<?php

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\Restaurant;
use App\Models\SuperCategory;
use App\Models\TaxClass;
use App\Models\TaxExemption;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function adjustmentOrder(bool $sent = true, bool $two = false): array
{
    $order = test()->postJson('/api/v1/waiter/orders', ['service_type' => 'takeout'])->assertCreated()->json('data.id');
    $response = test()->postJson('/api/v1/waiter/orders/'.$order.'/items', ['menu_item_id' => test()->menuA->id, 'quantity' => 1])->assertCreated();
    $first = $response->json('data.items.0.id');
    $second = null;
    if ($two) {
        $response = test()->postJson('/api/v1/waiter/orders/'.$order.'/items', ['menu_item_id' => test()->menuA2->id, 'quantity' => 1])->assertCreated();
        $second = collect($response->json('data.items'))->max('id');
    }
    if ($sent) {
        test()->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertOk();
    }

    return [$order, $first, $second];
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 5)->startOfDay()->addHours(12));
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id, 'discount_limit_per_shift' => '50.00', 'courtesy_percentage' => '10.00', 'courtesy_daily_limit' => 2]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id, 'discount_limit_per_shift' => '999.00', 'courtesy_percentage' => '20.00', 'courtesy_daily_limit' => 9]);
    $this->adminA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['role' => 'admin', 'pin' => '012345']))->user;
    $this->waiterA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create())->user;
    $this->colleague = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create())->user;
    $this->adminB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create(['role' => 'admin', 'pin' => '654321']))->user;
    $this->waiterB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create())->user;
    $this->actingAs($this->adminA, 'sanctum');
    $this->ingredient = InventoryItem::factory()->create(['current_stock' => '100.000']);
    foreach (['10.00', '20.00'] as $index => $percentage) {
        $tax = TaxClass::factory()->create(['percentage' => $percentage]);
        $category = Category::factory()->create(['super_category_id' => SuperCategory::factory()->create(['tax_class_id' => $tax->id])->id]);
        $menu = MenuItem::factory()->create(['category_id' => $category->id, 'sale_price' => $index === 0 ? '100.00' : '50.00']);
        $menu->ingredients()->create(['inventory_item_id' => $this->ingredient->id, 'quantity' => '1.000']);
        if ($index === 0) {
            $this->menuA = $menu;
        } else {
            $this->menuA2 = $menu;
        }
    }
    $this->cashA = CashRegister::factory()->create();
    $this->shiftA = WorkShift::factory()->create(['employee_id' => $this->waiterA->id]);
    $this->actingAs($this->adminB, 'sanctum');
    $this->cashB = CashRegister::factory()->create();
    $this->actingAs($this->waiterA, 'sanctum');
    $this->approval = ['pin' => '012345'];
});

test('line and subtotal discounts allocate cents exactly and exemptions recalculate taxes without PIN', function () {
    [$order, $line] = adjustmentOrder(true, true);
    $url = '/api/v1/waiter/orders/'.$order;
    $this->postJson($url.'/discounts', $this->approval + ['order_item_id' => (string) $line, 'percentage' => '10'])
        ->assertCreated()->assertJsonPath('data.discount_total', '10.00')->assertJsonPath('data.tax_total', '19.00')->assertJsonPath('data.total', '159.00')
        ->assertJsonPath('data.discounts.0.kind', 'discount')->assertJsonPath('data.discounts.0.amount', '10.00')
        ->assertJsonPath('data.discounts.0.authorized_by_employee_id', $this->adminA->id)->assertJsonMissingPath('data.discounts.0.pin');
    $this->postJson($url.'/discounts', $this->approval + ['amount' => '20.00'])->assertCreated()
        ->assertJsonPath('data.discount_total', '30.00')->assertJsonPath('data.tax_total', '16.28')->assertJsonPath('data.total', '136.28');
    $this->postJson($url.'/tax-exemptions', ['order_item_id' => (string) $line, 'reason' => 'Constancia fiscal'])->assertCreated()
        ->assertJsonPath('data.tax_total', '8.57')->assertJsonPath('data.total', '128.57');
    $this->postJson($url.'/tax-exemptions', ['reason' => 'Cuenta exenta'])->assertCreated()->assertJsonPath('data.tax_total', '0.00')->assertJsonPath('data.total', '120.00');
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.discounts')->assertJsonCount(2, 'data.tax_exemptions');
});

