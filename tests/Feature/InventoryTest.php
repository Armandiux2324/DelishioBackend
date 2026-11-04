<?php

use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryLoss;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function inventoryEmployee(Restaurant $restaurant, string $role = 'admin'): User
{
    $user = User::factory()->create(['user_type' => 'employee']);
    app(RestaurantContext::class)->run($restaurant, fn () => Employee::create([
        'user_id' => $user->id, 'role' => $role, 'hourly_rate' => 100, 'pin' => '123456',
    ]));

    return $user->refresh();
}

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->adminA = inventoryEmployee($this->restaurantA);
    $this->adminB = inventoryEmployee($this->restaurantB);
    $this->itemA = app(RestaurantContext::class)->run($this->restaurantA, fn () => InventoryItem::factory()->create(['current_stock' => '0.300']));
    $this->itemB = app(RestaurantContext::class)->run($this->restaurantB, fn () => InventoryItem::factory()->create());
    $this->payload = ['name' => 'Harina', 'unit' => 'kg', 'unit_cost' => '12.3456', 'current_stock' => '15.125'];
    $this->loss = ['inventory_item_id' => $this->itemA->id, 'quantity' => '0.100', 'reason' => 'Producto caducado'];
});

test('administrators manage ingredients and absolute stock', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $response = $this->postJson('/api/v1/admin/inventory-items', $this->payload)->assertCreated()
        ->assertJsonPath('data.restaurant_id', $this->restaurantA->id)
        ->assertJsonPath('data.unit_cost', '12.3456')->assertJsonPath('data.current_stock', '15.125');
    $url = '/api/v1/admin/inventory-items/'.$response->json('data.id');
    $this->getJson($url)->assertOk()->assertJsonPath('data.name', 'Harina');
    $this->putJson($url, ['current_stock' => '0.001'])->assertOk()->assertJsonPath('data.current_stock', '0.001');
    $this->patchJson($url, ['unit' => 'g', 'unit_cost' => '0'])->assertOk()->assertJsonPath('data.unit_cost', '0.0000');
    $this->getJson('/api/v1/admin/inventory-items')->assertOk()->assertJsonPath('meta.total', 2);
    $this->deleteJson($url)->assertNoContent();
    $this->assertSoftDeleted('inventory_items', ['id' => $response->json('data.id')]);
    $this->getJson($url)->assertNotFound();
});

test('DBML units are supported', function (string $unit) {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/inventory-items', array_replace($this->payload, ['unit' => $unit]))->assertCreated()->assertJsonPath('data.unit', $unit);
})->with(['g', 'kg', 'oz', 'lb', 'ml', 'l', 'fl_oz', 'gal', 'pza']);

test('ingredient validation enforces bounds precision and owned fields', function (string $field, mixed $value) {
    $this->actingAs($this->adminA, 'sanctum');
    $this->postJson('/api/v1/admin/inventory-items', array_replace($this->payload, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->patchJson('/api/v1/admin/inventory-items/'.$this->itemA->id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['name', ''], ['name', str_repeat('a', 256)], ['unit', 'litros'], ['unit', null],
    ['unit_cost', '-0.0001'], ['unit_cost', '1000000'], ['unit_cost', '1.12345'],
    ['current_stock', '-0.001'], ['current_stock', '1000000000'], ['current_stock', '0.0001'],
    ['restaurant_id', null], ['id', 100], ['deleted_at', null],
]);

test('ingredient creation requires every specified field', function () {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/inventory-items', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'unit', 'unit_cost', 'current_stock']);
});

test('ingredient operations isolate restaurants', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $url = '/api/v1/admin/inventory-items';
    $this->getJson($url.'?restaurant_id='.$this->restaurantB->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->itemA->id);
    $this->getJson($url.'/'.$this->itemB->id)->assertNotFound();
    $this->patchJson($url.'/'.$this->itemB->id, ['current_stock' => '0'])->assertNotFound();
    $this->deleteJson($url.'/'.$this->itemB->id)->assertNotFound();
    $this->assertDatabaseHas('inventory_items', ['id' => $this->itemB->id, 'current_stock' => 10, 'deleted_at' => null]);
});

test('losses subtract exact quantities and derive the authenticated recorder', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $response = $this->postJson('/api/v1/admin/inventory-losses', $this->loss)->assertCreated()->assertJsonPath('data.quantity', '0.100')->assertJsonPath('data.registered_by_employee_id', $this->adminA->id);
    $this->postJson('/api/v1/admin/inventory-losses', array_replace($this->loss, ['quantity' => '0.200']))->assertCreated();
    expect($this->itemA->fresh()->current_stock)->toBe('0.000');
    $this->postJson('/api/v1/admin/inventory-losses', $this->loss)->assertUnprocessable()->assertJsonValidationErrors('quantity');
    expect(InventoryLoss::count())->toBe(2);
    $url = '/api/v1/admin/inventory-losses/'.$response->json('data.id');
    $this->getJson($url)->assertOk();
    $this->patchJson($url, ['quantity' => '1'])->assertMethodNotAllowed();
    $this->deleteJson($url)->assertMethodNotAllowed();
});

test('loss insert failure rolls back stock deduction', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $dispatcher = InventoryLoss::getEventDispatcher();
    InventoryLoss::setEventDispatcher(clone $dispatcher);
    InventoryLoss::creating(fn () => throw new RuntimeException('Insert failed'));
    try {
        $this->withoutExceptionHandling();
        expect(fn () => $this->postJson('/api/v1/admin/inventory-losses', $this->loss))->toThrow(RuntimeException::class, 'Insert failed');
    } finally {
        InventoryLoss::setEventDispatcher($dispatcher);
    }
    expect($this->itemA->fresh()->current_stock)->toBe('0.300');
    expect(InventoryLoss::count())->toBe(0);
});

