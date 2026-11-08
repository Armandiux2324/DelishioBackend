<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderItemReturnRequest;
use App\Http\Resources\OrderItemResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemReturn;
use App\Services\OrderTotals;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderItemReturnController extends Controller
{
    public function index(Order $order): AnonymousResourceCollection
    {
        $this->ensureClosedPos($order);

        return OrderItemResource::collection($order->items()->where('cancelled', false)->with('menuItem')
            ->withSum('returns as quantity_returned', 'quantity')->orderBy('id')->get());
    }

    public function __invoke(StoreOrderItemReturnRequest $request, Order $order, OrderItem $item, OrderTotals $totals): JsonResponse
    {
        $data = $request->validated();
        $return = DB::transaction(function () use ($request, $order, $item, $totals, $data) {
            // Serialize returns for the entire order because its tax budget is shared across lines.
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $item = $order->items()->findOrFail($item->id);
            $this->ensureClosedPos($order);
            abort_if($item->cancelled, 409, __('La partida cancelada no se puede devolver.'));
            $previous = $item->returns()->get();
            $amount = BigDecimal::of((string) $data['amount'])->toScale(2);
            $tax = BigDecimal::of((string) $data['tax_amount'])->toScale(2);
            $existing = $previous->firstWhere('request_key', $data['request_key']);
            if ($existing !== null) {
                abort_unless($existing->quantity === (int) $data['quantity']
                    && $existing->amount === (string) $amount && $existing->tax_amount === (string) $tax
                    && $existing->reason === $data['reason'] && $existing->returned_at->toDateTimeString() === $data['returned_at'],
                    409, __('La clave de idempotencia ya se usó con otros datos.'));

                return $existing;
            }

            $errors = [];
            if ($data['returned_at'] < $order->closed_at->toDateTimeString()) {
                $errors['returned_at'] = __('La devolución debe ocurrir después del cierre de la venta.');
            }
            if ($previous->sum('quantity') + (int) $data['quantity'] > $item->quantity) {
                $errors['quantity'] = __('La cantidad devuelta supera la cantidad vendida.');
            }
            $lines = $totals->basis($order)['lines'];
            $redeemed = $totals->proportionalShares($lines, BigDecimal::of($order->loyalty_redeemed_amount));
            $paid = $lines[$item->id]->minus($redeemed[$item->id]);
            if (BigDecimal::sum($amount, ...$previous->pluck('amount'))->isGreaterThan($paid)) {
                $errors['amount'] = __('El importe devuelto supera el consumo pagado de la partida.');
            }
            if ($tax->isPositive()) {
                $exempt = $order->taxExemptions()->where(fn ($query) => $query->whereNull('order_item_id')->orWhere('order_item_id', $item->id))->exists();
                $taxReturned = OrderItemReturn::whereHas('item', fn ($items) => $items->where('order_id', $order->id))->pluck('tax_amount');
                if ($exempt || BigDecimal::sum($tax, ...$taxReturned)->isGreaterThan($order->tax_total)) {
                    $errors['tax_amount'] = __('El impuesto devuelto supera el impuesto cobrado disponible.');
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $item->returns()->create($data + ['recorded_by_employee_id' => $request->user()->id]);
        });

        return response()->json(['data' => $return], $return->wasRecentlyCreated ? 201 : 200);
    }

    private function ensureClosedPos(Order $order): void
    {
        abort_unless($order->source === 'pos' && $order->status === 'closed' && $order->closed_at !== null,
            409, __('Solo se pueden registrar devoluciones de partidas cobradas en el punto de venta.'));
    }
}
