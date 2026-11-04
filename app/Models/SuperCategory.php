<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\SuperCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['restaurant_id', 'name', 'tax_class_id'])]
class SuperCategory extends Model
{
    /** @use HasFactory<SuperCategoryFactory> */
    use BelongsToRestaurant, HasFactory, SoftDeletes;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function (SuperCategory $category) {
            abort_unless(TaxClass::whereKey($category->tax_class_id)->lockForUpdate()->first(), 403);
        });
        static::deleting(function (SuperCategory $category) {
            abort_if($category->categories()->exists(), 409, __('La súper categoría tiene categorías activas.'));
        });
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
