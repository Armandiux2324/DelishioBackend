<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Carbon\CarbonInterface;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    public function scopeCurrentlyAvailable(Builder $query): Builder
    {
        $now = now();
        $time = $now->format('H:i:s');
        $day = function (Builder $window, CarbonInterface $date): void {
            $window->where('valid_from', '<=', $date->toDateString())->where('valid_to', '>=', $date->toDateString())
                ->where(fn ($days) => $days->whereNull('days_of_week')->orWhere('days_of_week', 'like', '%'.$date->format('D').'%'));
        };

        return $query->where('active', true)->where(function (Builder $windows) use ($day, $now, $time) {
            $windows->where(function (Builder $today) use ($day, $now, $time) {
                $day($today, $now);
                $today->where(fn ($hours) => $hours->whereNull('time_start')->orWhere(fn ($normal) => $normal
                    ->whereColumn('time_start', '<', 'time_end')->where('time_start', '<=', $time)->where('time_end', '>=', $time))
                    ->orWhere(fn ($late) => $late->whereColumn('time_start', '>', 'time_end')->where('time_start', '<=', $time)));
            })->orWhere(function (Builder $yesterday) use ($day, $now, $time) {
                $day($yesterday, $now->copy()->subDay());
                $yesterday->whereColumn('time_start', '>', 'time_end')->where('time_end', '>=', $time);
            });
        });
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promotion_categories')->using(PromotionCategory::class);
    }
}
