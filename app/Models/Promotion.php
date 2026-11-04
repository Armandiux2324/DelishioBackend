<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['restaurant_id', 'name', 'condition_type', 'condition_value', 'discount_type', 'discount_value', 'days_of_week', 'time_start', 'time_end', 'valid_from', 'valid_to', 'active'])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use BelongsToRestaurant, HasFactory, SoftDeletes;

    public $timestamps = false;

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['condition_value' => 'decimal:2', 'discount_value' => 'decimal:2', 'active' => 'boolean'];
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'promotion_menu_items')->using(PromotionMenuItem::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promotion_categories')->using(PromotionCategory::class);
    }
}
