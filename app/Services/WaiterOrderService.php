<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SuperCategory;
use App\Models\TaxClass;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
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
            $items = $order->items()->where('cancelled', false)->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => __('La orden debe contener al menos un elemento.')]);
            }
            $menus = MenuItem::whereIn('id', $items->pluck('menu_item_id'))->where('active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($menus->count() !== $items->pluck('menu_item_id')->unique()->count()) {
                throw ValidationException::withMessages(['items' => __('Hay elementos de menú que ya no están disponibles.')]);
            }
            $taxes = $this->lockTaxRates($menus);
            $recipes = MenuItemIngredient::whereIn('menu_item_id', $menus->keys())->orderBy('menu_item_id')->orderBy('inventory_item_id')
                ->lockForUpdate()->get()->groupBy('menu_item_id');
            $needed = $deductions = [];
            foreach ($items as $item) {
                foreach ($recipes->get($item->menu_item_id, collect()) as $recipe) {
                    $amount = BigDecimal::of($recipe->quantity)->multipliedBy($item->quantity);
                    if ($amount->isLessThanOrEqualTo(0)) {
                        throw ValidationException::withMessages(['items' => __('La receta contiene cantidades inválidas.')]);
                    }
                    $id = $recipe->inventory_item_id;
                    $needed[$id] = ($needed[$id] ?? BigDecimal::of('0.000'))->plus($amount);
                    $deductions[] = ['inventory_item_id' => $id, 'order_item_id' => $item->id, 'quantity' => (string) $amount->negated()];
                }
            }
            // Stable inventory lock order also serializes shared ingredients across different orders.
            $stock = InventoryItem::whereIn('id', array_keys($needed))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($stock->count() !== count($needed)) {
                throw ValidationException::withMessages(['inventory' => __('Un ingrediente de la receta no está disponible.')]);
            }
            $errors = [];
            foreach ($needed as $id => $quantity) {
                if (BigDecimal::of($stock[$id]->current_stock)->isLessThan($quantity)) {
                    $errors[] = __('Existencia insuficiente de :ingredient.', ['ingredient' => $stock[$id]->name]);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['inventory' => $errors]);
            }
            $totals = app(OrderTotals::class)->calculate($order, $items, $taxes);
            foreach ($stock as $id => $ingredient) {
                $ingredient->update(['current_stock' => (string) BigDecimal::of($ingredient->current_stock)->minus($needed[$id])]);
            }
            foreach ($deductions as $deduction) {
                InventoryMovement::create($deduction);
            }
            $order->update($totals + ['status' => 'sent', 'sent_at' => now()]);

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

    private function lockTaxRates(Collection $menus): array
    {
        $categories = Category::whereIn('id', $menus->pluck('category_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $supers = SuperCategory::whereIn('id', $categories->pluck('super_category_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $taxes = TaxClass::whereIn('id', $supers->pluck('tax_class_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $rates = [];
        foreach ($menus as $menu) {
            $category = $categories->get($menu->category_id);
            $super = $category ? $supers->get($category->super_category_id) : null;
            $tax = $super ? $taxes->get($super->tax_class_id) : null;
            if (! $tax) {
                throw ValidationException::withMessages(['items' => __('La clasificación fiscal del menú no está disponible.')]);
            }
            $rates[$menu->id] = $tax->percentage;
        }

        return $rates;
    }
}
