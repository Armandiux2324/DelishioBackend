<?php

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function chefOrder(string $station = 'kitchen', bool $confirmed = true, string $service = 'dine_in'): array
{
    test()->actingAs(test()->waiter, 'sanctum');
    $data = ['service_type' => $service];
    if ($service === 'dine_in') {
        $data['table_number'] = 'A-12';
    } elseif ($service === 'delivery') {
        $data['delivery_fee'] = '5.00';
        $data['delivery_address'] = 'Private delivery address';
    }
    $order = test()->postJson('/api/v1/waiter/orders', $data)->assertCreated()->json('data.id');
    $item = test()->postJson("/api/v1/waiter/orders/$order/items", ['menu_item_id' => test()->menus[$station]->id,
        'quantity' => 2, 'modifiers' => 'Sin cebolla', 'kitchen_notes' => 'Alergia a nueces'])->assertCreated()->json('data.items.0.id');
    if ($confirmed) {
        test()->postJson("/api/v1/waiter/orders/$order/confirm")->assertOk();
    }
    test()->actingAs(test()->kitchen, 'sanctum');

    return [$order, $item];
}

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->a = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->b = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->admin = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'admin', 'pin' => '1234']))->user;
    $this->waiter = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create())->user;
    $this->kitchen = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'chef', 'station' => 'kitchen']))->user;
    $this->bar = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'chef', 'station' => 'bar']))->user;
    $this->foreignChef = app(RestaurantContext::class)->run($this->b, fn () => Employee::factory()->create(['role' => 'chef', 'station' => 'kitchen']))->user;
    $this->actingAs($this->admin, 'sanctum');
    $this->ingredient = InventoryItem::factory()->create(['current_stock' => '500.000']);
    $this->menus = [];
    foreach (['kitchen', 'bar', 'none'] as $station) {
        $menu = MenuItem::factory()->create(['name' => 'Menu '.$station, 'preparation_station' => $station]);
        $menu->ingredients()->create(['inventory_item_id' => $this->ingredient->id, 'quantity' => '1.000']);
        $this->menus[$station] = $menu;
    }
    $this->cash = CashRegister::factory()->create(['opening_amount' => '20.00']);
    $this->actingAs($this->kitchen, 'sanctum');
});

test('polling lists only confirmed active items in the assigned station with kitchen context and no financial or customer data', function () {
    [$order, $item] = chefOrder();
    [, $bar] = chefOrder('bar');
    [, $none] = chefOrder('none');
    [, $draft] = chefOrder('kitchen', false);
    [, $ready] = chefOrder();
    DB::table('order_items')->where('id', $ready)->update(['status' => 'ready']);
    [, $cancelled] = chefOrder();
    DB::table('order_items')->where('id', $cancelled)->update(['cancelled' => true]);
    $result = $this->getJson('/api/v1/chef/queue')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $item)->assertJsonPath('data.0.quantity', 2)
        ->assertJsonPath('data.0.menu_item.name', 'Menu kitchen')->assertJsonPath('data.0.order.table_number', 'A-12')
        ->assertJsonPath('data.0.order.service_type', 'dine_in')->assertJsonPath('data.0.modifiers', 'Sin cebolla')
        ->assertJsonPath('data.0.kitchen_notes', 'Alergia a nueces')->assertJsonPath('meta.per_page', 50);
    $result->assertJsonMissingPath('data.0.unit_price')->assertJsonMissingPath('data.0.order.total')
        ->assertJsonMissingPath('data.0.order.customer_id')->assertJsonMissingPath('data.0.order.delivery_address');
    expect($result->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs($this->bar, 'sanctum')->getJson('/api/v1/chef/queue')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bar);
    $this->actingAs($this->foreignChef, 'sanctum')->getJson('/api/v1/chef/queue')->assertOk()->assertJsonCount(0, 'data');
});

