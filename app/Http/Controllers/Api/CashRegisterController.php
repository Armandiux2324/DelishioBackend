<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\CloseCashRegisterRequest;
use App\Http\Requests\Cash\OpenCashRegisterRequest;
use App\Http\Resources\CashRegisterResource;
use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Restaurant;
use App\Support\RestaurantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CashRegisterResource::collection(CashRegister::orderByDesc('opened_at')->orderByDesc('id')->paginate(20));
    }

    public function show(CashRegister $cashRegister): CashRegisterResource
    {
        return new CashRegisterResource($cashRegister);
    }

    public function store(OpenCashRegisterRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            Restaurant::whereKey(app(RestaurantContext::class)->id())->lockForUpdate()->firstOrFail();
            abort_if(CashRegister::whereNull('closed_at')->exists(), 409, __('El restaurante ya tiene una caja abierta.'));
            $register = CashRegister::create($request->validated() + ['opened_by_employee_id' => $request->user()->id, 'opened_at' => now()]);

            return (new CashRegisterResource($register))->response()->setStatusCode(201);
        });
    }

    public function close(CloseCashRegisterRequest $request, CashRegister $cashRegister): CashRegisterResource
    {
        return DB::transaction(function () use ($request, $cashRegister) {
            $register = CashRegister::whereKey($cashRegister->id)->lockForUpdate()->firstOrFail();
            abort_if($register->closed_at !== null, 409, __('La caja ya está cerrada.'));
            abort_if(Order::where('cash_register_id', $register->id)->whereIn('status', ['open', 'sent'])
                ->where(fn ($orders) => $orders->where('source', '!=', 'customer_app')->orWhereDoesntHave('advancePayment')
                    ->orWhereHas('advancePayment', fn ($payment) => $payment->where('status', 'captured')))->exists(),
                409, __('La caja tiene órdenes pendientes de cerrar o cancelar.'));
            $expected = $register->expectedCash();
            if ($expected->isNegative() || $expected->isGreaterThan('99999999.99')) {
                throw ValidationException::withMessages(['counted_cash' => __('El saldo esperado de caja está fuera del rango permitido.')]);
            }
            $register->update([
                'closed_by_employee_id' => $request->user()->id, 'counted_cash' => $request->validated('counted_cash'),
                'expected_cash' => (string) $expected,
                'difference' => (string) BigDecimal::of((string) $request->validated('counted_cash'))->minus($expected),
                'closed_at' => now(),
            ]);

            return new CashRegisterResource($register);
        });
    }
}