test('a fixed configured courtesy uses its own daily quota and not the shift discount amount', function () {
    [$order] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/courtesies', $this->approval)->assertCreated()
        ->assertJsonPath('data.discounts.0.kind', 'courtesy')->assertJsonPath('data.discounts.0.percentage', '10.00')
        ->assertJsonPath('data.discounts.0.amount', '10.00')->assertJsonPath('data.discount_total', '10.00')->assertJsonPath('data.total', '99.00');
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => 50])->assertCreated();
    expect(OrderDiscount::where('work_shift_id', $this->shiftA->id)->count())->toBe(2);
});

test('shift limits accumulate across orders and a new shift has an independent amount budget', function () {
    [$first] = adjustmentOrder();
    [$second] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$first.'/discounts', $this->approval + ['amount' => 40])->assertCreated();
    $this->postJson('/api/v1/waiter/orders/'.$second.'/discounts', $this->approval + ['amount' => 10])->assertCreated();
    $this->postJson('/api/v1/waiter/orders/'.$second.'/discounts', $this->approval + ['amount' => '0.01'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson('/api/v1/work-shifts/'.$this->shiftA->id.'/clock-out')->assertOk();
    $new = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    $this->postJson('/api/v1/waiter/orders/'.$second.'/discounts', $this->approval + ['amount' => 20])->assertCreated();
    expect(OrderDiscount::latest('id')->firstOrFail()->work_shift_id)->toBe($new);
    expect(OrderDiscount::count())->toBe(3);
});

test('courtesy uses stay consumed after cancellations or shift changes and reset on the next application day', function () {
    $this->restaurantA->update(['courtesy_daily_limit' => 1]);
    [$first] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$first.'/courtesies', $this->approval)->assertCreated();
    $this->postJson('/api/v1/waiter/orders/'.$first.'/cancel', $this->approval + ['reason' => 'Cancelación autorizada'])->assertOk();
    $this->postJson('/api/v1/work-shifts/'.$this->shiftA->id.'/clock-out')->assertOk();
    $this->postJson('/api/v1/work-shifts')->assertCreated();
    [$second] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$second.'/courtesies', $this->approval)->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->travel(1)->days();
    $this->postJson('/api/v1/waiter/orders/'.$second.'/courtesies', $this->approval)->assertCreated();
    expect(OrderDiscount::where('kind', 'courtesy')->count())->toBe(2);
});

test('financial operations require a current shift and configured limits', function () {
    [$order] = adjustmentOrder();
    $this->postJson('/api/v1/work-shifts/'.$this->shiftA->id.'/clock-out')->assertOk();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => 1])->assertConflict();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/courtesies', $this->approval)->assertConflict();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/tax-exemptions', ['reason' => 'Constancia sin turno'])->assertCreated();
    $this->postJson('/api/v1/work-shifts')->assertCreated();
    $this->restaurantA->update(['discount_limit_per_shift' => null, 'courtesy_percentage' => null]);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => 1])->assertUnprocessable();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/courtesies', $this->approval)->assertUnprocessable();
    expect(OrderDiscount::count())->toBe(0);
});

test('discount inputs enforce exclusive positive amounts percentages PIN and protected identifiers', function (array $data, string $field) {
    [$order] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', array_replace($this->approval + ['amount' => 1], $data))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(OrderDiscount::count())->toBe(0);
})->with([
    [['amount' => 0], 'amount'], [['amount' => -1], 'amount'], [['amount' => '1.001'], 'amount'],
    [['amount' => '100000000'], 'amount'], [['amount' => 101], 'amount'],
    [['percentage' => 10], 'amount'], [['amount' => null, 'percentage' => 101], 'percentage'],
    [['amount' => null, 'percentage' => 0], 'percentage'], [['amount' => null, 'percentage' => '1.001'], 'percentage'],
    [['pin' => '123'], 'pin'], [['pin' => 1234], 'pin'], [['admin_user_id' => null], 'admin_user_id'],
    [['kind' => 'courtesy'], 'kind'], [['work_shift_id' => 999], 'work_shift_id'], [['authorized_by_employee_id' => 1], 'authorized_by_employee_id'],
    [['requested_by_employee_id' => 1], 'requested_by_employee_id'], [['created_at' => null], 'created_at'],
    [['discount_limit_per_shift' => 9999], 'discount_limit_per_shift'],
]);

