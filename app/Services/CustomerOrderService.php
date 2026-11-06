<?php

namespace App\Services;

use App\Models\AdvanceOrderPayment;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\LoyaltyBalance;
use App\Models\LoyaltyTransaction;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\PaymentIntent;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerOrderService
{
    public function create(Restaurant $restaurant, array $data): Order
    {
        $cart = array_map(fn ($line) => ['menu_item_id' => (int) $line['menu_item_id'], 'quantity' => (int) $line['quantity'],
            'modifiers' => $line['modifiers'] ?? null, 'kitchen_notes' => $line['kitchen_notes'] ?? null], $data['items']);
        $hash = hash('sha256', json_encode($cart, JSON_THROW_ON_ERROR));
        $customerId = Auth::guard('sanctum')->id();

        return DB::transaction(function () use ($restaurant, $data, $cart, $hash, $customerId) {
            $restaurant = Restaurant::whereKey($restaurant->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $existing = AdvanceOrderPayment::withoutGlobalScope('restaurant')->where('request_key', $data['request_key'])->first();
            if ($existing !== null) {
                $own = Order::whereKey($existing->order_id)->where('customer_id', $customerId)->exists();
                abort_unless($own && hash_equals($existing->request_hash, $hash), 409, __('La clave de idempotencia ya se usó con otra solicitud.'));

                return Order::findOrFail($existing->order_id)->load(['items.menuItem', 'advancePayment']);
            }
            $account = config('services.stripe.accounts.'.$restaurant->id);
            abort_unless(is_string($account) && preg_match('/\\Aacct_[a-zA-Z0-9]+\\z/', $account)
                && filled(config('services.stripe.secret')) && filled(config('services.stripe.publishable_key')),
                503, __('Stripe no está configurado.'));
            $cash = CashRegister::whereNull('closed_at')->orderBy('id')->lockForUpdate()->first();
            abort_unless($cash, 409, __('Se requiere una caja abierta.'));
            $user = User::whereKey($customerId)->where('user_type', 'customer')->where('active', true)->whereNotNull('email_verified_at')->lockForUpdate()->first();
            abort_unless($user && Customer::whereKey($customerId)->exists(), 403);
            $menus = MenuItem::whereIn('id', array_column($cart, 'menu_item_id'))->where('active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($menus->count() !== count(array_unique(array_column($cart, 'menu_item_id')))) {
                throw ValidationException::withMessages(['items' => __('Hay elementos de menú que ya no están disponibles.')]);
            }
            $taxes = app(OrderConfirmationService::class)->lockTaxRates($menus);
            $order = Order::create(['customer_id' => $customerId, 'source' => 'customer_app', 'service_type' => 'takeout', 'cash_register_id' => $cash->id]);
            foreach ($cart as $line) {
                $menu = $menus[$line['menu_item_id']];
                $order->items()->create($line + ['unit_price' => $menu->sale_price, 'station' => $menu->preparation_station]);
            }
            $totals = app(OrderTotals::class)->calculate($order, rates: $taxes);
            app(OrderConfirmationService::class)->prepareLocked($order);
            $adjustment = BigDecimal::of('0.00');
            if ($restaurant->dual_pricing_enabled) {
                if ($restaurant->dual_pricing_percentage === null || BigDecimal::of($restaurant->dual_pricing_percentage)->isNegative()
                    || BigDecimal::of($restaurant->dual_pricing_percentage)->isGreaterThan(100)) {
                    throw ValidationException::withMessages(['items' => __('Configura un porcentaje válido de precios duales.')]);
                }
                $adjustment = BigDecimal::of($totals['total'])->multipliedBy($restaurant->dual_pricing_percentage)->dividedBy(100, 2, RoundingMode::HalfUp);
            }
            $total = BigDecimal::of($totals['total'])->plus($adjustment);
            $minor = app(StripeConnectGateway::class)->minorUnits((string) $total);
            if ($minor < 1 || $minor > 99999999) {
                throw ValidationException::withMessages(['items' => __('El total está fuera del rango permitido para un pago con tarjeta.')]);
            }
            $points = BigDecimal::of('0.00');
            if ($restaurant->loyalty_enabled) {
                if ($restaurant->loyalty_factor === null || BigDecimal::of($restaurant->loyalty_factor)->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages(['items' => __('Configura el factor de puntos de lealtad.')]);
                }
                $points = BigDecimal::of($totals['subtotal'])->minus($totals['discount_total'])->multipliedBy($restaurant->loyalty_factor)
                    ->toScale(0, RoundingMode::Down)->toScale(2);
                if ($points->isGreaterThan('9999999999.99')) {
                    throw ValidationException::withMessages(['items' => __('El saldo de puntos excede el rango permitido.')]);
                }
            }
            $totals['total'] = (string) $total;
            $order->update($totals + ['dual_pricing_applied' => $adjustment->isPositive()]);
            AdvanceOrderPayment::create(['order_id' => $order->id, 'stripe_account_id' => $account, 'currency' => $restaurant->currency,
                'amount' => (string) $total, 'points_to_earn' => (string) $points, 'request_key' => $data['request_key'], 'request_hash' => $hash]);

            return $order->load(['items.menuItem', 'advancePayment']);
        });
    }

    public function payment(Order $order): array
    {
        abort_unless(Restaurant::whereKey($order->restaurant_id)->where('status', 'active')->exists(), 404);
        $payment = DB::transaction(function () use ($order) {
            $cash = CashRegister::whereKey($order->cash_register_id)->lockForUpdate()->firstOrFail();
            abort_if($cash->closed_at !== null, 409, __('La caja ya está cerrada.'));
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === 'open' && $order->sent_at === null, 409, __('La orden ya no permite ajustes.'));
            app(OrderConfirmationService::class)->prepareLocked($order);

            return $order->advancePayment()->firstOrFail();
        });

        return DB::transaction(function () use ($payment) {
            $payment = AdvanceOrderPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if($payment->payment_confirmed || $payment->refund_pending || $payment->refunded_at !== null, 409);
            $gateway = app(StripeConnectGateway::class);
            if ($payment->payment_reference === null) {
                abort_if($payment->created_at->lt(now()->subHours(23)), 409, __('La solicitud de pago expiró. Crea un pedido nuevo.'));
                $intent = $gateway->createIntent($payment);
                $this->checkIntent($payment, $intent, false);
                $payment->update(['payment_reference' => $intent->id]);
            } else {
                $intent = $gateway->retrieveIntent($payment);
                $this->checkIntent($payment, $intent, false);
            }
            abort_if($intent->status === 'canceled', 409, __('La solicitud de pago expiró. Crea un pedido nuevo.'));

            return ['payment_intent_id' => $intent->id, 'client_secret' => $intent->client_secret,
                'stripe_account_id' => $payment->stripe_account_id, 'publishable_key' => config('services.stripe.publishable_key'),
                'currency' => $payment->currency, 'amount' => $payment->amount, 'status' => $intent->status];
        });
    }

    public function acceptPayment(AdvanceOrderPayment $known, PaymentIntent $intent): void
    {
        $this->checkIntent($known, $intent, true);
        DB::transaction(function () use ($known) {
            // These trusted IDs come from the stored Stripe reference, never from customer-supplied tenant IDs.
            $initial = Order::withoutGlobalScope('restaurant')->findOrFail($known->order_id);
            $cash = CashRegister::withoutGlobalScope('restaurant')->where('restaurant_id', $initial->restaurant_id)
                ->whereKey($initial->cash_register_id)->lockForUpdate()->first();
            $order = Order::withoutGlobalScope('restaurant')->where('restaurant_id', $initial->restaurant_id)->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $payment = AdvanceOrderPayment::withoutGlobalScope('restaurant')->whereKey($known->id)->lockForUpdate()->firstOrFail();
            if ($payment->payment_confirmed || $payment->refund_pending || $payment->refunded_at !== null) {
                return;
            }
            $restaurant = Restaurant::find($order->restaurant_id);
            if (! $cash || $cash->closed_at !== null || ! $restaurant || $restaurant->status !== 'active' || $order->status !== 'open') {
                $this->markRefund($order, $payment);

                return;
            }
            try {
                app(RestaurantContext::class)->run($restaurant, fn () => DB::transaction(function () use ($order, $payment) {
                    app(OrderConfirmationService::class)->confirmLocked($order, true);
                    $user = User::whereKey($order->customer_id)->where('user_type', 'customer')->where('active', true)->whereNotNull('email_verified_at')->lockForUpdate()->first();
                    abort_unless($user && Customer::whereKey($order->customer_id)->exists(), 409);
                    $balance = LoyaltyBalance::firstOrCreate(['customer_id' => $order->customer_id], ['points_balance' => '0.00']);
                    $balance = LoyaltyBalance::whereKey($balance->id)->lockForUpdate()->firstOrFail();
                    $next = BigDecimal::of($balance->points_balance)->plus($payment->points_to_earn);
                    if ($next->isGreaterThan('9999999999.99')) {
                        throw ValidationException::withMessages(['points' => __('El saldo de puntos excede el rango permitido.')]);
                    }
                    Payment::create(['order_id' => $order->id, 'method' => 'card', 'amount' => $payment->amount]);
                    if (BigDecimal::of($payment->points_to_earn)->isPositive()) {
                        $balance->update(['points_balance' => (string) $next]);
                        LoyaltyTransaction::create(['customer_id' => $order->customer_id, 'order_id' => $order->id, 'type' => 'earn', 'points' => $payment->points_to_earn]);
                    }
                    $order->update(['status' => 'closed', 'closed_at' => now()]);
                    $payment->update(['payment_confirmed' => true, 'paid_at' => now()]);
                }));
            } catch (ValidationException $exception) {
                $this->markRefund($order, $payment);
            } catch (HttpException $exception) {
                if (! in_array($exception->getStatusCode(), [403, 404, 409, 422], true)) {
                    throw $exception;
                }
                $this->markRefund($order, $payment);
            }
        });
        $payment = AdvanceOrderPayment::withoutGlobalScope('restaurant')->findOrFail($known->id);
        if ($payment->refund_pending) {
            $this->retryRefund($payment);
        }
    }

    public function cancelPayment(AdvanceOrderPayment $known, PaymentIntent $intent): void
    {
        $this->checkIntent($known, $intent, false);
        abort_unless($intent->status === 'canceled', 400);
        DB::transaction(function () use ($known) {
            $order = Order::withoutGlobalScope('restaurant')->whereKey($known->order_id)->lockForUpdate()->firstOrFail();
            $payment = AdvanceOrderPayment::withoutGlobalScope('restaurant')->whereKey($known->id)->lockForUpdate()->firstOrFail();
            if (! $payment->payment_confirmed && ! $payment->refund_pending && $order->status === 'open' && $order->sent_at === null) {
                Order::withoutGlobalScope('restaurant')->whereKey($order->id)->update(['status' => 'cancelled']);
            }
        });
    }

    public function retryRefund(AdvanceOrderPayment $known): void
    {
        DB::transaction(function () use ($known) {
            $payment = AdvanceOrderPayment::withoutGlobalScope('restaurant')->whereKey($known->id)->lockForUpdate()->firstOrFail();
            if (! $payment->refund_pending || $payment->refunded_at !== null) {
                return;
            }
            $refund = app(StripeConnectGateway::class)->refund($payment);
            $data = ['refund_reference' => $refund->id];
            if ($refund->status === 'succeeded') {
                $data += ['refund_pending' => false, 'refunded_at' => now()];
            } elseif (in_array($refund->status, ['failed', 'canceled'], true)) {
                Log::error('advance_order_refund_failed', ['payment_id' => $payment->id, 'refund_id' => $refund->id]);
            }
            AdvanceOrderPayment::withoutGlobalScope('restaurant')->whereKey($payment->id)->update($data);
        });
    }

    private function markRefund(Order $order, AdvanceOrderPayment $payment): void
    {
        // Commit the rejection before any refund HTTP call, so a retry can never fulfill a refunded order.
        Order::withoutGlobalScope('restaurant')->where('restaurant_id', $order->restaurant_id)->whereKey($order->id)->update(['status' => 'cancelled']);
        AdvanceOrderPayment::withoutGlobalScope('restaurant')->whereKey($payment->id)->update(['refund_pending' => true, 'paid_at' => now()]);
        $event = ['order_id' => $order->id, 'payment_id' => $payment->id];
        DB::afterCommit(fn () => Log::warning('advance_order_rejected', $event));
    }

    private function checkIntent(AdvanceOrderPayment $payment, PaymentIntent $intent, bool $paid): void
    {
        abort_unless(is_string($intent->id) && str_starts_with($intent->id, 'pi_')
            && ($payment->payment_reference === null || hash_equals($payment->payment_reference, $intent->id))
            && $intent->amount === app(StripeConnectGateway::class)->minorUnits($payment->amount)
            && $intent->currency === strtolower($payment->currency)
            && ($intent->metadata->delishio_order_id ?? null) === (string) $payment->order_id
            && (! $paid || ($intent->status === 'succeeded' && $intent->amount_received === $intent->amount)),
            400, __('Los datos del pago no coinciden con el pedido.'));
    }
}
