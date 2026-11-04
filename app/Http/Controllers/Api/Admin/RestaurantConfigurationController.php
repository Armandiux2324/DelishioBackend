<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\UpdateRestaurantConfigurationRequest;
use App\Http\Resources\RestaurantConfigurationResource;
use App\Models\CashRegister;
use App\Models\Restaurant;
use App\Support\RestaurantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RestaurantConfigurationController extends Controller
{
    public function show(RestaurantContext $context): RestaurantConfigurationResource
    {
        return new RestaurantConfigurationResource(Restaurant::findOrFail($context->id()));
    }

    public function update(UpdateRestaurantConfigurationRequest $request, RestaurantContext $context): RestaurantConfigurationResource
    {
        return DB::transaction(function () use ($request, $context) {
            $restaurant = Restaurant::whereKey($context->id())->lockForUpdate()->firstOrFail();
            $restaurant->fill($request->validated());
            abort_if($restaurant->isDirty('currency') && CashRegister::exists(), 409, __('La moneda no se puede cambiar después de abrir una caja.'));

            // Valida el estado final bajo bloqueo: los PATCH parciales pueden conservar valores guardados.
            $rules = [];
            foreach (['loyalty_factor' => 'loyalty_enabled', 'loyalty_redeem_value' => 'loyalty_enabled', 'dual_pricing_percentage' => 'dual_pricing_enabled'] as $field => $flag) {
                $rules[$field] = [...$request->rules()[$field], Rule::requiredIf($restaurant->$flag)];
            }
            Validator::make($restaurant->getAttributes(), $rules)->validate();
            $restaurant->save();

            return new RestaurantConfigurationResource($restaurant->refresh());
        });
    }
}