test('courtesy configuration and tax exemption reasons cannot be forged', function () {
    [$order] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/courtesies', $this->approval + ['percentage' => 100, 'amount' => 100, 'courtesy_daily_limit' => 999])
        ->assertUnprocessable()->assertJsonValidationErrors(['percentage', 'amount', 'courtesy_daily_limit']);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/tax-exemptions', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson('/api/v1/waiter/orders/'.$order.'/tax-exemptions', ['reason' => str_repeat('ñ', 32768)])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson('/api/v1/waiter/orders/'.$order.'/tax-exemptions', ['reason' => 'Valido', 'pin' => '012345'])->assertUnprocessable()->assertJsonValidationErrors('pin');
});

test('duplicate tax exemptions and adjustments on cancelled items are rejected', function () {
    [$order, $line] = adjustmentOrder(true, true);
    $url = '/api/v1/waiter/orders/'.$order;
    $this->postJson($url.'/tax-exemptions', ['order_item_id' => $line, 'reason' => 'Constancia'])->assertCreated();
    $this->postJson($url.'/tax-exemptions', ['order_item_id' => $line, 'reason' => 'Otra'])->assertConflict();
    $this->postJson($url.'/items/'.$line.'/cancel', $this->approval + ['reason' => 'Cancelado'])->assertOk();
    $this->postJson($url.'/discounts', $this->approval + ['order_item_id' => $line, 'amount' => 1])->assertConflict();
    $this->postJson($url.'/tax-exemptions', ['order_item_id' => $line, 'reason' => 'No'])->assertConflict();
    $this->postJson($url.'/tax-exemptions', ['reason' => 'Cuenta'])->assertCreated();
    $this->postJson($url.'/tax-exemptions', ['reason' => 'Duplicado'])->assertConflict();
});

test('sent cancellation needs a valid local administrator PIN and preserves consumed inventory', function () {
    [$order, $line] = adjustmentOrder(true, true);
    $url = '/api/v1/waiter/orders/'.$order.'/items/'.$line.'/cancel';
    $this->postJson($url, ['reason' => 'Rechazado'])->assertUnprocessable()->assertJsonValidationErrors('pin');
    $this->postJson($url, ['reason' => 'Rechazado', 'admin_user_id' => $this->adminB->id, 'pin' => '654321'])->assertUnprocessable()->assertJsonValidationErrors('pin');
    $this->postJson($url, $this->approval + ['reason' => 'Devolución autorizada'])->assertOk()
        ->assertJsonPath('data.items.0.cancelled', true)->assertJsonPath('data.items.0.cancel_authorized_by_employee_id', $this->adminA->id)
        ->assertJsonPath('data.subtotal', '50.00')->assertJsonPath('data.tax_total', '10.00')->assertJsonPath('data.total', '60.00');
    expect($this->ingredient->fresh()->current_stock)->toBe('98.000');
    expect(InventoryMovement::count())->toBe(2);
    $this->postJson($url, $this->approval + ['reason' => 'Otra vez'])->assertConflict();
});

test('whole order or last item cancellation zeroes the bill and permits register closure', function (bool $whole) {
    [$order, $line] = adjustmentOrder();
    $url = '/api/v1/waiter/orders/'.$order.($whole ? '' : '/items/'.$line).'/cancel';
    $this->postJson($url, $this->approval + ['reason' => 'Orden cancelada'])->assertOk()->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.total', '0.00')->assertJsonPath('data.discount_total', '0.00');
    $this->postJson('/api/v1/cash-registers/'.$this->cashA->id.'/close', ['counted_cash' => 100])->assertOk();
    $this->postJson($url, $this->approval + ['reason' => 'Duplicado'])->assertConflict();
})->with([true, false]);

