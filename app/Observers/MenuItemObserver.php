<?php

namespace App\Observers;

use App\Models\MenuAuditLog;
use App\Models\MenuItem;

class MenuItemObserver
{
    public function saving(MenuItem $item): void
    {
        // Keep the full snapshot within TEXT capacity, including future deletion logs.
        MenuAuditLog::encodeValue($item->only(MenuItem::AUDITED_FIELDS));
    }

    public function created(MenuItem $item): void
    {
        MenuAuditLog::record($item->id, 'create', null, $item->only(MenuItem::AUDITED_FIELDS));
    }

    public function updated(MenuItem $item): void
    {
        $changed = array_intersect(array_keys($item->getChanges()), MenuItem::AUDITED_FIELDS);
        $groups = [
            'price_change' => array_intersect($changed, ['sale_price']),
            $item->active ? 'activate' : 'deactivate' => array_intersect($changed, ['active']),
            'edit' => array_diff($changed, ['sale_price', 'active']),
        ];
        foreach ($groups as $action => $fields) {
            if ($fields === []) {
                continue;
            }
            $old = $new = [];
            foreach ($fields as $field) {
                $old[$field] = $item->getOriginal($field);
                $new[$field] = $item->getAttribute($field);
            }
            MenuAuditLog::record($item->id, $action, $old, $new);
        }
    }

    public function deleted(MenuItem $item): void
    {
        MenuAuditLog::record($item->id, 'delete', $item->only(MenuItem::AUDITED_FIELDS), null);
    }
}
