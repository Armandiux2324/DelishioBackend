<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Uso: ->middleware('role:admin,waiter') o ->middleware('role:super_admin').
     *
     * Los valores aceptados son: super_admin, customer (= users.user_type),
     * y admin, waiter, chef (= employees.role). El middleware resuelve cuál
     * de los dos campos corresponde según el user_type de la cuenta.
     *
     * Si la ruta trae un parámetro {restaurant} (id o modelo vía route model
     * binding), además verifica que el empleado autenticado pertenezca a ese
     * restaurante — sin esto, un Administrador autenticado correctamente podría leer o modificar datos de otro
     * restaurante con solo cambiar el id en la URL.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'No autenticado');
        }

        $userRole = match ($user->user_type) {
            'super_admin', 'customer' => $user->user_type,
            'employee' => $user->employee?->role,
        };

        if (!$userRole || !in_array($userRole, $roles, true)) {
            abort(403, 'No tienes permiso para esta acción');
        }

        if ($user->user_type === 'employee' && $request->route('restaurant')) {
            $routeRestaurant = $request->route('restaurant');
            $routeRestaurantId = is_object($routeRestaurant) ? $routeRestaurant->id : (int) $routeRestaurant;

            if ($routeRestaurantId !== $user->employee->restaurant_id) {
                abort(403, 'No tienes acceso a este restaurante');
            }
        }

        return $next($request);
    }
}