test('draft cancellations require reason but no PIN and do not consume stock', function () {
    [$order, $line] = adjustmentOrder(false);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/items/'.$line.'/cancel', ['reason' => 'El cliente cambió de opinión'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.items.0.cancel_authorized_by_employee_id', null);
    expect($this->ingredient->fresh()->current_stock)->toBe('100.000');
    expect(InventoryMovement::count())->toBe(0);
    $empty = $this->postJson('/api/v1/waiter/orders', ['service_type' => 'takeout'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/waiter/orders/'.$empty.'/cancel', ['reason' => 'Borrador vacío'])->assertOk()->assertJsonPath('data.status', 'cancelled');
});

test('authorized amounts survive draft edits and audited line removal never deletes their history', function () {
    [$order, $line] = adjustmentOrder(false);
    $url = '/api/v1/waiter/orders/'.$order;
    $this->postJson($url.'/discounts', $this->approval + ['order_item_id' => $line, 'percentage' => 10])->assertCreated();
    $this->patchJson($url.'/items/'.$line, ['quantity' => 2])->assertOk()->assertJsonPath('data.subtotal', '200.00')->assertJsonPath('data.discount_total', '10.00');
    $this->postJson($url.'/tax-exemptions', ['order_item_id' => $line, 'reason' => 'Exento'])->assertCreated();
    $this->deleteJson($url.'/items/'.$line)->assertNoContent();
    $this->assertDatabaseHas('order_items', ['id' => $line, 'cancelled' => true]);
    expect(OrderDiscount::count())->toBe(1);
    expect(TaxExemption::count())->toBe(1);
    $this->postJson($url.'/items', ['menu_item_id' => $this->menuA2->id, 'quantity' => 1])->assertCreated()->assertJsonPath('data.discount_total', '0.00');
    $this->postJson($url.'/confirm')->assertOk()->assertJsonPath('data.subtotal', '50.00')->assertJsonPath('data.tax_total', '10.00');
    expect($this->ingredient->fresh()->current_stock)->toBe('99.000');
});

test('bill adjustments before confirmation are retained by confirmation and cannot enlarge their monetary approval', function () {
    [$order, $line] = adjustmentOrder(false);
    $url = '/api/v1/waiter/orders/'.$order;
    $this->postJson($url.'/discounts', $this->approval + ['percentage' => 10])->assertCreated()->assertJsonPath('data.discount_total', '10.00');
    $this->patchJson($url.'/items/'.$line, ['quantity' => 2])->assertOk()->assertJsonPath('data.discount_total', '10.00');
    $this->postJson($url.'/confirm')->assertOk()->assertJsonPath('data.subtotal', '200.00')->assertJsonPath('data.tax_total', '19.00')->assertJsonPath('data.total', '209.00');
});

test('a rollback after ledger or cancellation writes leaves all business state unchanged', function (string $operation) {
    [$order, $line] = adjustmentOrder();
    $dispatcher = Order::getEventDispatcher();
    Order::setEventDispatcher(clone $dispatcher);
    Order::updated(fn () => throw new RuntimeException('Final write failed'));
    try {
        $body = match ($operation) {
            'discounts' => $this->approval + ['amount' => 10],
            'courtesies' => $this->approval,
            'tax-exemptions' => ['reason' => 'Comprobante'],
            'cancel' => $this->approval + ['reason' => 'Cancelación'],
        };
        $this->postJson('/api/v1/waiter/orders/'.$order.'/'.$operation, $body)->assertServerError();
    } finally {
        Order::setEventDispatcher($dispatcher);
    }
    expect(OrderDiscount::count())->toBe(0);
    expect(TaxExemption::count())->toBe(0);
    $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'sent', 'discount_total' => 0, 'total' => 110]);
    $this->assertDatabaseHas('order_items', ['id' => $line, 'cancelled' => false, 'cancel_reason' => null]);
    expect($this->ingredient->fresh()->current_stock)->toBe('99.000');
})->with(['discounts', 'courtesies', 'tax-exemptions', 'cancel']);

