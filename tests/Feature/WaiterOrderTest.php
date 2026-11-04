<?php

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\SuperCategory;
use App\Models\TaxClass;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function waiterDraft(array $data = ['service_type' => 'takeout']): int
{
    return test()->postJson('/api/v1/waiter/orders', $data)->assertCreated()->json('data.id');
}

function waiterLine(int $order, int $menu, int $quantity = 1): int
{
    $response = test()->postJson('/api/v1/waiter/orders/'.$order.'/items', ['menu_item_id' => $menu, 'quantity' => $quantity])->assertCreated();

    return collect($response->json('data.items'))->max('id');
}

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->adminA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->waiterA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create())->user;
    $this->otherWaiter = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create())->user;
    $this->adminB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->waiterB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create())->user;
    $this->actingAs($this->adminA, 'sanctum');
    $tax = TaxClass::factory()->create(['percentage' => '10.00']);
    $category = Category::factory()->create(['super_category_id' => SuperCategory::factory()->create(['tax_class_id' => $tax->id])->id]);
    $this->ingredientA = InventoryItem::factory()->create(['name' => 'Harina', 'current_stock' => '10.000']);
    $this->ingredientA2 = InventoryItem::factory()->create(['name' => 'Leche', 'current_stock' => '10.000']);
    $this->menuA = MenuItem::factory()->create(['category_id' => $category->id, 'sale_price' => '10.00', 'preparation_station' => 'kitchen']);
    $this->menuA->ingredients()->create(['inventory_item_id' => $this->ingredientA->id, 'quantity' => '0.125']);
    $this->menuA2 = MenuItem::factory()->create(['category_id' => $category->id, 'sale_price' => '5.00', 'preparation_station' => 'bar', 'legal_age_required' => true]);
    $this->menuA2->ingredients()->create(['inventory_item_id' => $this->ingredientA->id, 'quantity' => '0.250']);
    $this->menuA2->ingredients()->create(['inventory_item_id' => $this->ingredientA2->id, 'quantity' => '0.050']);
    $this->cashA = CashRegister::factory()->create();
    $this->actingAs($this->adminB, 'sanctum');
    $this->menuB = MenuItem::factory()->create();
    $this->ingredientB = InventoryItem::factory()->create(['current_stock' => '10.000']);
    $this->menuB->ingredients()->create(['inventory_item_id' => $this->ingredientB->id, 'quantity' => '0.500']);
    $this->cashB = CashRegister::factory()->create();
    $this->actingAs($this->waiterA, 'sanctum');
});

test('waiter creates edits and confirms an order consuming shared ingredients atomically', function () {
    $this->travelTo(now()->startOfSecond());
    $order = waiterDraft(['service_type' => 'delivery', 'delivery_address' => 'Calle 1', 'delivery_fee' => '15.00']);
    $url = '/api/v1/waiter/orders/'.$order;
    $first = waiterLine($order, $this->menuA->id, 2);
    waiterLine($order, $this->menuA2->id, 2);
    $this->patchJson($url.'/items/'.$first, ['modifiers' => 'Sin sal', 'kitchen_notes' => 'Alergia declarada'])->assertOk()
        ->assertJsonPath('data.subtotal', '30.00')->assertJsonPath('data.tax_total', '0.00')->assertJsonPath('data.total', '45.00');
    expect($this->ingredientA->fresh()->current_stock)->toBe('10.000');
    expect(InventoryMovement::count())->toBe(0);
    $this->postJson($url.'/confirm')->assertOk()->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.subtotal', '30.00')->assertJsonPath('data.tax_total', '3.00')->assertJsonPath('data.total', '48.00')
        ->assertJsonPath('data.cash_register_id', $this->cashA->id)->assertJsonPath('data.waiter_employee_id', $this->waiterA->id)
        ->assertJsonPath('data.source', 'pos')->assertJsonPath('data.items.0.station', 'kitchen')->assertJsonPath('data.items.0.status', 'pending');
    expect($this->ingredientA->fresh()->current_stock)->toBe('9.250');
    expect($this->ingredientA2->fresh()->current_stock)->toBe('9.900');
    $this->assertDatabaseHas('orders', ['id' => $order, 'sent_at' => now()->format('Y-m-d H:i:s')]);
    $this->assertDatabaseHas('inventory_movements', ['order_item_id' => $first, 'inventory_item_id' => $this->ingredientA->id, 'quantity' => -0.250]);
    expect(InventoryMovement::count())->toBe(3);
    $this->postJson($url.'/confirm')->assertConflict();
    $this->postJson($url.'/items', ['menu_item_id' => $this->menuA->id, 'quantity' => 1])->assertConflict();
    $this->patchJson($url.'/items/'.$first, ['quantity' => 3])->assertConflict();
    $this->deleteJson($url.'/items/'.$first)->assertConflict();
    expect($this->ingredientA->fresh()->current_stock)->toBe('9.250');
    expect(InventoryMovement::count())->toBe(3);
});

