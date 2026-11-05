<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['restaurant_id', 'waiter_employee_id', 'customer_id', 'source', 'service_type', 'table_number', 'delivery_address', 'delivery_fee', 'status', 'subtotal', 'tax_total', 'discount_total', 'dual_pricing_applied', 'tip_amount', 'total', 'cash_register_id', 'sent_at', 'closed_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToRestaurant, HasFactory;

    public const UPDATED_AT = null;

    protected $attributes = ['status' => 'open', 'subtotal' => '0.00', 'tax_total' => '0.00', 'discount_total' => '0.00', 'dual_pricing_applied' => false, 'tip_amount' => '0.00', 'total' => '0.00'];

    protected function casts(): array
    {
        return ['delivery_fee' => 'decimal:2', 'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'discount_total' => 'decimal:2',
            'dual_pricing_applied' => 'boolean', 'tip_amount' => 'decimal:2', 'total' => 'decimal:2', 'created_at' => 'datetime', 'sent_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Order $order) {
            abort_if($order->exists && $order->getRawOriginal('closed_at') !== null && $order->isDirty(), 409, __('La orden ya no permite ajustes.'));
            abort_if($order->exists && $order->isDirty(['waiter_employee_id', 'source', 'cash_register_id']), 403);
            if ($order->customer_id !== null) {
                abort_unless(Customer::whereKey($order->customer_id)->whereHas('user', fn ($query) => $query->where('user_type', 'customer'))->exists(), 403);
            }
            if ($order->waiter_employee_id !== null) {
                abort_unless(Employee::whereKey($order->waiter_employee_id)->exists(), 403);
            }
            if ($order->cash_register_id !== null) {
                abort_unless(CashRegister::whereKey($order->cash_register_id)->exists(), 403);
            }
        });
        static::deleting(fn () => abort(403));
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class)->orderBy('id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    public function taxExemptions(): HasMany
    {
        return $this->hasMany(TaxExemption::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'waiter_employee_id', 'user_id');
    }
}