test('PIN rate limiting survives business rollbacks with the database cache driver', function () {
    config(['cache.default' => 'database']);
    [$order] = adjustmentOrder();
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', ['admin_user_id' => $this->adminA->id, 'pin' => '999999', 'amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('pin');
    }
    expect((int) Cache::store('database')->get('admin-pin:'.$this->restaurantA->id.':user:'.$this->waiterA->id))->toBe(5);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => 1])->assertStatus(429);
    expect(OrderDiscount::count())->toBe(0);
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => 1])->assertCreated();
});

test('stale draft binding cannot bypass PIN after concurrent confirmation', function (bool $timestamp) {
    [$order] = adjustmentOrder(false);
    $dispatcher = Order::getEventDispatcher();
    Order::setEventDispatcher(clone $dispatcher);
    $changed = false;
    Order::retrieved(function (Order $bound) use ($order, &$changed, $timestamp) {
        if ($bound->id === $order && ! $changed) {
            $changed = true;
            DB::table('orders')->where('id', $order)->update(['status' => 'sent', 'sent_at' => $timestamp ? now() : null]);
        }
    });
    try {
        $this->postJson('/api/v1/waiter/orders/'.$order.'/cancel', ['reason' => 'Sin PIN'])->assertUnprocessable()->assertJsonValidationErrors('pin');
    } finally {
        Order::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'sent']);
})->with([true, false]);

test('scope checks reject foreign orders other waiters targets and authorization identities', function () {
    $this->actingAs($this->waiterB, 'sanctum');
    $foreignOrder = $this->postJson('/api/v1/waiter/orders', ['service_type' => 'takeout'])->assertCreated()->json('data.id');
    $this->actingAs($this->colleague, 'sanctum');
    [$colleagueOrder, $colleagueLine] = adjustmentOrder();
    $this->actingAs($this->waiterA, 'sanctum');
    [$own, $ownLine] = adjustmentOrder();
    foreach ([$foreignOrder, $colleagueOrder] as $id) {
        $this->postJson('/api/v1/waiter/orders/'.$id.'/discounts', $this->approval + ['amount' => 1])->assertNotFound();
        $this->postJson('/api/v1/waiter/orders/'.$id.'/courtesies', $this->approval)->assertNotFound();
        $this->postJson('/api/v1/waiter/orders/'.$id.'/tax-exemptions', ['reason' => 'No'])->assertNotFound();
        $this->postJson('/api/v1/waiter/orders/'.$id.'/cancel', $this->approval + ['reason' => 'No'])->assertNotFound();
    }
    $this->postJson('/api/v1/waiter/orders/'.$own.'/discounts', $this->approval + ['order_item_id' => $colleagueLine, 'amount' => 1])->assertNotFound();
    $this->postJson('/api/v1/waiter/orders/'.$own.'/tax-exemptions', ['order_item_id' => $colleagueLine, 'reason' => 'No'])->assertNotFound();
    $this->postJson('/api/v1/waiter/orders/'.$own.'/items/'.$colleagueLine.'/cancel', $this->approval + ['reason' => 'No'])->assertNotFound();
    $this->postJson('/api/v1/waiter/orders/'.$own.'/discounts', ['admin_user_id' => $this->adminB->id, 'pin' => '654321', 'amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('pin');
    expect(OrderDiscount::count())->toBe(0);
    expect(TaxExemption::count())->toBe(0);
    $this->assertDatabaseHas('order_items', ['id' => $ownLine, 'cancelled' => false]);
});

test('only authenticated waiters can invoke adjustment endpoints', function (string $role) {
    [$order, $line] = adjustmentOrder();
    if ($role === 'guest') {
        auth()->forgetGuards();
    } elseif ($role === 'admin') {
        $this->actingAs($this->adminA, 'sanctum');
    } elseif ($role === 'chef') {
        $this->actingAs(Employee::factory()->create(['role' => 'chef', 'station' => 'bar'])->user, 'sanctum');
    } else {
        $this->actingAs(User::factory()->create(['user_type' => $role]), 'sanctum');
    }
    $status = $role === 'guest' ? 401 : 403;
    $this->getJson('/api/v1/waiter/admin-authorizers')->assertStatus($status);
    $url = '/api/v1/waiter/orders/'.$order;
    $this->postJson($url.'/discounts', $this->approval + ['amount' => 1])->assertStatus($status);
    $this->postJson($url.'/courtesies', $this->approval)->assertStatus($status);
    $this->postJson($url.'/tax-exemptions', ['reason' => 'No'])->assertStatus($status);
    $this->postJson($url.'/cancel', $this->approval + ['reason' => 'No'])->assertStatus($status);
    $this->postJson($url.'/items/'.$line.'/cancel', $this->approval + ['reason' => 'No'])->assertStatus($status);
})->with(['guest', 'admin', 'chef', 'customer', 'super_admin']);

test('closed cancelled orders and closed registers reject further changes', function (string $state) {
    [$order] = adjustmentOrder();
    if ($state === 'closed_register') {
        DB::table('cash_registers')->where('id', $this->cashA->id)->update(['closed_at' => now()]);
    } else {
        DB::table('orders')->where('id', $order)->update(['status' => $state]);
    }
    $url = '/api/v1/waiter/orders/'.$order;
    $this->postJson($url.'/discounts', $this->approval + ['amount' => 1])->assertConflict();
    $this->postJson($url.'/courtesies', $this->approval)->assertConflict();
    $this->postJson($url.'/tax-exemptions', ['reason' => 'No'])->assertConflict();
    $this->postJson($url.'/cancel', $this->approval + ['reason' => 'No'])->assertConflict();
})->with(['closed', 'cancelled', 'closed_register']);

test('adjustment audit records fail closed and cannot be edited or deleted', function () {
    [$order, $line] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => 1])->assertCreated();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/tax-exemptions', ['reason' => 'Exento'])->assertCreated();
    $discount = OrderDiscount::firstOrFail();
    $exemption = TaxExemption::firstOrFail();
    expect(fn () => $discount->update(['amount' => 0]))->toThrow(HttpException::class);
    expect(fn () => $discount->delete())->toThrow(HttpException::class);
    expect(fn () => $exemption->update(['reason' => 'Cambio']))->toThrow(HttpException::class);
    expect(fn () => $exemption->delete())->toThrow(HttpException::class);
    $this->actingAs($this->waiterB, 'sanctum');
    expect(OrderDiscount::count())->toBe(0);
    expect(TaxExemption::count())->toBe(0);
    auth()->forgetGuards();
    expect(fn () => OrderDiscount::count())->toThrow(HttpException::class);
    expect(fn () => TaxExemption::count())->toThrow(HttpException::class);
});