test('preparation progresses forward and waiter polling immediately sees changes without affecting stock or money', function () {
    [$order, $item] = chefOrder();
    $before = Order::findOrFail($order)->getAttributes();
    $stock = $this->ingredient->fresh()->current_stock;
    $movements = InventoryMovement::count();
    foreach (['in_preparation', 'ready'] as $status) {
        $this->patchJson("/api/v1/chef/queue/$item", ['status' => $status])->assertOk()
            ->assertJsonPath('data.status', $status)->assertJsonMissingPath('data.unit_price');
        $this->actingAs($this->waiter, 'sanctum')->getJson("/api/v1/waiter/orders/$order")->assertOk()
            ->assertJsonPath('data.items.0.status', $status);
        $this->actingAs($this->kitchen, 'sanctum');
    }
    $this->getJson('/api/v1/chef/queue')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/chef/queue?status=ready')->assertOk()->assertJsonPath('data.0.id', $item);
    expect(Order::findOrFail($order)->getAttributes())->toBe($before)
        ->and($this->ingredient->fresh()->current_stock)->toBe($stock)->and(InventoryMovement::count())->toBe($movements);
});

test('retries of the same transition are idempotent but skipped and reversed states conflict', function () {
    [, $item] = chefOrder();
    $url = "/api/v1/chef/queue/$item";
    $this->patchJson($url, ['status' => 'ready'])->assertConflict();
    $this->patchJson($url, ['status' => 'in_preparation'])->assertOk();
    $this->patchJson($url, ['status' => 'in_preparation'])->assertOk();
    $this->getJson('/api/v1/chef/queue?status=in_preparation')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson($url, ['status' => 'ready'])->assertOk();
    $this->patchJson($url, ['status' => 'ready'])->assertOk();
    $this->patchJson($url, ['status' => 'in_preparation'])->assertConflict();
    expect(OrderItem::findOrFail($item)->status)->toBe('ready');
});

test('items from another restaurant another station or none cannot be changed', function (string $actor) {
    [, $item] = chefOrder($actor === 'none' ? 'none' : 'kitchen');
    $this->actingAs($actor === 'none' ? $this->kitchen : $this->{$actor}, 'sanctum');
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertNotFound();
    app(RestaurantContext::class)->run($this->a, fn () => expect(OrderItem::findOrFail($item)->status)->toBe('pending'));
})->with(['bar', 'foreignChef', 'none']);

test('draft cancelled and unconfirmed orders never enter preparation', function (string $state) {
    [$order, $item] = chefOrder('kitchen', $state !== 'draft');
    if ($state === 'cancelled_item') {
        DB::table('order_items')->where('id', $item)->update(['cancelled' => true]);
    } elseif ($state === 'cancelled_order') {
        DB::table('orders')->where('id', $order)->update(['status' => 'cancelled']);
    } elseif ($state === 'missing_confirmation') {
        DB::table('orders')->where('id', $order)->update(['sent_at' => null]);
    }
    $this->getJson('/api/v1/chef/queue')->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertConflict();
})->with(['draft', 'cancelled_item', 'cancelled_order', 'missing_confirmation']);

test('paid orders remain in the queue even after cash register closing', function () {
    [$order, $item] = chefOrder();
    $this->actingAs($this->waiter, 'sanctum');
    $total = Order::findOrFail($order)->total;
    $this->postJson("/api/v1/waiter/orders/$order/close", ['payments' => [['method' => 'cash', 'amount' => $total]]])->assertOk();
    $counted = (string) $this->cash->expectedCash();
    $this->postJson('/api/v1/cash-registers/'.$this->cash->id.'/close', ['counted_cash' => $counted])->assertOk();
    $this->actingAs($this->kitchen, 'sanctum')->getJson('/api/v1/chef/queue')->assertOk()->assertJsonPath('data.0.id', $item);
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertOk();
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'ready'])->assertOk();
    expect(Order::findOrFail($order)->status)->toBe('closed');
});

test('oldest creation timestamp wins and equal timestamps use stable IDs with paginated polling', function () {
    [$order, $first] = chefOrder();
    $rows = [];
    $at = now()->subHour()->startOfSecond();
    for ($i = 0; $i < 51; $i++) {
        $rows[] = ['order_id' => $order, 'menu_item_id' => $this->menus['kitchen']->id,
            'quantity' => 1, 'unit_price' => '12.50', 'station' => 'kitchen', 'status' => 'pending', 'cancelled' => false, 'created_at' => $at];
    }
    DB::table('order_items')->insert($rows);
    $expected = OrderItem::where('order_id', $order)->orderBy('created_at')->orderBy('id')->pluck('id')->all();
    $one = $this->getJson('/api/v1/chef/queue')->assertOk()->assertJsonCount(50, 'data')->json('data');
    $two = $this->getJson('/api/v1/chef/queue?page=2')->assertOk()->assertJsonCount(2, 'data')->json('data');
    expect(array_column([...$one, ...$two], 'id'))->toBe($expected)->and(end($expected))->toBe($first);
});

