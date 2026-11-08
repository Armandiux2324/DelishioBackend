<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\TaxExemption;
use App\Models\WorkShift;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderAdjustmentService
{
    public function discount(Order $order, array $data, bool $courtesy = false): Order
    {
        $this->checkOrder($order);
        if (! $courtesy && isset($data['order_item_id'])) {
            $this->checkItem($order, $data['order_item_id']);
        }
        // Keep rate-limiter writes outside the business transaction, including database cache.
        $admin = app(AdminPinAuthorization::class)->authorize($data['admin_user_id'], $data['pin'], $courtesy ? 'order.courtesy' : 'order.discount');

        return DB::transaction(function () use ($order, $data, $courtesy, $admin) {
            $order = $this->lockOrder($order);
            $this->checkAdmin($admin);
            $shift = WorkShift::where('employee_id', $order->waiter_employee_id)->whereNull('clock_out')->orderByDesc('id')->lockForUpdate()->first();
            abort_unless($shift, 409, __('Se requiere un turno abierto para aplicar descuentos o cortesías.'));
            $restaurant = Restaurant::findOrFail($order->restaurant_id);
            $lines = app(OrderTotals::class)->basis($order)['lines'];
            $target = ! $courtesy && isset($data['order_item_id']) ? (int) $data['order_item_id'] : null;
            if ($target !== null) {
                $this->checkItem($order, $target);
            }
            $base = BigDecimal::of('0.00');
            foreach ($lines as $id => $line) {
                if ($target === null || $id === $target) {
                    $base = $base->plus($line);
                }
            }
            $percentage = $courtesy ? $restaurant->courtesy_percentage : ($data['percentage'] ?? null);
            if ($courtesy && ($percentage === null || BigDecimal::of($percentage)->isLessThanOrEqualTo(0) || $restaurant->courtesy_daily_limit === null)) {
                throw ValidationException::withMessages(['percentage' => __('Configura el porcentaje y el límite diario de cortesías.')]);
            }
            $amount = $percentage !== null
                ? $base->multipliedBy((string) $percentage)->dividedBy(100, 2, RoundingMode::HalfUp)
                : BigDecimal::of((string) $data['amount']);
            if ($amount->isLessThanOrEqualTo(0) || $amount->isGreaterThan($base)) {
                throw ValidationException::withMessages(['amount' => __('El descuento debe ser positivo y no superar el subtotal disponible.')]);
            }
            if ($courtesy) {
                $day = now()->startOfDay();
                $used = OrderDiscount::where('requested_by_employee_id', $order->waiter_employee_id)->where('kind', 'courtesy')
                    ->where('created_at', '>=', $day)->where('created_at', '<', $day->copy()->addDay())->lockForUpdate()->get()->count();
                if ($used >= $restaurant->courtesy_daily_limit) {
                    throw ValidationException::withMessages(['kind' => __('Se alcanzó el límite diario de cortesías.')]);
                }
            } else {
                if ($restaurant->discount_limit_per_shift === null) {
                    throw ValidationException::withMessages(['amount' => __('Configura el límite de descuento por turno.')]);
                }
                $spent = BigDecimal::of('0.00');
                foreach (OrderDiscount::where('work_shift_id', $shift->id)->where('kind', 'discount')->lockForUpdate()->get() as $record) {
                    $spent = $spent->plus($record->amount);
                }
                if ($spent->plus($amount)->isGreaterThan($restaurant->discount_limit_per_shift)) {
                    throw ValidationException::withMessages(['amount' => __('Se excede el límite de descuento disponible en el turno.')]);
                }
            }
            OrderDiscount::create([
                'order_id' => $order->id, 'order_item_id' => $target, 'kind' => $courtesy ? 'courtesy' : 'discount',
                'percentage' => $percentage, 'amount' => (string) $amount, 'requested_by_employee_id' => $order->waiter_employee_id,
                'authorized_by_employee_id' => $admin->user_id, 'work_shift_id' => $shift->id,
            ]);
            $order->update(app(OrderTotals::class)->calculate($order));

            return $this->result($order);
        });
    }

    public function exempt(Order $order, array $data): Order
    {
        $this->checkOrder($order);

        return DB::transaction(function () use ($order, $data) {
            $order = $this->lockOrder($order);
            $target = isset($data['order_item_id']) ? (int) $data['order_item_id'] : null;
            if ($target !== null) {
                $this->checkItem($order, $target);
            }
            $existing = $order->taxExemptions()->get();
            abort_if($existing->contains(fn ($record) => $record->order_item_id === null || $record->order_item_id === $target),
                409, __('El alcance seleccionado ya está exento de impuesto.'));
            TaxExemption::create(['order_id' => $order->id, 'order_item_id' => $target, 'reason' => $data['reason'],
                'requested_by_employee_id' => $order->waiter_employee_id]);
            $order->update(app(OrderTotals::class)->calculate($order));

            return $this->result($order);
        });
    }

    public function cancel(Order $order, array $data, ?OrderItem $item = null): Order
    {
        $this->checkOrder($order);
        if ($item !== null) {
            $this->checkItem($order, $item->id);
        }
        $admin = null;
        if (isset($data['admin_user_id'], $data['pin'])) {
            $admin = app(AdminPinAuthorization::class)->authorize($data['admin_user_id'], $data['pin'], $item ? 'order.item.cancel' : 'order.cancel');
        }

        return DB::transaction(function () use ($order, $item, $data, $admin) {
            $order = $this->lockOrder($order);
            if (($order->status === 'sent' || $order->sent_at !== null) && $admin === null) {
                throw ValidationException::withMessages(['pin' => __('La cancelación de una orden enviada requiere PIN de administrador.')]);
            }
            if ($admin !== null) {
                $this->checkAdmin($admin);
            }
            $items = $order->items()->where('cancelled', false)->orderBy('id')->lockForUpdate()->get();
            if ($item !== null) {
                abort_unless($items->contains('id', $item->id), 409, __('El elemento ya está cancelado.'));
            }
            $cancelledAt = now();
            foreach ($items as $line) {
                if ($item === null || $line->id === $item->id) {
                    $line->update(['cancelled' => true, 'cancelled_at' => $cancelledAt, 'cancel_reason' => $data['reason'], 'cancelled_by_employee_id' => $order->waiter_employee_id,
                        'cancel_authorized_by_employee_id' => $admin?->user_id]);
                }
            }
            if ($item === null || ! $order->items()->where('cancelled', false)->exists()) {
                $order->status = 'cancelled';
                $order->cancelled_at = $cancelledAt;
                $order->cancel_reason = $data['reason'];
                $order->cancel_authorized_by_employee_id = $admin?->user_id;
            }
            // Confirmed cancellations preserve consumed inventory; prepared ingredients are not automatically restocked.
            $order->update(app(OrderTotals::class)->calculate($order));
            $event = ['order_id' => $order->id, 'order_item_id' => $item?->id, 'reason' => $data['reason'],
                'requested_by_employee_id' => $order->waiter_employee_id, 'authorized_by_employee_id' => $admin?->user_id, 'cancelled_at' => $cancelledAt->toIso8601String()];
            DB::afterCommit(fn () => Log::info('order_cancelled', $event));

            return $this->result($order);
        });
    }

    private function checkOrder(Order $order): void
    {
        abort_unless($order->waiter_employee_id === Auth::guard('sanctum')->id(), 404);
        abort_if(! in_array($order->status, ['open', 'sent'], true) || $order->closed_at !== null, 409, __('La orden ya no permite ajustes.'));
    }

    private function lockOrder(Order $order): Order
    {
        $cash = CashRegister::whereKey($order->cash_register_id)->lockForUpdate()->firstOrFail();
        abort_if($cash->closed_at !== null, 409, __('La caja ya está cerrada.'));
        abort_unless(Employee::active()->whereKey(Auth::guard('sanctum')->id())->where('role', 'waiter')->lockForUpdate()->first(), 403);
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->checkOrder($order);

        return $order;
    }

    private function checkItem(Order $order, int $id): OrderItem
    {
        $item = $order->items()->whereKey($id)->firstOrFail();
        abort_if($item->cancelled, 409, __('El elemento ya está cancelado.'));

        return $item;
    }

    private function checkAdmin(Employee $approved): void
    {
        $current = Employee::active()->whereKey($approved->user_id)->where('role', 'admin')->first();
        if (! $current || ! hash_equals($approved->pin, $current->pin)) {
            throw ValidationException::withMessages(['pin' => __('PIN de administrador inválido.')]);
        }
    }

    private function result(Order $order): Order
    {
        return $order->load(['items.menuItem', 'discounts', 'taxExemptions']);
    }
}
