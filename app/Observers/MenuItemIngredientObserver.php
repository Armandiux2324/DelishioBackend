<?php

namespace App\Observers;

use App\Models\MenuAuditLog;
use App\Models\MenuItemIngredient;

class MenuItemIngredientObserver
{
    public function created(MenuItemIngredient $ingredient): void
    {
        MenuAuditLog::record($ingredient->menu_item_id, 'edit', null, ['ingredients' => $ingredient->only(['inventory_item_id', 'quantity'])]);
    }

    public function updated(MenuItemIngredient $ingredient): void
    {
        if ($ingredient->wasChanged('quantity')) {
            MenuAuditLog::record($ingredient->menu_item_id, 'edit',
                ['ingredients' => ['inventory_item_id' => $ingredient->inventory_item_id, 'quantity' => $ingredient->getOriginal('quantity')]],
                ['ingredients' => $ingredient->only(['inventory_item_id', 'quantity'])]
            );
        }
    }

    public function deleted(MenuItemIngredient $ingredient): void
    {
        MenuAuditLog::record($ingredient->menu_item_id, 'edit', ['ingredients' => $ingredient->only(['inventory_item_id', 'quantity'])], null);
    }
}
