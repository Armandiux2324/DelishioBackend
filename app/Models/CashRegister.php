<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Brick\Math\BigDecimal;
use Database\Factories\CashRegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['restaurant_id', 'opened_by_employee_id', 'opening_amount', 'opened_at', 'closed_by_employee_id', 'counted_cash', 'expected_cash', 'difference', 'closed_at'])]
class CashRegister extends Model
{
    /** @use HasFactory<CashRegisterFactory> */
    use BelongsToRestaurant, HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['opening_amount' => 'decimal:2', 'counted_cash' => 'decimal:2', 'expected_cash' => 'decimal:2',
            'difference' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (CashRegister $register) => abort_unless(
            Employee::active()->whereKey($register->opened_by_employee_id)->whereIn('role', ['admin', 'waiter'])->exists(), 403
        ));
        static::saving(function (CashRegister $register) {
            if ($register->exists) {
                abort_if($register->isDirty(['opened_by_employee_id', 'opening_amount', 'opened_at']), 403);
                abort_if($register->getRawOriginal('closed_at') !== null && $register->isDirty(), 409, __('La caja ya está cerrada.'));
            }
            if ($register->closed_by_employee_id !== null) {
                abort_unless(Employee::active()->whereKey($register->closed_by_employee_id)->where('role', 'waiter')->exists(), 403);
            }
        });
        static::deleting(fn () => abort(403));
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'opened_by_employee_id', 'user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'closed_by_employee_id', 'user_id');
    }

    public function expectedCash(): BigDecimal
    {
        $expected = BigDecimal::of($this->opening_amount);
        foreach (Payment::where('method', 'cash')->whereHas('order', fn ($query) => $query->where('cash_register_id', $this->id)->where('status', 'closed'))->orderBy('id')->cursor() as $payment) {
            $expected = $expected->plus($payment->amount);
        }
        foreach ($this->movements()->orderBy('id')->cursor() as $movement) {
            $expected = $movement->type === 'cash_in' ? $expected->plus($movement->amount) : $expected->minus($movement->amount);
        }

        return $expected;
    }
}