test('authorizer lookup reveals only active local administrators names and ids', function () {
    $this->getJson('/api/v1/waiter/admin-authorizers')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $this->adminA->id)->assertJsonPath('data.0.name', $this->adminA->name)
        ->assertJsonMissingPath('data.0.pin')->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.hourly_rate');
    $this->adminA->forceFill(['active' => false])->save();
    $this->getJson('/api/v1/waiter/admin-authorizers')->assertOk()->assertJsonCount(0, 'data');
});

test('fractional tax rates retain their decimals when a confirmed bill is adjusted', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->category->superCategory->taxClass->update(['percentage' => '7.25']);
    $this->actingAs($this->waiterA, 'sanctum');
    [$order] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => 10])->assertCreated()
        ->assertJsonPath('data.tax_total', '6.53')->assertJsonPath('data.total', '96.53');
});

test('subtotal allocation across tiny lines never loses cents or creates negative bases', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->update(['sale_price' => '0.01']);
    $this->actingAs($this->waiterA, 'sanctum');
    [$order] = adjustmentOrder(false);
    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/v1/waiter/orders/'.$order.'/items', ['menu_item_id' => $this->menuA->id, 'quantity' => 1])->assertCreated();
    }
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertOk();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => '0.02'])->assertCreated()
        ->assertJsonPath('data.discount_total', '0.02')->assertJsonPath('data.total', '0.01');
});

