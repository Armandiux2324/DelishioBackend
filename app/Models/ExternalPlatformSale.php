<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Brick\Math\BigDecimal;
use Database\Factories\ExternalPlatformSaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['platform', 'total_amount', 'commission_amount', 'registered_by_employee_id', 'sale_date'])]
class ExternalPlatformSale extends Model
{
    /** @use HasFactory<ExternalPlatformSaleFactory> */
    use BelongsToRestaurant, HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'commission_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'sale_date' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ExternalPlatformSale $sale) {
            abort_unless(Employee::whereKey($sale->registered_by_employee_id)->whereIn('role', ['admin', 'waiter'])->exists(), 403);
            $sale->net_amount = (string) BigDecimal::of((string) $sale->total_amount)->minus((string) $sale->commission_amount);
        });
        static::updating(fn () => abort(403));
        static::deleting(fn () => abort(403));
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExternalPlatformSaleItem::class, 'external_sale_id')->orderBy('id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'registered_by_employee_id', 'user_id');
    }
}
