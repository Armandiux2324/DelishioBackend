<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

#[Fillable(['menu_item_id', 'employee_id', 'action', 'old_value', 'new_value'])]
class MenuAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('menuItem'));
        static::creating(function (MenuAuditLog $log) {
            abort_unless(MenuItem::withTrashed()->whereKey($log->menu_item_id)->exists(), 403);
            abort_unless(Employee::whereKey($log->employee_id)->where('role', 'admin')->exists(), 403);
        });
        static::updating(fn () => abort(403));
        static::deleting(fn () => abort(403));
    }

    public static function record(int $menuItemId, string $action, ?array $old, ?array $new): void
    {
        $user = Auth::guard('sanctum')->user();
        abort_unless($user?->user_type === 'employee', 403);
        $user->ensureCanAccess();
        $values = [];
        foreach (['old_value' => $old, 'new_value' => $new] as $key => $value) {
            $values[$key] = static::encodeValue($value);
        }
        static::create($values + ['menu_item_id' => $menuItemId, 'employee_id' => $user->id, 'action' => $action]);
    }

    public static function encodeValue(?array $value): ?string
    {
        $encoded = $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== null && strlen($encoded) > 65535) {
            throw ValidationException::withMessages(['description' => __('Los valores de auditoría no deben exceder 65535 bytes.')]);
        }

        return $encoded;
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'user_id');
    }
}
