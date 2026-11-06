<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreAdvanceOrderRequest;
use App\Http\Resources\CustomerMenuItemResource;
use App\Http\Resources\CustomerOrderResource;
use App\Http\Resources\CustomerRestaurantResource;
use App\Http\Resources\PromotionResource;
use App\Models\LoyaltyBalance;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Restaurant;
use App\Services\CustomerOrderService;
use App\Support\RestaurantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerApiController extends Controller
{
    public function restaurants(Request $request): JsonResponse
    {
        $this->pagination($request);

        return CustomerRestaurantResource::collection(Restaurant::where('status', 'active')->orderBy('name')->orderBy('id')->paginate(20))->response();
    }

    public function restaurant(Restaurant $restaurant): JsonResponse
    {
        return $this->catalog($restaurant, fn () => (new CustomerRestaurantResource($restaurant))->response());
    }

    public function menu(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->pagination($request);

        return $this->catalog($restaurant, fn () => CustomerMenuItemResource::collection(MenuItem::with('category.superCategory.taxClass')
            ->where('active', true)->whereHas('category', fn ($query) => $query->withoutTrashed()->whereHas('superCategory.taxClass'))
            ->orderBy('name')->orderBy('id')->paginate(20))->response());
    }

    public function promotions(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->pagination($request);

        return $this->catalog($restaurant, fn () => PromotionResource::collection(Promotion::currentlyAvailable()
            ->with(['menuItems' => fn ($query) => $query->where('active', true)->whereHas('category', fn ($category) => $category->withoutTrashed()->whereHas('superCategory.taxClass')),
                'categories'])->orderBy('id')->paginate(20))->response());
    }

    public function store(StoreAdvanceOrderRequest $request, Restaurant $restaurant, CustomerOrderService $service): JsonResponse
    {
        try {
            return $this->catalog($restaurant, fn () => (new CustomerOrderResource($service->create($restaurant, $request->validated())))->response()->setStatusCode(201));
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, __('La clave de idempotencia ya se usó con otra solicitud.'));
        }
    }

    public function orders(Request $request): JsonResponse
    {
        $this->pagination($request);
        // Customer history explicitly selects its owner instead of inheriting an employee tenant.
        $orders = Order::withoutGlobalScope('restaurant')->where('customer_id', $request->user()->id)->where('source', 'customer_app')
            ->with($this->historyRelations())->orderByDesc('id')->paginate(20);

        return CustomerOrderResource::collection($orders)->response()->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $order = $this->owned($request, $order);
        $order->load($this->historyRelations());

        return (new CustomerOrderResource($order))->response()->header('Cache-Control', 'no-store, private');
    }

    public function payment(Request $request, string $order, CustomerOrderService $service): JsonResponse
    {
        $request->validate(array_fill_keys(['amount', 'total', 'currency', 'stripe_account_id', 'customer_id', 'restaurant_id',
            'payment_reference', 'payment_confirmed', 'status', 'card_number', 'cvv', 'card', 'payment_method',
            'payment_intent_id', 'refund_attempts', 'authorized_at', 'captured_at', 'surcharge_total', 'loyalty_redeemed_amount'], ['missing']));
        $order = $this->owned($request, $order);
        $restaurant = Restaurant::findOrFail($order->restaurant_id);

        return $this->catalog($restaurant, fn () => response()->json(['data' => $service->payment($order)]))->header('Cache-Control', 'no-store, private');
    }

    public function points(Request $request): JsonResponse
    {
        $this->pagination($request);
        $balances = LoyaltyBalance::withoutGlobalScope('restaurant')->where('customer_id', $request->user()->id)->with('restaurant')
            ->orderBy('restaurant_id')->paginate(20)->through(fn ($balance) => ['restaurant_id' => $balance->restaurant_id,
                'restaurant_name' => $balance->restaurant?->name, 'points_balance' => $balance->points_balance]);

        return response()->json($balances)->header('Cache-Control', 'no-store, private');
    }

    private function owned(Request $request, string $id): Order
    {
        return Order::withoutGlobalScope('restaurant')->where('customer_id', $request->user()->id)->where('source', 'customer_app')->whereKey($id)->firstOrFail();
    }

    private function historyRelations(): array
    {
        // Only eager-load children of the already owner-filtered orders, including suspended/deleted restaurant history.
        return ['items' => fn ($query) => $query->withoutGlobalScope('restaurant')->orderBy('id')
            ->with(['menuItem' => fn ($menu) => $menu->withoutGlobalScope('restaurant')->select('id', 'name')]),
            'advancePayment' => fn ($query) => $query->withoutGlobalScope('restaurant')];
    }

    private function catalog(Restaurant $restaurant, Closure $callback): JsonResponse
    {
        abort_unless($restaurant->status === 'active', 404);

        return app(RestaurantContext::class)->run($restaurant, $callback);
    }

    private function pagination(Request $request): void
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
    }
}
