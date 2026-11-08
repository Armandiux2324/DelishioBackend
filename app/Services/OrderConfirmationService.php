<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\SuperCategory;
use App\Models\TaxClass;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class OrderConfirmationService
{
    // The caller owns the transaction and locks the cash register and order before entering.
    public function confirmLocked(Order $order, bool $preserveTotals = false): void
    {
        abort_unless($order->status === 'open' && $order->sent_at === null, 409);
        ['items' => $items, 'taxes' => $taxes, 'stock' => $stock, 'needed' => $needed, 'deductions' => $deductions] = $this->prepareLocked($order);
        $totals = $preserveTotals ? [] : app(OrderTotals::class)->calculate($order, $items, $taxes);
        foreach ($stock as $id => $ingredient) {
            $ingredient->update(['current_stock' => (string) BigDecimal::of($ingredient->current_stock)->minus($needed[$id])]);
        }
        foreach ($deductions as $deduction) {
            InventoryMovement::create($deduction);
        }
        $order->update($totals + ['status' => 'sent', 'sent_at' => now()]);
    }

    public function prepareLocked(Order $order): array
    {
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
        $insufficient = [];
        foreach ($needed as $id => $quantity) {
            if (BigDecimal::of($stock[$id]->current_stock)->isLessThan($quantity)) {
                $errors[] = __('Existencia insuficiente de :ingredient.', ['ingredient' => $stock[$id]->name]);
                $insufficient[] = $id;
            }
        }
        if ($errors !== []) {
            if ($order->source === 'customer_app') {
                $affected = collect($deductions)->whereIn('inventory_item_id', $insufficient)->pluck('order_item_id');
                $names = $items->whereIn('id', $affected)->pluck('menu_item_id')->unique()->map(fn ($id) => $menus[$id]->name);
                throw InsufficientStockException::withMessages(['items' => $names->map(fn ($name) => __('El elemento :item no está disponible.', ['item' => $name]))->all()]);
            }
            throw ValidationException::withMessages(['inventory' => $errors]);
        }

        return compact('items', 'taxes', 'stock', 'needed', 'deductions');
    }

    public function lockTaxRates(Collection $menus): array
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
