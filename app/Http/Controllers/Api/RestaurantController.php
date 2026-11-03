<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreRestaurantRequest;
use App\Http\Requests\SuperAdmin\UpdateRestaurantRequest;
use App\Http\Resources\RestaurantResource;
use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RestaurantController extends Controller
{
    /**
     * RFSA-002 - Consulta de Restaurantes.
     */
    public function index(): AnonymousResourceCollection
    {
        return RestaurantResource::collection(
            Restaurant::orderBy('name')->paginate(20)
        );
    }

    public function show(Restaurant $restaurant): RestaurantResource
    {
        return new RestaurantResource($restaurant);
    }

    /**
     * RFSA-001 - Alta de Restaurante.
     *
     * Crea el restaurante y su Administrador principal en una sola
     * transacción: no tiene sentido que exista uno sin el otro.
     *
     * El PIN del administrador se autogenera (RFSA-001 no lo pide en el
     * formulario, pero sin PIN no podría autorizar nada vía RFU-005).
     * hourly_rate queda en 0 — el rol Administrador no se paga por hora
     * de la misma forma que Mesero/Chef; si el restaurante sí lo necesita
     * para nómina, el propio Administrador lo ajusta después (RFA-026).
     */
    public function store(StoreRestaurantRequest $request): JsonResponse
    {
        $restaurant = DB::transaction(function () use ($request) {
            $restaurant = Restaurant::create([
                'name' => $request->name,
                'address' => $request->address,
                'phone' => $request->phone,
                'created_by_user_id' => $request->user()->id,
            ]);

            $adminUser = User::create([
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => $request->admin_password,
                'user_type' => 'employee',
                'email_verified_at' => now(),
            ]);

            Employee::create([
                'user_id' => $adminUser->id,
                'restaurant_id' => $restaurant->id,
                'role' => 'admin',
                'hourly_rate' => 0,
                'pin' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            ]);

            return $restaurant;
        });

        return (new RestaurantResource($restaurant))->response()->setStatusCode(201);
    }

    /**
     * RFSA-003 - Edición de Restaurante.
     */
    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant): RestaurantResource
    {
        $restaurant->update($request->validated());

        return new RestaurantResource($restaurant);
    }

    /**
     * RFSA-004 - Activación de Restaurante.
     */
    public function activate(Restaurant $restaurant): RestaurantResource
    {
        $restaurant->update(['status' => 'active']);

        return new RestaurantResource($restaurant);
    }

    /**
     * RFSA-005 - Suspensión de Restaurante.
     */
    public function suspend(Restaurant $restaurant): RestaurantResource
    {
        $restaurant->update(['status' => 'suspended']);

        return new RestaurantResource($restaurant);
    }

    /**
     * RFSA-006 - Eliminación de Restaurante (soft delete, restaurants ya
     * tiene SoftDeletes — las órdenes/empleados históricos del restaurante
     * eliminado siguen siendo consultables, no se borran en cascada).
     */
    public function destroy(Restaurant $restaurant): JsonResponse
    {
        $restaurant->delete();

        return response()->json(['message' => 'Restaurante eliminado']);
    }
}