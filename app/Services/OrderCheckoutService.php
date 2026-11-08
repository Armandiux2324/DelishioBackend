<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\LoyaltyBalance;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderCheckoutService
{
    public function linkCustomer(Order $order, string $qr): Order
    {
        $this->checkOwner($order);

        return DB::transaction(function () use ($order, $qr) {
            $order = $this->lockOrder($order);
            abort_unless(in_array($order->status, ['open', 'sent'], true) && $order->closed_at === null, 409, __('La orden ya no permite ajustes.'));
            $customer = Customer::where('qr_code', $qr)->first();
            if (! $customer) {
                throw ValidationException::withMessages(['qr_code' => __('El cliente no está disponible.')]);
            }
            $this->lockCustomer($customer->user_id);
            // The customer is global; affiliation and balances belong only to the current restaurant.
            LoyaltyBalance::firstOrCreate(['customer_id' => $customer->user_id], ['points_balance' => '0.00']);
            $order->update(['customer_id' => $customer->user_id]);

            return $this->result($order);
        });
    }

    public function preview(Order $order, array $data): array
    {
        $this->checkOwner($order);

        return DB::transaction(function () use ($order, $data) {
            $order = $this->lockOrder($order);
            $this->checkSent($order);
            $balance = $order->customer_id === null ? null : $this->lockCustomer($order->customer_id);

            return $this->calculate($order, $data, $balance)['quote'];
        });
    }

    public function close(Order $order, array $data): Order
    {
        $this->checkOwner($order);

        return DB::transaction(function () use ($order, $data) {
            $order = $this->lockOrder($order);
            $this->checkSent($order);
            abort_if($order->payments()->exists() || $order->loyaltyTransactions()->exists(), 409, __('La orden ya tiene un cobro registrado.'));
            $balance = $order->customer_id === null ? null : $this->lockCustomer($order->customer_id);
            $calculation = $this->calculate($order, $data, $balance);
            $quote = $calculation['quote'];
            if ($quote['payment_difference'] !== '0.00') {
                throw ValidationException::withMessages(['payments' => __('Los importes base de los pagos deben sumar exactamente :total.', ['total' => $quote['payment_base_total']])]);
            }
            foreach ($calculation['payments'] as $payment) {
                Payment::create(['order_id' => $order->id, ...$payment]);
            }
            if ($order->customer_id !== null && ($calculation['redeem']->isPositive() || $calculation['earn']->isPositive())) {
                $balance ??= LoyaltyBalance::create(['customer_id' => $order->customer_id, 'points_balance' => '0.00']);
                $balance->update(['points_balance' => (string) $calculation['balance']]);
                foreach (['redeem', 'earn'] as $type) {
                    if ($calculation[$type]->isPositive()) {
                        LoyaltyTransaction::create(['customer_id' => $order->customer_id, 'order_id' => $order->id,
                            'type' => $type, 'points' => (string) $calculation[$type], 'base_amount' => $type === 'earn' ? $calculation['base_amount'] : null]);
                    }
                }
            }
            $order->update(['subtotal' => $quote['subtotal'], 'tax_total' => $quote['tax_total'],
                'discount_total' => $quote['discount_total'], 'loyalty_redeemed_amount' => $quote['loyalty_redeemed_amount'], 'surcharge_total' => $quote['surcharge_total'],
                'tip_amount' => $quote['tip_amount'], 'total' => $quote['total'], 'status' => 'closed', 'closed_at' => now()]);

            return $this->result($order);
        });
    }

    private function calculate(Order $order, array $data, ?LoyaltyBalance $balance): array
    {
        $restaurant = Restaurant::findOrFail($order->restaurant_id);
        $redeem = BigDecimal::of((string) ($data['redeem_points'] ?? '0'))->toScale(2);
        $tip = BigDecimal::of((string) ($data['tip_amount'] ?? '0'))->toScale(2);
        $totals = app(OrderTotals::class)->calculate($order);
        $base = BigDecimal::of($totals['subtotal'])->minus($totals['discount_total']);
        $redemption = $earn = BigDecimal::of('0.00');
        $available = BigDecimal::of($balance?->points_balance ?? '0.00');
        if ($redeem->isPositive()) {
            if (! $restaurant->loyalty_enabled || $order->customer_id === null || $restaurant->loyalty_redeem_value === null
                || BigDecimal::of($restaurant->loyalty_redeem_value)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['redeem_points' => __('El canje requiere un cliente vinculado y puntos habilitados y configurados.')]);
            }
            if ($redeem->isGreaterThan($available)) {
                throw ValidationException::withMessages(['redeem_points' => __('El saldo de puntos es insuficiente.')]);
            }
            $redemption = $redeem->dividedBy($restaurant->loyalty_redeem_value, 2, RoundingMode::Down);
            if ($redemption->isZero() || $redemption->isGreaterThan($base)) {
                throw ValidationException::withMessages(['redeem_points' => __('El canje debe cubrir al menos un centavo y no superar el consumo neto.')]);
            }
        }
        if ($redemption->isPositive()) {
            $totals = app(OrderTotals::class)->calculate($order, redemptionAmount: (string) $redemption);
        }
        $bill = BigDecimal::of($totals['total']);
        $paid = $nonCard = BigDecimal::of('0.00');
        foreach ($data['payments'] as $payment) {
            $amount = BigDecimal::of((string) $payment['amount']);
            $paid = $paid->plus($amount);
            if ($payment['method'] !== 'card') {
                $nonCard = $nonCard->plus($amount);
            }
        }
        $adjustment = BigDecimal::of('0.00');
        $remainder = $bill->isGreaterThan($nonCard) ? $bill->minus($nonCard) : BigDecimal::of('0.00');
        if ($restaurant->dual_pricing_enabled && $remainder->isPositive()) {
            $percentage = $restaurant->dual_pricing_percentage;
            if ($percentage === null || BigDecimal::of($percentage)->isNegative() || BigDecimal::of($percentage)->isGreaterThan(Restaurant::MAX_DUAL_PRICING_PERCENTAGE)) {
                throw ValidationException::withMessages(['payments' => __('Configura un porcentaje válido de precios duales.')]);
            }
            // Cash and transfers cover the bill first; only the remaining bill receives a card adjustment.
            $adjustment = $remainder->multipliedBy($percentage)->dividedBy(100, 2, RoundingMode::HalfUp);
        }
        $consumption = $base->minus($redemption);
        $total = $bill->plus($tip)->plus($adjustment);
        $payments = array_map(fn ($payment) => ['method' => $payment['method'], 'amount' => $payment['amount'],
            'surcharge_amount' => $payment['method'] === 'card' ? (string) $adjustment : '0.00'], $data['payments']);
        $hasCard = collect($payments)->contains('method', 'card');
        $charged = $hasCard ? $paid->plus($adjustment) : $paid;
        $cardBase = $bill->plus($tip)->isGreaterThan($nonCard) ? $bill->plus($tip)->minus($nonCard) : BigDecimal::of('0.00');
        if ($restaurant->loyalty_enabled && $order->customer_id !== null) {
            if ($restaurant->loyalty_factor === null || BigDecimal::of($restaurant->loyalty_factor)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['payments' => __('Configura el factor de puntos de lealtad.')]);
            }
            $earn = $consumption->multipliedBy($restaurant->loyalty_factor)->toScale(0, RoundingMode::Down)->toScale(2);
        }
        $newBalance = $available->minus($redeem)->plus($earn);
        if ($earn->isGreaterThan('9999999999.99') || $newBalance->isNegative() || $newBalance->isGreaterThan('9999999999.99')) {
            throw ValidationException::withMessages(['redeem_points' => __('El saldo de puntos excede el rango permitido.')]);
        }
        $discount = BigDecimal::of($totals['discount_total']);
        foreach ([$paid, $total, $discount] as $amount) {
            if ($amount->isNegative() || $amount->isGreaterThan('99999999.99')) {
                throw ValidationException::withMessages(['payments' => __('Los importes de la orden exceden el rango permitido.')]);
            }
        }

        return ['redeem' => $redeem, 'earn' => $earn, 'balance' => $newBalance, 'base_amount' => (string) $consumption, 'payments' => $payments, 'quote' => [
            'currency' => $restaurant->currency, 'subtotal' => $totals['subtotal'], 'tax_total' => $totals['tax_total'],
            'discount_total' => (string) $discount, 'loyalty_redeemed_amount' => (string) $redemption,
            'tip_amount' => (string) $tip, 'surcharge_total' => (string) $adjustment, 'total' => (string) $total,
            'card_amount' => (string) $cardBase, 'card_charge_total' => (string) $cardBase->plus($adjustment),
            'payment_base_total' => (string) $bill->plus($tip), 'payment_total' => (string) $charged, 'payment_difference' => (string) $charged->minus($total),
            'points_redeemed' => (string) $redeem, 'points_earned' => (string) $earn, 'points_balance' => (string) $newBalance,
        ]];
    }

    private function lockOrder(Order $order): Order
    {
        $cash = CashRegister::whereKey($order->cash_register_id)->lockForUpdate()->firstOrFail();
        abort_if($cash->closed_at !== null, 409, __('La caja ya está cerrada.'));
        abort_unless(Employee::active()->whereKey(Auth::guard('sanctum')->id())->where('role', 'waiter')->lockForUpdate()->first(), 403);
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->checkOwner($order);

        return $order;
    }

    private function checkOwner(Order $order): void
    {
        abort_unless($order->source === 'pos' && $order->waiter_employee_id === Auth::guard('sanctum')->id(), 404);
    }

    private function checkSent(Order $order): void
    {
        abort_unless($order->status === 'sent' && $order->sent_at !== null && $order->closed_at === null,
            409, __('Solo se puede cobrar una orden confirmada y sin cerrar.'));
    }

    private function lockCustomer(int $id): ?LoyaltyBalance
    {
        $user = User::whereKey($id)->where('user_type', 'customer')->where('active', true)->whereNotNull('email_verified_at')->lockForUpdate()->first();
        $customer = Customer::whereKey($id)->lockForUpdate()->first();
        if (! $user || ! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('El cliente no está disponible.')]);
        }

        // Locking the shared user also serializes first balance creation across separate cash registers.
        return LoyaltyBalance::where('customer_id', $id)->lockForUpdate()->first();
    }

    private function result(Order $order): Order
    {
        return $order->load(['items.menuItem', 'discounts', 'taxExemptions', 'payments', 'loyaltyTransactions']);
    }
}