test('service types follow document fields and allow zero delivery fees', function (array $data) {
    $this->postJson('/api/v1/waiter/orders', $data)->assertCreated()->assertJsonPath('data.service_type', $data['service_type'])->assertJsonPath('data.status', 'open');
})->with([[['service_type' => 'dine_in', 'table_number' => 'A-12']], [['service_type' => 'takeout']], [['service_type' => 'delivery', 'delivery_fee' => '0']]]);

test('creating orders validates service fields and forbids server owned totals identities and state', function (array $data, string $field) {
    $this->postJson('/api/v1/waiter/orders', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Order::count())->toBe(0);
})->with([
    [[], 'service_type'], [['service_type' => 'other'], 'service_type'], [['service_type' => 'dine_in'], 'table_number'],
    [['service_type' => 'takeout', 'table_number' => '1'], 'table_number'],
    [['service_type' => 'takeout', 'delivery_address' => 'X'], 'delivery_address'],
    [['service_type' => 'delivery'], 'delivery_fee'], [['service_type' => 'delivery', 'delivery_fee' => '-0.01'], 'delivery_fee'],
    [['service_type' => 'delivery', 'delivery_fee' => '1.001'], 'delivery_fee'],
    [['service_type' => 'delivery', 'delivery_fee' => '100000000'], 'delivery_fee'],
]);