test('loss validation rejects invalid quantities reasons and forged metadata', function (string $field, mixed $value) {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/inventory-losses', array_replace($this->loss, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->itemA->fresh()->current_stock)->toBe('0.300');
    expect(InventoryLoss::count())->toBe(0);
})->with([
    ['quantity', '0'], ['quantity', '-1'], ['quantity', '0.0001'], ['quantity', '1000000000'], ['quantity', '0.301'],
    ['reason', ''], ['reason', str_repeat('ñ', 32768)], ['inventory_item_id', 999999],
    ['registered_by_employee_id', null], ['restaurant_id', 1], ['created_at', null], ['id', 1],
]);

test('loss history and parent references isolate restaurants after soft deletion', function () {
    $this->actingAs($this->adminB, 'sanctum');
    $foreign = $this->postJson('/api/v1/admin/inventory-losses', array_replace($this->loss, ['inventory_item_id' => $this->itemB->id]))->assertCreated()->json('data.id');
    $this->actingAs($this->adminA, 'sanctum');
    $own = $this->postJson('/api/v1/admin/inventory-losses', $this->loss)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/admin/inventory-losses', array_replace($this->loss, ['inventory_item_id' => $this->itemB->id]))->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
    $this->getJson('/api/v1/admin/inventory-losses/'.$foreign)->assertNotFound();
    $this->getJson('/api/v1/admin/inventory-losses?restaurant_id='.$this->restaurantB->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
    $this->deleteJson('/api/v1/admin/inventory-items/'.$this->itemA->id)->assertNoContent();
    $this->getJson('/api/v1/admin/inventory-losses/'.$own)->assertOk();
    $this->getJson('/api/v1/admin/inventory-losses')->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/admin/inventory-losses', $this->loss)->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
    $this->actingAs($this->adminB, 'sanctum')->getJson('/api/v1/admin/inventory-losses/'.$own)->assertNotFound();
});

test('units cannot change after losses but metadata can', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->postJson('/api/v1/admin/inventory-losses', $this->loss)->assertCreated();
    $url = '/api/v1/admin/inventory-items/'.$this->itemA->id;
    $this->patchJson($url, ['unit' => 'g'])->assertConflict();
    $this->patchJson($url, ['unit' => 'kg', 'name' => 'Nuevo nombre', 'unit_cost' => '99.9999'])->assertOk();
});

test('inventory requires authentication and administrator role', function (string $role) {
    $loss = app(RestaurantContext::class)->run($this->restaurantA, fn () => InventoryLoss::factory()->create([
        'inventory_item_id' => $this->itemA->id, 'registered_by_employee_id' => $this->adminA->id,
    ]));
    if ($role !== 'guest') {
        $user = in_array($role, ['waiter', 'chef']) ? inventoryEmployee($this->restaurantA, $role) : User::factory()->create(['user_type' => $role]);
        $this->actingAs($user, 'sanctum');
    }
    $status = $role === 'guest' ? 401 : 403;
    $this->getJson('/api/v1/admin/inventory-items')->assertStatus($status);
    $this->postJson('/api/v1/admin/inventory-items', $this->payload)->assertStatus($status);
    $this->getJson('/api/v1/admin/inventory-items/'.$this->itemA->id)->assertStatus($status);
    $this->patchJson('/api/v1/admin/inventory-items/'.$this->itemA->id, ['current_stock' => '0'])->assertStatus($status);
    $this->deleteJson('/api/v1/admin/inventory-items/'.$this->itemA->id)->assertStatus($status);
    $this->getJson('/api/v1/admin/inventory-losses')->assertStatus($status);
    $this->getJson('/api/v1/admin/inventory-losses/'.$loss->id)->assertStatus($status);
    $this->postJson('/api/v1/admin/inventory-losses', $this->loss)->assertStatus($status);
})->with(['guest', 'waiter', 'chef', 'customer', 'super_admin']);

test('existing tokens cannot access suspended restaurant inventory', function () {
    $token = $this->adminA->createToken('inventory')->plainTextToken;
    $this->restaurantA->update(['status' => 'suspended']);
    $this->withToken($token)->getJson('/api/v1/admin/inventory-items')->assertForbidden();
    $this->postJson('/api/v1/admin/inventory-losses', $this->loss)->assertForbidden();
});

test('inventory models fail closed without tenant context', function () {
    expect(fn () => InventoryItem::count())->toThrow(HttpException::class);
    expect(fn () => InventoryLoss::count())->toThrow(HttpException::class);
});

test('direct loss writes cannot reference foreign ingredients or employees', function () {
    $this->actingAs($this->adminA, 'sanctum');
    expect(fn () => InventoryLoss::create($this->loss + ['registered_by_employee_id' => $this->adminB->id]))
        ->toThrow(HttpException::class);
    expect(fn () => InventoryLoss::create(array_replace($this->loss, ['inventory_item_id' => $this->itemB->id, 'registered_by_employee_id' => $this->adminA->id])))
        ->toThrow(HttpException::class);
    $loss = InventoryLoss::create($this->loss + ['registered_by_employee_id' => $this->adminA->id]);
    $this->actingAs($this->adminB, 'sanctum');
    expect(fn () => $loss->update(['reason' => 'Ataque']))->toThrow(HttpException::class);
    expect(fn () => $loss->delete())->toThrow(HttpException::class);
});

test('DBML maximum costs and quantities are accepted', function () {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/inventory-items', array_replace($this->payload, [
        'unit_cost' => '999999.9999', 'current_stock' => '999999999.999',
    ]))->assertCreated()->assertJsonPath('data.unit_cost', '999999.9999')->assertJsonPath('data.current_stock', '999999999.999');
});
