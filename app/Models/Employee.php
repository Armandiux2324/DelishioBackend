<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;

#[Fillable(['user_id', 'restaurant_id', 'phone', 'role', 'station', 'hourly_rate', 'pin'])]
#[Hidden(['pin'])]

class Employee extends Model
{
    use BelongsToRestaurant, HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('user', fn ($user) => $user->where('user_type', 'employee')->where('active', true)->whereNotNull('email_verified_at'))
            ->whereHas('restaurant', fn ($restaurant) => $restaurant->where('status', 'active'));
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee) {
            abort_if($employee->exists && $employee->isDirty('user_id'), 403);
            abort_unless(User::whereKey($employee->user_id)->where('user_type', 'employee')->exists(), 403);
        });
    }

    public function resolveRouteBindingQuery($query, $value, $field = null): Builder|Relation
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->whereHas('user');
    }

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:2',
            'pin' => 'hashed',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