test('order identity totals and timestamps cannot be supplied by the client', function (string $field) {
    $this->postJson('/api/v1/waiter/orders', ['service_type' => 'takeout', $field => null])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['restaurant_id', 'waiter_employee_id', 'customer_id', 'cash_register_id', 'source', 'status', 'subtotal', 'tax_total', 'discount_total', 'tip_amount', 'dual_pricing_applied', 'total', 'created_at', 'sent_at', 'closed_at', 'items']);

test('order items require local active menu positive integer quantities and bounded text', function (string $field, mixed $value) {
    $order = waiterDraft();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/items', array_replace(['menu_item_id' => $this->menuA->id, 'quantity' => 1], [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(OrderItem::count())->toBe(0);
})->with([
    ['quantity', 0], ['quantity', -1], ['quantity', 1.5], ['quantity', 2147483648], ['quantity', null],
    ['menu_item_id', 999999], ['modifiers', str_repeat('ñ', 32768)], ['kitchen_notes', ['not' => 'text']],
    ['unit_price', '0.01'], ['station', 'none'], ['status', 'ready'], ['cancelled', true],
    ['cancel_reason', 'No'], ['cancel_authorized_by_employee_id', 1], ['order_id', 999], ['created_at', null],
]);

test('draft edits and removals recalculate totals without touching stock even after menu deletion', function () {
    $order = waiterDraft();
    $line = waiterLine($order, $this->menuA->id);
    $other = waiterLine($order, $this->menuA2->id);
    $this->patchJson('/api/v1/waiter/orders/'.$order.'/items/'.$line, ['quantity' => 3, 'modifiers' => null])->assertOk()->assertJsonPath('data.subtotal', '35.00');
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->delete();
    $this->menuA2->delete();
    $this->actingAs($this->waiterA, 'sanctum');
    $this->deleteJson('/api/v1/waiter/orders/'.$order.'/items/'.$line)->assertNoContent();
    $this->deleteJson('/api/v1/waiter/orders/'.$order.'/items/'.$other)->assertNoContent();
    $this->getJson('/api/v1/waiter/orders/'.$order)->assertJsonPath('data.subtotal', '0.00')->assertJsonCount(0, 'data.items');
    expect($this->ingredientA->fresh()->current_stock)->toBe('10.000');
    expect(InventoryMovement::count())->toBe(0);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertUnprocessable()->assertJsonValidationErrors('items');
});

test('confirmation rejects summed shortages across lines naming ingredients without partial consumption', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->ingredientA->update(['current_stock' => '0.600']);
    $this->ingredientA2->update(['current_stock' => '0.050']);
    $this->actingAs($this->waiterA, 'sanctum');
    $order = waiterDraft();
    waiterLine($order, $this->menuA->id, 2);
    waiterLine($order, $this->menuA2->id, 2);
    $response = $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertUnprocessable()->assertJsonValidationErrors('inventory');
    expect(implode(' ', $response->json('errors.inventory')))->toContain('Harina', 'Leche');
    expect($this->ingredientA->fresh()->current_stock)->toBe('0.600');
    expect($this->ingredientA2->fresh()->current_stock)->toBe('0.050');
    expect(InventoryMovement::count())->toBe(0);
    $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'open', 'sent_at' => null]);
});

test('different orders compete for the last stock without overdraw', function () {
    $first = waiterDraft();
    waiterLine($first, $this->menuA->id, 60);
    $second = waiterDraft();
    waiterLine($second, $this->menuA->id, 30);
    $this->postJson('/api/v1/waiter/orders/'.$first.'/confirm')->assertOk();
    expect($this->ingredientA->fresh()->current_stock)->toBe('2.500');
    $this->postJson('/api/v1/waiter/orders/'.$second.'/confirm')->assertUnprocessable()->assertJsonValidationErrors('inventory');
    expect($this->ingredientA->fresh()->current_stock)->toBe('2.500');
    expect(InventoryMovement::count())->toBe(1);
});

test('confirmation snapshots quoted price and station but consumes the current recipe and taxes', function () {
    $order = waiterDraft();
    $line = waiterLine($order, $this->menuA->id, 2);
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->update(['sale_price' => '99.00', 'preparation_station' => 'bar']);
    $this->menuA->ingredients()->firstOrFail()->update(['quantity' => '0.300']);
    $this->menuA->category->superCategory->taxClass->update(['percentage' => '20.00']);
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertOk()
        ->assertJsonPath('data.subtotal', '20.00')->assertJsonPath('data.tax_total', '4.00')
        ->assertJsonPath('data.items.0.unit_price', '10.00')->assertJsonPath('data.items.0.station', 'kitchen');
    expect($this->ingredientA->fresh()->current_stock)->toBe('9.400');
    $this->assertDatabaseHas('inventory_movements', ['order_item_id' => $line, 'quantity' => -0.600]);
});

