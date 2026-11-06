<?php

namespace App\Http\Controllers\Api\Chef;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chef\ListQueueRequest;
use App\Http\Requests\Chef\UpdatePreparationStatusRequest;
use App\Http\Resources\ChefQueueItemResource;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class QueueController extends Controller
{
    public function index(ListQueueRequest $request): JsonResponse
    {
        $chef = $this->chef();
        $statuses = $request->has('status') ? [$request->validated('status')] : ['pending', 'in_preparation'];
        $items = OrderItem::with(['menuItem:id,name', 'order:id,restaurant_id,service_type,table_number,created_at,sent_at'])
            ->where('station', $chef->station)->where('cancelled', false)->whereIn('status', $statuses)
            ->whereHas('order', fn ($query) => $query->whereNotNull('sent_at')->whereIn('status', ['sent', 'closed']))
            ->orderBy('created_at')->orderBy('id')->paginate(50)->withQueryString();

        return ChefQueueItemResource::collection($items)->response()->header('Cache-Control', 'no-store, private');
    }

    public function update(UpdatePreparationStatusRequest $request, OrderItem $item): JsonResponse
    {
        return DB::transaction(function () use ($request, $item) {
            $chef = $this->chef(true);
            abort_unless($item->station === $chef->station, 404);
            // Match order-before-item locks used by cancellations; payment status does not stop preparation.
            $order = Order::whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($item->id)->where('station', $chef->station)->lockForUpdate()->firstOrFail();
            abort_if($item->cancelled || $order->sent_at === null || ! in_array($order->status, ['sent', 'closed'], true),
                409, __('El elemento no está disponible para preparación.'));
            $status = $request->validated('status');
            if ($item->status !== $status) {
                $next = match ($item->status) {
                    'pending' => 'in_preparation',
                    'in_preparation' => 'ready',
                    default => null,
                };
                abort_unless($status === $next, 409, __('La transición de preparación no está permitida.'));
                $item->update(['status' => $status]);
            }

            return (new ChefQueueItemResource($item->load(['menuItem:id,name', 'order:id,restaurant_id,service_type,table_number,created_at,sent_at'])))
                ->response()->header('Cache-Control', 'no-store, private');
        });
    }

    private function chef(bool $lock = false): Employee
    {
        $query = Employee::active()->whereKey(Auth::guard('sanctum')->id())->where('role', 'chef')->whereIn('station', ['kitchen', 'bar']);
        $chef = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($chef, 403, __('El Chef no tiene una estación de preparación válida.'));

        return $chef;
    }
}