test('queue follows captured station and retains deleted menu names and service types', function (string $service) {
    [, $item] = chefOrder('kitchen', true, $service);
    $this->actingAs($this->admin, 'sanctum');
    $this->menus['kitchen']->update(['preparation_station' => 'bar', 'active' => false]);
    $this->menus['kitchen']->delete();
    $this->actingAs($this->kitchen, 'sanctum')->getJson('/api/v1/chef/queue')->assertOk()
        ->assertJsonPath('data.0.id', $item)->assertJsonPath('data.0.station', 'kitchen')
        ->assertJsonPath('data.0.menu_item.name', 'Menu kitchen')->assertJsonPath('data.0.order.service_type', $service)
        ->assertJsonMissingPath('data.0.order.delivery_address');
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertOk();
})->with(['takeout', 'delivery']);

test('only chef role can read or update the queue', function (string $actor, int $status) {
    [, $item] = chefOrder();
    if ($actor === 'guest') {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();
    } else {
        $user = match ($actor) {
            'admin' => $this->admin,
            'waiter' => $this->waiter,
            'customer' => Customer::factory()->create()->user,
            default => User::factory()->create(['user_type' => 'super_admin']),
        };
        $this->actingAs($user, 'sanctum');
    }
    $this->getJson('/api/v1/chef/queue')->assertStatus($status);
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertStatus($status);
})->with([['guest', 401], ['admin', 403], ['waiter', 403], ['customer', 403], ['super_admin', 403]]);

test('invalid status and forged item data are rejected', function (array $data, string $key) {
    [, $item] = chefOrder();
    $this->patchJson("/api/v1/chef/queue/$item", $data)->assertUnprocessable()->assertJsonValidationErrors($key);
    expect(OrderItem::findOrFail($item)->status)->toBe('pending');
})->with([
    [[], 'status'], [['status' => null], 'status'], [['status' => 'pending'], 'status'],
    [['status' => 'cancelled'], 'status'], [['status' => ['ready']], 'status'],
    [['status' => 'in_preparation', 'station' => 'bar'], 'station'],
    [['status' => 'in_preparation', 'restaurant_id' => 1], 'restaurant_id'],
    [['status' => 'in_preparation', 'order_id' => 1], 'order_id'],
    [['status' => 'in_preparation', 'quantity' => 100], 'quantity'],
    [['status' => 'in_preparation', 'cancelled' => true], 'cancelled'],
    [['status' => 'in_preparation', 'unit_price' => 0], 'unit_price'],
    [['status' => 'in_preparation', 'kitchen_notes' => 'changed'], 'kitchen_notes'],
]);

test('list filters validate known states and cannot override assigned restaurant or station', function (string $query, string $key) {
    $this->getJson('/api/v1/chef/queue?'.$query)->assertUnprocessable()->assertJsonValidationErrors($key);
})->with([['status=cancelled', 'status'], ['status=', 'status'], ['station=bar', 'station'],
    ['restaurant_id=2', 'restaurant_id'], ['employee_id=1', 'employee_id'], ['page=0', 'page'], ['page=bad', 'page']]);

test('inactive unverified deleted or unassigned chefs cannot access preparation', function (string $state) {
    [, $item] = chefOrder();
    if ($state === 'inactive') {
        $this->kitchen->forceFill(['active' => false])->save();
    } elseif ($state === 'unverified') {
        $this->kitchen->forceFill(['email_verified_at' => null])->save();
    } elseif ($state === 'suspended') {
        $this->a->update(['status' => 'suspended']);
    } elseif ($state === 'deleted') {
        $this->kitchen->delete();
    } else {
        DB::table('employees')->where('user_id', $this->kitchen->id)->update(['station' => null]);
    }
    $this->getJson('/api/v1/chef/queue')->assertForbidden();
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertForbidden();
})->with(['inactive', 'unverified', 'suspended', 'deleted', 'unassigned']);

