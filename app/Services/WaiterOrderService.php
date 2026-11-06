<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WaiterOrderService
{
    public function create(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $waiter = $this->waiter();
            $cash = CashRegister::whereNull('closed_at')->orderBy('id')->lockForUpdate()->first();
            abort_unless($cash, 409, __('Se requiere una caja abierta.'));
            $order = Order::create([...$data, 'waiter_employee_id' => $waiter->user_id, 'source' => 'pos', 'cash_register_id' => $cash->id]);
            $this->draftTotals($order);

            return $order->load(['items.menuItem', 'discounts', 'taxExemptions']);
        });
    }

    public function addItem(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $order = $this->lockDraft($order);
            $menu = MenuItem::whereKey($data['menu_item_id'])->where('active', true)->lockForUpdate()->first();
            if (! $menu) {
                throw ValidationException::withMessages(['menu_item_id' => __('El elemento de menú seleccionado no está disponible.')]);
            }
            $order->items()->create([...$data, 'unit_price' => $menu->sale_price, 'station' => $menu->preparation_station]);
            $this->draftTotals($order);

            return $order->load(['items.menuItem', 'discounts', 'taxExemptions']);
        });
    }

    public function updateItem(Order $order, OrderItem $item, array $data): Order
    {
        return DB::transaction(function () use ($order, $item, $data) {
            $order = $this->lockDraft($order);
            $item = $order->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($item->cancelled, 409, __('La orden ya no permite editar elementos libremente.'));
            $item->update($data);
            $this->draftTotals($order);

            return $order->load(['items.menuItem', 'discounts', 'taxExemptions']);
        });
    }

    public function removeItem(Order $order, OrderItem $item): void
    {
        DB::transaction(function () use ($order, $item) {
            $order = $this->lockDraft($order);
            $item = $order->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->discounts()->exists() || $item->taxExemptions()->exists()) {
                abort_if($item->cancelled, 409, __('El elemento ya está cancelado.'));
                $reason = __('Elemento eliminado antes de confirmar.');
                $item->update(['cancelled' => true, 'cancel_reason' => $reason, 'cancelled_by_employee_id' => $order->waiter_employee_id]);
                $event = ['order_id' => $order->id, 'order_item_id' => $item->id, 'reason' => $reason,
                    'requested_by_employee_id' => $order->waiter_employee_id, 'authorized_by_employee_id' => null, 'cancelled_at' => now()->toIso8601String()];
                DB::afterCommit(fn () => Log::info('order_cancelled', $event));
            } else {
                $item->delete();
            }
            $this->draftTotals($order);
        });
    }

    public function confirm(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lockDraft($order);
            app(OrderConfirmationService::class)->confirmLocked($order);

            return $order->load(['items.menuItem', 'discounts', 'taxExemptions']);
        });
    }

    private function waiter(): Employee
    {
        $waiter = Employee::active()->whereKey(Auth::guard('sanctum')->id())->where('role', 'waiter')->first();
        abort_unless($waiter, 403);

        return $waiter;
    }

    private function lockDraft(Order $order): Order
    {
        $waiter = $this->waiter();
        abort_unless($order->waiter_employee_id === $waiter->user_id, 404);
        $cash = CashRegister::whereKey($order->cash_register_id)->lockForUpdate()->firstOrFail();
        abort_if($cash->closed_at !== null, 409, __('La caja ya está cerrada.'));
        $order = Order::whereKey($order->id)->where('waiter_employee_id', $waiter->user_id)->lockForUpdate()->firstOrFail();
        abort_if($order->status !== 'open' || $order->sent_at !== null, 409, __('La orden ya no permite editar elementos libremente.'));

        return $order;
    }

    private function draftTotals(Order $order): void
    {
        $items = $order->items()->where('cancelled', false)->orderBy('id')->lockForUpdate()->get();
        $order->update(app(OrderTotals::class)->calculate($order, $items));
    }
}
