<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cash_register_id', 'type', 'amount', 'reason', 'related_employee_id', 'payroll_period_start', 'payroll_period_end', 'registered_by_employee_id'])]
class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('cashRegister'));
        static::creating(function (CashMovement $movement) {
            $register = CashRegister::whereKey($movement->cash_register_id)->lockForUpdate()->first();
            abort_unless($register, 403);
            abort_if($register->closed_at !== null, 409, __('La caja ya está cerrada.'));
            abort_unless(Employee::active()->whereKey($movement->registered_by_employee_id)->where('role', 'admin')->exists(), 403);
            if ($movement->related_employee_id !== null) {
                abort_unless(Employee::whereKey($movement->related_employee_id)->exists(), 403);
            }
        });
        static::updating(fn () => abort(403));
        static::deleting(fn () => abort(403));
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'registered_by_employee_id', 'user_id');
    }

    public function relatedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'related_employee_id', 'user_id');
    }
}