test('disabled deleted menu or unavailable ingredients stop confirmation', function (string $state) {
    $order = waiterDraft();
    waiterLine($order, $this->menuA->id);
    $this->actingAs($this->adminA, 'sanctum');
    match ($state) {
        'inactive' => $this->menuA->update(['active' => false]),
        'deleted' => $this->menuA->delete(),
        'ingredient_deleted' => DB::table('inventory_items')->where('id', $this->ingredientA->id)->update(['deleted_at' => now()]),
        'foreign_ingredient' => DB::table('menu_item_ingredients')->where('menu_item_id', $this->menuA->id)->update(['inventory_item_id' => $this->ingredientB->id]),
        'invalid_recipe' => DB::table('menu_item_ingredients')->where('menu_item_id', $this->menuA->id)->update(['quantity' => -1]),
    };
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertUnprocessable();
    expect(InventoryMovement::count())->toBe(0);
    $this->assertDatabaseHas('inventory_items', ['id' => $this->ingredientB->id, 'current_stock' => 10]);
    $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'open']);
})->with(['inactive', 'deleted', 'ingredient_deleted', 'foreign_ingredient', 'invalid_recipe']);

test('any failure after stock or ledger writes rolls back the complete confirmation', function (string $failure) {
    $order = waiterDraft();
    waiterLine($order, $this->menuA->id);
    waiterLine($order, $this->menuA2->id);
    $dispatcher = Order::getEventDispatcher();
    Order::setEventDispatcher(clone $dispatcher);
    if ($failure === 'movement') {
        InventoryMovement::created(fn () => throw new RuntimeException('Ledger failed'));
    } else {
        Order::updated(function (Order $changed) {
            if ($changed->status === 'sent') {
                throw new RuntimeException('Order failed');
            }
        });
    }
    try {
        $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertServerError();
    } finally {
        Order::setEventDispatcher($dispatcher);
    }
    expect($this->ingredientA->fresh()->current_stock)->toBe('10.000');
    expect($this->ingredientA2->fresh()->current_stock)->toBe('10.000');
    expect(InventoryMovement::count())->toBe(0);
    $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'open', 'sent_at' => null, 'tax_total' => 0]);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertOk();
})->with(['movement', 'order']);

