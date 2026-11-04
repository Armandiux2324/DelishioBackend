<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\TaxClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['restaurant_id', 'name', 'percentage'])]
class TaxClass extends Model
{
    /** @use HasFactory<TaxClassFactory> */
    use BelongsToRestaurant, HasFactory, SoftDeletes;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(function (TaxClass $taxClass) {
            abort_if($taxClass->superCategories()->exists(), 409, __('La clase de impuesto tiene súper categorías activas.'));
        });
    }

    protected function casts(): array
    {
        return ['percentage' => 'decimal:2'];
    }

    public function superCategories(): HasMany
    {
        return $this->hasMany(SuperCategory::class);
    }
}