test('cancelled lines cap applied discounts without returning the originally approved shift quota', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA2->update(['sale_price' => '20.00']);
    $this->actingAs($this->waiterA, 'sanctum');
    [$order, $line] = adjustmentOrder(true, true);
    $url = '/api/v1/waiter/orders/'.$order;
    $this->postJson($url.'/discounts', $this->approval + ['amount' => 50])->assertCreated();
    $this->postJson($url.'/items/'.$line.'/cancel', $this->approval + ['reason' => 'Cancelado'])->assertOk()
        ->assertJsonPath('data.subtotal', '20.00')->assertJsonPath('data.discount_total', '20.00')->assertJsonPath('data.total', '0.00');
    expect(OrderDiscount::firstOrFail()->amount)->toBe('50.00');
    [$next] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$next.'/discounts', $this->approval + ['amount' => '0.01'])->assertUnprocessable()->assertJsonValidationErrors('amount');
});

test('courtesy quotas belong to each waiter rather than the whole restaurant', function () {
    $this->restaurantA->update(['courtesy_daily_limit' => 1]);
    [$first] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$first.'/courtesies', $this->approval)->assertCreated();
    $this->actingAs($this->colleague, 'sanctum');
    $this->postJson('/api/v1/work-shifts')->assertCreated();
    [$second] = adjustmentOrder();
    $this->postJson('/api/v1/waiter/orders/'.$second.'/courtesies', $this->approval)->assertCreated();
    expect(OrderDiscount::where('kind', 'courtesy')->count())->toBe(2);
});

test('PIN rotation between approval and mutation invalidates the in-flight authorization', function () {
    [$order] = adjustmentOrder();
    Log::shouldReceive('info')->once()->with('admin_pin_authorized', Mockery::type('array'))->andReturnUsing(function () {
        $this->adminA->employee->update(['pin' => '111111']);
    });
    $this->postJson('/api/v1/waiter/orders/'.$order.'/discounts', $this->approval + ['amount' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('pin');
    expect(OrderDiscount::count())->toBe(0);
});

test('successful cancellation records action time and actors without logging credentials', function () {
    [$order, $line] = adjustmentOrder();
    Log::spy();
    $response = $this->postJson('/api/v1/waiter/orders/'.$order.'/items/'.$line.'/cancel', $this->approval + ['reason' => 'Autorizado']);
    $response->assertOk()->assertDontSee('012345');
    Log::shouldHaveReceived('info')->with('admin_pin_authorized', [
        'restaurant_id' => $this->restaurantA->id, 'requested_by_employee_id' => $this->waiterA->id,
        'authorized_by_employee_id' => $this->adminA->id, 'action' => 'order.item.cancel', 'authorized_at' => '2026-11-05T12:00:00+00:00',
    ])->once();
    $this->assertDatabaseHas('order_items', ['id' => $line, 'cancelled_at' => '2026-11-05 12:00:00']);
    $this->assertDatabaseHas('orders', ['id' => $order, 'cancelled_at' => '2026-11-05 12:00:00',
        'cancel_reason' => 'Autorizado', 'cancel_authorized_by_employee_id' => $this->adminA->id]);
    Log::shouldHaveReceived('info')->with('order_cancelled', [
        'order_id' => $order, 'order_item_id' => $line, 'reason' => 'Autorizado',
        'requested_by_employee_id' => $this->waiterA->id, 'authorized_by_employee_id' => $this->adminA->id,
        'cancelled_at' => '2026-11-05T12:00:00+00:00',
    ])->once();
});

test('audit model writes cannot bind an unrelated authorizer shift target or requester', function () {
    [$order, $line] = adjustmentOrder();
    $foreignShift = app(RestaurantContext::class)->run($this->restaurantB, fn () => WorkShift::factory()->create(['employee_id' => $this->waiterB->id]));
    $data = ['order_id' => $order, 'kind' => 'discount', 'amount' => '1.00', 'requested_by_employee_id' => $this->waiterA->id,
        'authorized_by_employee_id' => $this->adminA->id, 'work_shift_id' => $this->shiftA->id];
    foreach ([['authorized_by_employee_id' => $this->adminB->id], ['work_shift_id' => $foreignShift->id], ['requested_by_employee_id' => $this->waiterB->id]] as $change) {
        expect(fn () => OrderDiscount::create(array_replace($data, $change)))->toThrow(HttpException::class);
    }
    expect(fn () => TaxExemption::create(['order_id' => $order, 'reason' => 'No', 'requested_by_employee_id' => $this->waiterB->id]))->toThrow(HttpException::class);
});
