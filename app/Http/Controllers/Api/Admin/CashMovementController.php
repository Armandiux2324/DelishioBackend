<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\StoreCashMovementRequest;
use App\Http\Resources\CashMovementResource;
use App\Models\CashMovement;
use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashMovementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CashMovementResource::collection(CashMovement::orderByDesc('created_at')->orderByDesc('id')->paginate(20));
    }

    public function show(CashMovement $cashMovement): CashMovementResource
    {
        return new CashMovementResource($cashMovement);
    }

    public function store(StoreCashMovementRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $register = CashRegister::whereKey($data['cash_register_id'])->lockForUpdate()->firstOrFail();
            abort_if($register->closed_at !== null, 409, __('La caja ya está cerrada.'));
            $expected = $register->expectedCash();
            $remaining = $data['type'] === 'cash_in' ? $expected->plus((string) $data['amount']) : $expected->minus((string) $data['amount']);
            if ($remaining->isNegative()) {
                throw ValidationException::withMessages(['amount' => __('El monto supera el efectivo disponible en caja.')]);
            }
            if ($remaining->isGreaterThan('99999999.99')) {
                throw ValidationException::withMessages(['amount' => __('El saldo de caja excede el límite permitido.')]);
            }
            $movement = CashMovement::create($data + ['registered_by_employee_id' => $request->user()->id]);

            return (new CashMovementResource($movement))->response()->setStatusCode(201);
        });
    }
}
