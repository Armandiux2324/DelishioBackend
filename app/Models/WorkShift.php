<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\WorkShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'clock_in', 'clock_out'])]
class WorkShift extends Model
{
    /** @use HasFactory<WorkShiftFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['clock_in' => 'datetime', 'clock_out' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('employee'));
        static::saving(function (WorkShift $shift) {
            if ($shift->exists) {
                abort_unless(Employee::whereKey($shift->getRawOriginal('employee_id'))->exists(), 403);
                abort_if($shift->isDirty(['employee_id', 'clock_in']), 403);
                abort_if($shift->getRawOriginal('clock_out') !== null && $shift->isDirty(), 409, __('El turno ya está cerrado.'));
            }
            abort_unless(Employee::whereKey($shift->employee_id)->exists(), 403);
        });
        static::deleting(fn () => abort(403));
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'user_id');
    }
}