test('changes after route binding are checked again under lock', function (string $change, int $status) {
    [$order, $item] = chefOrder();
    $dispatcher = OrderItem::getEventDispatcher();
    OrderItem::setEventDispatcher(clone $dispatcher);
    $changed = false;
    OrderItem::retrieved(function (OrderItem $bound) use ($order, $item, $change, &$changed) {
        if ($bound->id === $item && ! $changed) {
            $changed = true;
            match ($change) {
                'cancel_item' => DB::table('order_items')->where('id', $item)->update(['cancelled' => true]),
                'cancel_order' => DB::table('orders')->where('id', $order)->update(['status' => 'cancelled']),
                'ready' => DB::table('order_items')->where('id', $item)->update(['status' => 'ready']),
                'station' => DB::table('employees')->where('user_id', test()->kitchen->id)->update(['station' => 'bar']),
                'role' => DB::table('employees')->where('user_id', test()->kitchen->id)->update(['role' => 'waiter']),
                'inactive' => DB::table('users')->where('id', test()->kitchen->id)->update(['active' => false]),
            };
        }
    });
    try {
        $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertStatus($status);
    } finally {
        OrderItem::setEventDispatcher($dispatcher);
    }
})->with([['cancel_item', 409], ['cancel_order', 409], ['ready', 409], ['station', 404], ['role', 403], ['inactive', 403]]);

test('a failed status save rolls back instead of publishing a partial transition', function () {
    [, $item] = chefOrder();
    $dispatcher = OrderItem::getEventDispatcher();
    OrderItem::setEventDispatcher(clone $dispatcher);
    OrderItem::updated(fn () => throw new RuntimeException('failed preparation'));
    $this->withoutExceptionHandling();
    try {
        expect(fn () => $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation']))
            ->toThrow(RuntimeException::class, 'failed preparation');
    } finally {
        OrderItem::setEventDispatcher($dispatcher);
    }
    expect(OrderItem::findOrFail($item)->status)->toBe('pending');
});

test('preparation conflict messages use the authenticated chef language', function () {
    [, $item] = chefOrder();
    $this->kitchen->update(['locale' => 'en']);
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'ready'])->assertConflict()
        ->assertJsonPath('message', 'The preparation transition is not allowed.');
});

test('each restaurant sees and advances its own kitchen items only', function () {
    [, $local] = chefOrder();
    $adminB = app(RestaurantContext::class)->run($this->b, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->actingAs($adminB, 'sanctum');
    $waiterB = Employee::factory()->create();
    $menu = MenuItem::factory()->create(['name' => 'Private B menu']);
    $order = Order::factory()->create(['waiter_employee_id' => $waiterB->user_id]);
    $foreign = $order->items()->create(['menu_item_id' => $menu->id, 'quantity' => 1, 'unit_price' => '12.50', 'station' => 'kitchen']);
    $order->update(['status' => 'sent', 'sent_at' => now()]);
    $this->actingAs($this->foreignChef, 'sanctum')->getJson('/api/v1/chef/queue')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id)->assertJsonPath('data.0.menu_item.name', 'Private B menu');
    $this->patchJson('/api/v1/chef/queue/'.$foreign->id, ['status' => 'in_preparation'])->assertOk();
    $this->patchJson('/api/v1/chef/queue/'.$local, ['status' => 'in_preparation'])->assertNotFound();
    $this->actingAs($this->kitchen, 'sanctum')->getJson('/api/v1/chef/queue')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $local);
    $this->patchJson('/api/v1/chef/queue/'.$foreign->id, ['status' => 'ready'])->assertNotFound();
});

test('a waiter cancellation after polling removes the item and rejects late chef actions', function () {
    [$order, $item] = chefOrder();
    $this->getJson('/api/v1/chef/queue')->assertOk()->assertJsonPath('data.0.id', $item);
    $this->actingAs($this->waiter, 'sanctum')->postJson("/api/v1/waiter/orders/$order/items/$item/cancel",
        ['reason' => 'Cliente canceló', 'admin_user_id' => $this->admin->id, 'pin' => '1234'])->assertOk();
    $this->actingAs($this->kitchen, 'sanctum')->getJson('/api/v1/chef/queue')->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertConflict();
    expect(OrderItem::findOrFail($item)->cancelled)->toBeTrue();
});
