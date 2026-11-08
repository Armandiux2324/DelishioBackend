<?php

namespace App\Services\Reports;

use App\Models\MenuAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class MenuAuditReport
{
    public function summarize(CarbonImmutable $from, CarbonImmutable $until, array $filters): array
    {
        $entries = MenuAuditLog::where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->when(isset($filters['employee_id']), fn (Builder $query) => $query->where('employee_id', $filters['employee_id']))
            ->when(isset($filters['menu_item_id']), fn (Builder $query) => $query->where('menu_item_id', $filters['menu_item_id']))
            ->with(['employee.user' => fn ($user) => $user->withTrashed()->select('id', 'name', 'username'), 'menuItem:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (MenuAuditLog $log) => [
                'id' => $log->id,
                'menu_item_id' => $log->menu_item_id,
                'menu_item_name' => $log->menuItem?->name,
                'employee_id' => $log->employee_id,
                'employee_name' => $log->employee?->user?->name,
                'username' => $log->employee?->user?->username,
                'action' => $log->action,
                'old_value' => json_decode($log->old_value ?? 'null', true, flags: JSON_THROW_ON_ERROR),
                'new_value' => json_decode($log->new_value ?? 'null', true, flags: JSON_THROW_ON_ERROR),
                'created_at' => $log->created_at,
            ]);

        return [
            'entries' => $entries,
            'totals' => ['entry_count' => $entries->count(), 'by_action' => $entries->countBy('action')],
        ];
    }
}
