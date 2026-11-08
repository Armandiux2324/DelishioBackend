<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
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
use App\Notifications\AdvanceOrderCancelled;
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
            $account = $restaurant->stripe_account_id;
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
                    || BigDecimal::of($restaurant->dual_pricing_percentage)->isGreaterThan(Restaurant::MAX_DUAL_PRICING_PERCENTAGE)) {
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
            $order->update($totals + ['surcharge_total' => (string) $adjustment]);
            AdvanceOrderPayment::create(['order_id' => $order->id, 'stripe_account_id' => $account, 'currency' => $restaurant->currency,
                'amount' => (string) $total, 'points_to_earn' => (string) $points, 'request_key' => $data['request_key'], 'request_hash' => $hash]);

            return $order->load(['items.menuItem', 'advancePayment']);
        });
    }

    public function payment(Order $order): array
    {
        abort_unless(Restaurant::whereKey($order->restaurant_id)->where('status', 'active')->exists(), 404);
        abort_unless(Restaurant::whereKey($order->restaurant_id)->whereNotNull('stripe_account_id')->exists(), 503, __('Stripe no está configurado.'));
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
            abort_if(! in_array($payment->status, ['pending', 'authorized'], true), 409);
            $gateway = app(StripeConnectGateway::class);
            if ($payment->payment_intent_id === null) {
                abort_if($payment->created_at->lt(now()->subHours(23)), 409, __('La solicitud de pago expiró. Crea un pedido nuevo.'));
                $intent = $gateway->createIntent($payment);
                $this->checkIntent($payment, $intent, false);
                $payment->update(['payment_intent_id' => $intent->id]);
            } else {
                $intent = $gateway->retrieveIntent($payment);
                $this->checkIntent($payment, $intent, false);
            }
            abort_if($intent->status === 'canceled', 409, __('La solicitud de pago expiró. Crea un pedido nuevo.'));
            abort_if($intent->capture_method !== 'manual', 409, __('Crea un pedido nuevo para autorizar el pago.'));

            return ['payment_intent_id' => $intent->id, 'client_secret' => $intent->client_secret,
                'stripe_account_id' => $payment->stripe_account_id, 'publishable_key' => config('services.stripe.publishable_key'),
                'currency' => $payment->currency, 'amount' => $payment->amount, 'status' => $payment->status, 'stripe_status' => $intent->status];
        });
    }

    public function handleEvent(AdvanceOrderPayment $known, PaymentIntent $intent): void
    {
        $this->checkIntent($known, $intent, $intent->status === 'succeeded');
        $this->reconcile($known);
    }

    public function reconcile(AdvanceOrderPayment $known): void
    {
        $payment = $this->freshPayment($known->id);
        try {
            if ($payment->payment_intent_id === null) {
                return;
            }
            if (in_array($payment->status, ['refund_pending', 'refund_failed'], true)) {
                $this->retryRefund($payment);

                return;
            }
            $order = Order::withoutGlobalScope('restaurant')->findOrFail($payment->order_id);
            if (in_array($payment->status, ['voided', 'refunded'], true)
                || ($payment->status === 'captured' && $order->closed_at !== null && $order->sent_at !== null)) {
                return;
            }
            $intent = app(StripeConnectGateway::class)->retrieveIntent($payment);
            $this->checkIntent($payment, $intent, $intent->status === 'succeeded');
            if ($intent->status === 'canceled') {
                DB::transaction(function () use ($payment) {
                    $order = Order::withoutGlobalScope('restaurant')->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
                    $current = $this->freshPayment($payment->id, true);
                    if ($order->closed_at === null && ! in_array($current->status, ['captured', 'refunded'], true)) {
                        $this->cancelLocked($order, $current, 'La autorización de pago ya no está disponible.');
                        $this->saveState($current, ['status' => 'voided']);
                    }
                });

                return;
            }
            // Legacy automatic intents may already be held by an old client. Never expose or reuse them for new charges.
            if ($order->status !== 'cancelled' && $intent->capture_method !== 'manual' && $intent->status !== 'succeeded') {
                DB::transaction(function () use ($payment) {
                    $order = Order::withoutGlobalScope('restaurant')->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
                    $this->cancelLocked($order, $this->freshPayment($payment->id, true), 'Crea un pedido nuevo para autorizar el pago.');
                });
                $order->status = 'cancelled';
            }
            if ($order->status === 'cancelled') {
                $this->compensate($payment);

                return;
            }
            if (! in_array($intent->status, ['requires_capture', 'succeeded'], true)) {
                return;
            }
            if ($intent->status === 'requires_capture') {
                DB::transaction(function () use ($payment) {
                    $current = $this->freshPayment($payment->id, true);
                    if (in_array($current->status, ['pending', 'authorized'], true)) {
                        $this->saveState($current, ['status' => 'authorized', 'authorized_at' => $current->authorized_at ?? now(), 'last_error' => null]);
                    }
                });
            }
            $this->complete($payment, $intent);
            $order = Order::withoutGlobalScope('restaurant')->findOrFail($payment->order_id);
            if ($order->status === 'cancelled') {
                $this->compensate($this->freshPayment($payment->id));
            }
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() >= 500) {
                $current = $this->freshPayment($payment->id);
                if (! in_array($current->status, ['captured', 'voided', 'refunded', 'refund_failed'], true)) {
                    $this->saveState($current, ['last_error' => __('No se pudo completar la operación con Stripe. Intenta nuevamente.')]);
                }
            }
            throw $exception;
        } finally {
            $this->notifyCancellation($payment->id);
        }
    }

    private function complete(AdvanceOrderPayment $known, PaymentIntent $intent): void
    {
        DB::transaction(function () use ($known, $intent) {
            $initial = Order::withoutGlobalScope('restaurant')->findOrFail($known->order_id);
            $cash = CashRegister::withoutGlobalScope('restaurant')->where('restaurant_id', $initial->restaurant_id)
                ->whereKey($initial->cash_register_id)->lockForUpdate()->first();
            $order = Order::withoutGlobalScope('restaurant')->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $payment = $this->freshPayment($known->id, true);
            if (($payment->status === 'captured' && $order->closed_at !== null) || $order->status === 'cancelled'
                || in_array($payment->status, ['voided', 'refunded', 'refund_pending', 'refund_failed'], true)) {
                return;
            }
            $restaurant = Restaurant::find($order->restaurant_id);
            if (! $cash || $cash->closed_at !== null || ! $restaurant || $restaurant->status !== 'active' || $restaurant->stripe_account_id === null || $order->status !== 'open') {
                $this->cancelLocked($order, $payment, 'El pedido ya no está disponible para confirmación.');

                return;
            }
            try {
                app(RestaurantContext::class)->run($restaurant, fn () => DB::transaction(function () use ($order, $payment, $intent) {
                    app(OrderConfirmationService::class)->prepareLocked($order);
                    $user = User::whereKey($order->customer_id)->where('user_type', 'customer')->where('active', true)->whereNotNull('email_verified_at')->lockForUpdate()->first();
                    abort_unless($user && Customer::whereKey($order->customer_id)->exists(), 409);
                    $balance = LoyaltyBalance::firstOrCreate(['customer_id' => $order->customer_id], ['points_balance' => '0.00']);
                    $balance = LoyaltyBalance::whereKey($balance->id)->lockForUpdate()->firstOrFail();
                    $next = BigDecimal::of($balance->points_balance)->plus($payment->points_to_earn);
                    if ($next->isGreaterThan('9999999999.99')) {
                        throw ValidationException::withMessages(['points' => __('El saldo de puntos excede el rango permitido.')]);
                    }
                    // All business checks and inventory locks precede capture. A lost capture response is recovered by reconciliation.
                    $captured = $intent->status === 'succeeded' ? $intent : app(StripeConnectGateway::class)->captureIntent($payment);
                    $this->checkIntent($payment, $captured, true);
                    app(OrderConfirmationService::class)->confirmLocked($order, true);
                    Payment::create(['order_id' => $order->id, 'method' => 'card',
                        'amount' => (string) BigDecimal::of($payment->amount)->minus($order->surcharge_total), 'surcharge_amount' => $order->surcharge_total]);
                    if (BigDecimal::of($payment->points_to_earn)->isPositive()) {
                        $balance->update(['points_balance' => (string) $next]);
                        LoyaltyTransaction::create(['customer_id' => $order->customer_id, 'order_id' => $order->id, 'type' => 'earn',
                            'points' => $payment->points_to_earn, 'base_amount' => (string) BigDecimal::of($order->subtotal)->minus($order->discount_total)->minus($order->loyalty_redeemed_amount)]);
                    }
                    $order->update(['status' => 'closed', 'closed_at' => now()]);
                    $payment->update(['status' => 'captured', 'captured_at' => now(), 'last_error' => null]);
                }));
            } catch (InsufficientStockException) {
                $this->cancelLocked($order, $payment, 'El pedido fue cancelado por falta de existencias.');
            } catch (ValidationException) {
                $this->cancelLocked($order, $payment, 'El pedido ya no está disponible para confirmación.');
            } catch (HttpException $exception) {
                if (! in_array($exception->getStatusCode(), [403, 404, 409, 422], true)) {
                    throw $exception;
                }
                $this->cancelLocked($order, $payment, 'El pedido ya no está disponible para confirmación.');
            }
        });
    }

    private function compensate(AdvanceOrderPayment $payment): void
    {
        $intent = app(StripeConnectGateway::class)->voidIntent($payment);
        $this->checkIntent($payment, $intent, $intent->status === 'succeeded');
        if ($intent->status === 'canceled') {
            $this->saveState($payment, ['status' => 'voided', 'last_error' => null]);

            return;
        }
        if ($intent->status === 'succeeded') {
            $this->saveState($payment, ['status' => 'refund_pending', 'captured_at' => $payment->captured_at ?? now()]);
            $this->retryRefund($this->freshPayment($payment->id));
        }
    }

    public function retryRefund(AdvanceOrderPayment $known): void
    {
        $failure = null;
        DB::transaction(function () use ($known, &$failure) {
            $payment = $this->freshPayment($known->id, true);
            if (! in_array($payment->status, ['refund_pending', 'refund_failed'], true)) {
                return;
            }
            $this->saveState($payment, ['refund_attempts' => $payment->refund_attempts + 1]);
            try {
                $refund = app(StripeConnectGateway::class)->refund($payment);
                $failed = in_array($refund->status, ['failed', 'canceled'], true);
                $data = ['refund_reference' => $refund->id,
                    'status' => $refund->status === 'succeeded' ? 'refunded' : ($failed ? 'refund_failed' : 'refund_pending'),
                    'last_error' => $failed ? __('Stripe no confirmó la devolución; se reintentará.') : null];
                if ($refund->status === 'succeeded') {
                    $data['refunded_at'] = now();
                }
                $this->saveState($payment, $data);
                if ($failed) {
                    Log::error('advance_order_refund_failed', ['payment_id' => $payment->id, 'refund_id' => $refund->id]);
                }
            } catch (\Throwable $exception) {
                $this->saveState($payment, ['status' => 'refund_failed', 'last_error' => __('No se pudo completar la operación con Stripe. Intenta nuevamente.')]);
                $failure = $exception;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function cancelLocked(Order $order, AdvanceOrderPayment $payment, string $reason): void
    {
        $cancelledAt = $payment->cancellation_requested_at ?? now();
        Order::withoutGlobalScope('restaurant')->whereKey($order->id)->update([
            'status' => 'cancelled',
            'cancelled_at' => $cancelledAt,
            'cancel_reason' => $order->cancel_reason ?? $reason,
        ]);
        $this->saveState($payment, ['cancellation_requested_at' => $cancelledAt, 'last_error' => __($reason)]);
        $event = ['order_id' => $order->id, 'payment_id' => $payment->id];
        DB::afterCommit(fn () => Log::warning('advance_order_rejected', $event));
    }

    private function notifyCancellation(int $id): void
    {
        try {
            DB::transaction(function () use ($id) {
                $payment = $this->freshPayment($id, true);
                if ($payment->cancellation_requested_at === null || $payment->cancellation_notified_at !== null) {
                    return;
                }
                $order = Order::withoutGlobalScope('restaurant')->findOrFail($payment->order_id);
                $user = User::withTrashed()->findOrFail($order->customer_id);
                $user->notify((new AdvanceOrderCancelled($order->id))->locale($user->preferredLocale()));
                $this->saveState($payment, ['cancellation_notified_at' => now()]);
            });
        } catch (\Throwable $exception) {
            Log::warning('advance_order_cancellation_notification_failed', ['payment_id' => $id, 'exception' => $exception::class]);
        }
    }

    private function freshPayment(int $id, bool $lock = false): AdvanceOrderPayment
    {
        $query = AdvanceOrderPayment::withoutGlobalScope('restaurant')->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function saveState(AdvanceOrderPayment $payment, array $data): void
    {
        // Reconciliation has no user session; the row identity comes only from stored Stripe references or the scheduler.
        AdvanceOrderPayment::withoutGlobalScope('restaurant')->whereKey($payment->id)->update($data);
    }

    private function checkIntent(AdvanceOrderPayment $payment, PaymentIntent $intent, bool $paid): void
    {
        abort_unless(is_string($intent->id) && str_starts_with($intent->id, 'pi_')
            && ($payment->payment_intent_id === null || hash_equals($payment->payment_intent_id, $intent->id))
            && $intent->amount === app(StripeConnectGateway::class)->minorUnits($payment->amount)
            && $intent->currency === strtolower($payment->currency)
            && ($intent->metadata->delishio_order_id ?? null) === (string) $payment->order_id
            && (! $paid || ($intent->status === 'succeeded' && $intent->amount_received === $intent->amount))
            && ($intent->status !== 'requires_capture' || ($intent->capture_method === 'manual' && $intent->amount_capturable === $intent->amount)),
            400, __('Los datos del pago no coinciden con el pedido.'));
    }
}