test('orders items menus and consumption records cannot cross restaurants or waiter ownership', function () {
    $this->actingAs($this->waiterB, 'sanctum');
    $foreign = waiterDraft();
    $foreignLine = waiterLine($foreign, $this->menuB->id);
    $this->actingAs($this->otherWaiter, 'sanctum');
    $colleague = waiterDraft();
    $colleagueLine = waiterLine($colleague, $this->menuA->id);
    $this->actingAs($this->waiterA, 'sanctum');
    $own = waiterDraft();
    $ownLine = waiterLine($own, $this->menuA->id);
    $this->getJson('/api/v1/waiter/orders?restaurant_id='.$this->restaurantB->id.'&waiter_employee_id='.$this->otherWaiter->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
    foreach ([[$foreign, $foreignLine], [$colleague, $colleagueLine]] as [$id, $item]) {
        $url = '/api/v1/waiter/orders/'.$id;
        $this->getJson($url)->assertNotFound();
        $this->postJson($url.'/confirm')->assertNotFound();
        $this->postJson($url.'/items', ['menu_item_id' => $this->menuA->id, 'quantity' => 1])->assertNotFound();
        $this->patchJson($url.'/items/'.$item, ['quantity' => 2])->assertNotFound();
        $this->deleteJson($url.'/items/'.$item)->assertNotFound();
    }
    $this->patchJson('/api/v1/waiter/orders/'.$own.'/items/'.$colleagueLine, ['quantity' => 2])->assertNotFound();
    $this->deleteJson('/api/v1/waiter/orders/'.$own.'/items/'.$foreignLine)->assertNotFound();
    $this->postJson('/api/v1/waiter/orders/'.$own.'/items', ['menu_item_id' => $this->menuB->id, 'quantity' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('menu_item_id');
    $this->postJson('/api/v1/waiter/orders/'.$own.'/confirm')->assertOk();
    expect(InventoryMovement::count())->toBe(1);
    $this->actingAs($this->waiterB, 'sanctum');
    expect(InventoryMovement::count())->toBe(0);
    expect($this->ingredientB->fresh()->current_stock)->toBe('10.000');
});

test('waiter menu exposes active local items and the legal age flag only', function () {
    $this->getJson('/api/v1/waiter/menu-items')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/waiter/menu-items/'.$this->menuA2->id)->assertOk()->assertJsonPath('data.legal_age_required', true);
    $this->getJson('/api/v1/waiter/menu-items/'.$this->menuB->id)->assertNotFound();
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->update(['active' => false]);
    $this->actingAs($this->waiterA, 'sanctum')->getJson('/api/v1/waiter/menu-items/'.$this->menuA->id)->assertNotFound();
    $order = waiterDraft();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/items', ['menu_item_id' => $this->menuA->id, 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('menu_item_id');
});

test('financial bounds roll back draft additions and tax rounding is exact', function () {
    $order = waiterDraft();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/items', ['menu_item_id' => $this->menuA->id, 'quantity' => 10000000])
        ->assertUnprocessable()->assertJsonValidationErrors('items');
    expect(OrderItem::count())->toBe(0);
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->update(['sale_price' => '0.05']);
    $this->menuA->ingredients()->firstOrFail()->delete();
    $this->actingAs($this->waiterA, 'sanctum');
    waiterLine($order, $this->menuA->id);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertOk()->assertJsonPath('data.tax_total', '0.01')->assertJsonPath('data.total', '0.06');
    expect(InventoryMovement::count())->toBe(0);
});

test('orders require an open local cash register and prevent its closure while unsettled', function () {
    $order = waiterDraft();
    $this->postJson('/api/v1/cash-registers/'.$this->cashA->id.'/close', ['counted_cash' => 100])->assertConflict();
    DB::table('cash_registers')->where('id', $this->cashA->id)->update(['closed_at' => now()]);
    $this->postJson('/api/v1/waiter/orders', ['service_type' => 'takeout'])->assertConflict();
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertConflict();
});

test('an exact millesimal stock balance can be consumed to zero', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->ingredientA->update(['current_stock' => '0.001']);
    $this->menuA->ingredients()->firstOrFail()->update(['quantity' => '0.001']);
    $this->actingAs($this->waiterA, 'sanctum');
    $order = waiterDraft();
    $line = waiterLine($order, $this->menuA->id);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertOk();
    expect($this->ingredientA->fresh()->current_stock)->toBe('0.000');
    $this->assertDatabaseHas('inventory_movements', ['order_item_id' => $line, 'quantity' => -0.001]);
});

test('quantity edits roll back if the recalculated draft total would overflow', function () {
    $order = waiterDraft();
    $line = waiterLine($order, $this->menuA->id);
    $this->patchJson('/api/v1/waiter/orders/'.$order.'/items/'.$line, ['quantity' => 10000000])
        ->assertUnprocessable()->assertJsonValidationErrors('items');
    $this->assertDatabaseHas('order_items', ['id' => $line, 'quantity' => 1]);
    $this->assertDatabaseHas('orders', ['id' => $order, 'subtotal' => 10]);
});

test('tax overflow at confirmation cannot deduct stock or commit the order', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->update(['sale_price' => '99999999.99']);
    $this->actingAs($this->waiterA, 'sanctum');
    $order = waiterDraft();
    waiterLine($order, $this->menuA->id);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertUnprocessable()->assertJsonValidationErrors('items');
    expect($this->ingredientA->fresh()->current_stock)->toBe('10.000');
    expect(InventoryMovement::count())->toBe(0);
    $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'open', 'sent_at' => null]);
});

test('direct model writes cannot assign foreign waiters registers or order parents', function () {
    $order = waiterDraft();
    $line = waiterLine($order, $this->menuA->id);
    $data = ['source' => 'pos', 'service_type' => 'takeout', 'waiter_employee_id' => $this->waiterA->id, 'cash_register_id' => $this->cashA->id];
    expect(fn () => Order::create(array_replace($data, ['waiter_employee_id' => $this->waiterB->id])))->toThrow(HttpException::class);
    expect(fn () => Order::create(array_replace($data, ['cash_register_id' => $this->cashB->id])))->toThrow(HttpException::class);
    $orderModel = Order::findOrFail($order);
    $item = OrderItem::findOrFail($line);
    $this->actingAs($this->waiterB, 'sanctum');
    expect(fn () => $orderModel->update(['total' => '0.00']))->toThrow(HttpException::class);
    expect(fn () => $item->update(['quantity' => 2]))->toThrow(HttpException::class);
    expect(fn () => $item->delete())->toThrow(HttpException::class);
});

