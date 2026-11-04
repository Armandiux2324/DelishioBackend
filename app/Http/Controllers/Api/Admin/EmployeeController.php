<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EmployeeResource::collection(Employee::with('user')->whereHas('user')->orderBy('user_id')->paginate(20));
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $this->lockRestaurant();
            $user = User::create($request->safe()->only(['name', 'username', 'email', 'password', 'locale']) + ['user_type' => 'employee']);
            $user->forceFill(['email_verified_at' => now(), 'active' => $request->validated('active', true)])->save();
            $generatedPin = $request->has('pin') ? null : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $data = $request->safe()->only(['phone', 'role', 'station', 'hourly_rate', 'pin']);
            $data['station'] = $data['role'] === 'chef' ? $data['station'] : null;
            $employee = Employee::create($data + ['user_id' => $user->id, 'pin' => $generatedPin]);
            $resource = new EmployeeResource($employee->load('user'));
            if ($generatedPin !== null) {
                $resource->additional(['meta' => ['generated_pin' => $generatedPin]]);
            }

            return $resource->response()->setStatusCode(201);
        });
    }

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee->load('user'));
    }

    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        return DB::transaction(function () use ($request, $employee) {
            $this->lockRestaurant();
            $employee = Employee::whereKey($employee->user_id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($employee->user_id)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            $role = $data['role'] ?? $employee->role;
            $active = (bool) ($data['active'] ?? $user->active);
            if ($role === 'chef') {
                Validator::make(['station' => $data['station'] ?? $employee->station], ['station' => ['required', 'in:kitchen,bar']])->validate();
            }
            if ($employee->role === 'admin' && $user->active && ($role !== 'admin' || ! $active)) {
                $this->ensureAnotherAdmin($employee);
            }
            $user->forceFill($request->safe()->only(['name', 'username', 'email', 'password', 'locale', 'active']))->save();
            $employeeData = $request->safe()->only(['phone', 'role', 'station', 'hourly_rate', 'pin']);
            if ($role !== 'chef') {
                $employeeData['station'] = null;
            }
            $employee->update($employeeData);
            if ($user->wasChanged(['username', 'email', 'password', 'active']) || $employee->wasChanged(['pin', 'role', 'station'])) {
                $user->tokens()->delete();
                $user->verificationCodes()->where('used', false)->update(['used' => true]);
            }

            return new EmployeeResource($employee->refresh()->load('user'));
        });
    }

    public function destroy(Employee $employee): Response
    {
        DB::transaction(function () use ($employee) {
            $this->lockRestaurant();
            $employee = Employee::whereKey($employee->user_id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($employee->user_id)->lockForUpdate()->firstOrFail();
            if ($employee->role === 'admin' && $user->active) {
                $this->ensureAnotherAdmin($employee);
            }
            $user->tokens()->delete();
            $user->verificationCodes()->where('used', false)->update(['used' => true]);
            $user->delete();
        });

        return response()->noContent();
    }

    private function lockRestaurant(): void
    {
        Restaurant::whereKey(app(RestaurantContext::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function ensureAnotherAdmin(Employee $employee): void
    {
        abort_unless(Employee::where('role', 'admin')->where('user_id', '!=', $employee->user_id)
            ->whereHas('user', fn ($query) => $query->where('user_type', 'employee')->where('active', true)->whereNotNull('email_verified_at'))->exists(),
            409, __('El restaurante debe conservar al menos un administrador activo.'));
    }
}