test('consumption history remains scoped and fixes ingredient units after a recipe is removed', function () {
    $order = waiterDraft();
    $line = waiterLine($order, $this->menuA->id);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertOk();
    $movement = InventoryMovement::firstOrFail();
    expect(fn () => $movement->update(['quantity' => '-5.000']))->toThrow(HttpException::class);
    expect(fn () => $movement->delete())->toThrow(HttpException::class);
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->ingredients()->firstOrFail()->delete();
    $this->patchJson('/api/v1/admin/inventory-items/'.$this->ingredientA->id, ['unit' => 'g'])->assertConflict();
    $this->menuA2->ingredients()->where('inventory_item_id', $this->ingredientA->id)->firstOrFail()->delete();
    $this->ingredientA->delete();
    expect(InventoryMovement::count())->toBe(1);
    $this->actingAs($this->waiterB, 'sanctum');
    expect(InventoryMovement::count())->toBe(0);
    expect(fn () => InventoryMovement::create(['inventory_item_id' => $this->ingredientB->id, 'order_item_id' => $line, 'quantity' => '-1.000']))->toThrow(HttpException::class);
    auth()->forgetGuards();
    expect(fn () => Order::count())->toThrow(HttpException::class);
    expect(fn () => OrderItem::count())->toThrow(HttpException::class);
    expect(fn () => InventoryMovement::count())->toThrow(HttpException::class);
});

test('confirmation accepts no client totals inventory operations or state', function (string $field) {
    $order = waiterDraft();
    waiterLine($order, $this->menuA->id);
    $this->postJson('/api/v1/waiter/orders/'.$order.'/confirm', [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(InventoryMovement::count())->toBe(0);
})->with(['status', 'sent_at', 'total', 'cash_register_id', 'items', 'inventory_movements']);

test('only authenticated waiters can operate these routes', function (string $role) {
    $order = waiterDraft();
    $line = waiterLine($order, $this->menuA->id);
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
    $url = '/api/v1/waiter/orders';
    $this->getJson('/api/v1/waiter/menu-items')->assertStatus($status);
    $this->getJson($url)->assertStatus($status);
    $this->getJson($url.'/'.$order)->assertStatus($status);
    $this->postJson($url, ['service_type' => 'takeout'])->assertStatus($status);
    $this->postJson($url.'/'.$order.'/items', ['menu_item_id' => $this->menuA->id, 'quantity' => 1])->assertStatus($status);
    $this->patchJson($url.'/'.$order.'/items/'.$line, ['quantity' => 2])->assertStatus($status);
    $this->deleteJson($url.'/'.$order.'/items/'.$line)->assertStatus($status);
    $this->postJson($url.'/'.$order.'/confirm')->assertStatus($status);
})->with(['guest', 'admin', 'chef', 'customer', 'super_admin']);

test('suspension or deactivation blocks confirmation with previously issued tokens', function (string $condition) {
    $order = waiterDraft();
    waiterLine($order, $this->menuA->id);
    $token = $this->waiterA->createToken('test')->plainTextToken;
    auth()->forgetGuards();
    if ($condition === 'suspended') {
        $this->restaurantA->update(['status' => 'suspended']);
    } else {
        $this->waiterA->forceFill(['active' => false])->save();
    }
    $this->withToken($token)->postJson('/api/v1/waiter/orders/'.$order.'/confirm')->assertForbidden();
})->with(['suspended', 'inactive']);
