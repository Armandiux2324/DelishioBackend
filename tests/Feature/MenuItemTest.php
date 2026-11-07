<?php

use App\Models\Category;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\MenuAuditLog;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function menuEmployee(Restaurant $restaurant, string $role = 'admin'): User
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
    $this->adminA = menuEmployee($this->restaurantA);
    $this->adminB = menuEmployee($this->restaurantB);
    [$this->categoryA, $this->ingredientA, $this->ingredientA2] = app(RestaurantContext::class)->run($this->restaurantA,
        fn () => [Category::factory()->create(), InventoryItem::factory()->create(), InventoryItem::factory()->create()]
    );
    [$this->categoryB, $this->ingredientB] = app(RestaurantContext::class)->run($this->restaurantB,
        fn () => [Category::factory()->create(), InventoryItem::factory()->create()]
    );
    $this->payload = [
        'category_id' => $this->categoryA->id, 'name' => 'Margarita', 'sale_price' => '125.50',
        'description' => 'Con limón', 'image_url' => 'https://delishio.com/menu/margarita.jpg',
        'legal_age_required' => true, 'preparation_station' => 'bar', 'active' => true,
        'ingredients' => [['inventory_item_id' => $this->ingredientA->id, 'quantity' => '0.125']],
    ];
});

test('administrators create consult update and soft delete menu items with recipes', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $response = $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertCreated()
        ->assertJsonPath('data.sale_price', '125.50')->assertJsonPath('data.legal_age_required', true)
        ->assertJsonPath('data.preparation_station', 'bar')->assertJsonPath('data.ingredients.0.quantity', '0.125');
    $id = $response->json('data.id');
    $url = '/api/v1/admin/menu-items/'.$id;
    $this->getJson($url)->assertOk()->assertJsonPath('data.name', 'Margarita');
    $this->getJson('/api/v1/admin/menu-items')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    $this->putJson($url, ['name' => 'Margarita especial', 'ingredients' => [
        ['inventory_item_id' => $this->ingredientA2->id, 'quantity' => '1.001'],
    ]])->assertOk()->assertJsonPath('data.ingredients.0.inventory_item_id', $this->ingredientA2->id);
    $this->assertDatabaseMissing('menu_item_ingredients', ['menu_item_id' => $id, 'inventory_item_id' => $this->ingredientA->id]);
    $this->assertDatabaseHas('menu_item_ingredients', ['menu_item_id' => $id, 'inventory_item_id' => $this->ingredientA2->id, 'quantity' => 1.001]);
    $this->putJson($url, ['sale_price' => '99.99', 'description' => null, 'image_url' => null])->assertOk()
        ->assertJsonPath('data.sale_price', '99.99')->assertJsonPath('data.description', null)->assertJsonCount(1, 'data.ingredients');
    $this->deleteJson($url)->assertNoContent();
    $this->assertSoftDeleted('menu_items', ['id' => $id]);
    $this->getJson($url)->assertNotFound();
    $this->getJson('/api/v1/admin/menu-items')->assertJsonCount(0, 'data');
    expect($this->ingredientA->fresh()->current_stock)->toBe('10.000');
    expect($this->ingredientA2->fresh()->current_stock)->toBe('10.000');
});

test('minimal menu items use DBML defaults and support empty recipes', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->postJson('/api/v1/admin/menu-items', [
        'category_id' => $this->categoryA->id, 'name' => 'Agua', 'sale_price' => '0',
    ])->assertCreated()->assertJsonPath('data.active', true)->assertJsonPath('data.legal_age_required', false)
        ->assertJsonPath('data.preparation_station', 'none')->assertJsonPath('data.sale_price', '0.00')
        ->assertJsonCount(0, 'data.ingredients')->json('data.id');
    $create = MenuAuditLog::where('menu_item_id', $id)->where('action', 'create')->firstOrFail();
    expect(json_decode($create->new_value, true))->toMatchArray(['active' => true, 'legal_age_required' => false, 'preparation_station' => 'none']);
});

test('all DBML preparation stations are accepted', function (string $station) {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/menu-items', array_replace($this->payload, ['preparation_station' => $station]))
        ->assertCreated()->assertJsonPath('data.preparation_station', $station);
})->with(['none', 'kitchen', 'bar']);

test('menu creation requires the mandatory DBML fields', function () {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/menu-items', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'name', 'sale_price']);
});

test('menu validation rejects invalid scalar values and forged identifiers', function (string $field, mixed $value) {
    $this->actingAs($this->adminA, 'sanctum');
    $item = MenuItem::factory()->create(['category_id' => $this->categoryA->id]);
    $this->postJson('/api/v1/admin/menu-items', array_replace($this->payload, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->putJson('/api/v1/admin/menu-items/'.$item->id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($item->fresh()->sale_price)->toBe('12.50');
})->with([
    ['name', ''], ['name', str_repeat('a', 256)], ['sale_price', '-0.01'], ['sale_price', '100000000'],
    ['sale_price', '1.001'], ['sale_price', null], ['category_id', 999999], ['category_id', null],
    ['description', str_repeat('ñ', 32768)], ['image_url', 'javascript:alert(1)'], ['image_url', 'https://delishio.com/'.str_repeat('x', 255)],
    ['legal_age_required', null], ['legal_age_required', 'yes'], ['active', null], ['active', 'yes'],
    ['preparation_station', 'Cocina'], ['preparation_station', null], ['id', null], ['restaurant_id', 1],
    ['created_at', null], ['updated_at', null], ['deleted_at', null], ['ingredients', null], ['ingredients', 'invalid'],
]);

test('recipe validation requires unique existing ingredients and positive exact quantities', function (array $recipe, string $field) {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->ingredientA->id;
    $recipe = array_map(fn ($row) => array_replace(['inventory_item_id' => $id], $row), $recipe);
    $this->postJson('/api/v1/admin/menu-items', array_replace($this->payload, ['ingredients' => $recipe]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(MenuItem::count())->toBe(0);
    expect(MenuAuditLog::count())->toBe(0);
})->with([
    [[['quantity' => '0']], 'ingredients.0.quantity'],
    [[['quantity' => '-1']], 'ingredients.0.quantity'],
    [[['quantity' => '0.0001']], 'ingredients.0.quantity'],
    [[['quantity' => '1000000000']], 'ingredients.0.quantity'],
    [[[]], 'ingredients.0.quantity'],
    [[['inventory_item_id' => null, 'quantity' => '1']], 'ingredients.0.inventory_item_id'],
    [[['inventory_item_id' => 999999, 'quantity' => '1']], 'ingredients.0.inventory_item_id'],
    [[['quantity' => '1'], ['quantity' => '2']], 'ingredients.0.inventory_item_id'],
    [[['quantity' => '1', 'menu_item_id' => 999]], 'ingredients.0'],
]);

test('DBML maximum price and recipe quantity are accepted without requiring stock', function () {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/menu-items', array_replace($this->payload, [
        'sale_price' => '99999999.99', 'ingredients' => [['inventory_item_id' => $this->ingredientA->id, 'quantity' => '999999999.999']],
    ]))->assertCreated()->assertJsonPath('data.sale_price', '99999999.99')->assertJsonPath('data.ingredients.0.quantity', '999999999.999');
});

test('menu routes and recipe references isolate restaurants', function () {
    $this->actingAs($this->adminB, 'sanctum');
    $foreign = MenuItem::factory()->create(['category_id' => $this->categoryB->id, 'name' => 'Privado B']);
    $this->actingAs($this->adminA, 'sanctum');
    $own = $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertCreated()->json('data.id');
    $url = '/api/v1/admin/menu-items';
    $this->getJson($url.'?restaurant_id='.$this->restaurantB->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
    $this->getJson($url.'/'.$foreign->id)->assertNotFound();
    $this->putJson($url.'/'.$foreign->id, ['name' => 'Ataque', 'ingredients' => []])->assertNotFound();
    $this->deleteJson($url.'/'.$foreign->id)->assertNotFound();
    $this->postJson($url, array_replace($this->payload, ['category_id' => $this->categoryB->id]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->putJson($url.'/'.$own, ['category_id' => $this->categoryB->id])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->putJson($url.'/'.$own, ['ingredients' => [['inventory_item_id' => $this->ingredientB->id, 'quantity' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('ingredients.0.inventory_item_id');
    $this->assertDatabaseHas('menu_items', ['id' => $foreign->id, 'name' => 'Privado B', 'deleted_at' => null]);
    expect(MenuAuditLog::where('menu_item_id', $foreign->id)->count())->toBe(0);
    $this->actingAs($this->adminB, 'sanctum')->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id);
});

test('deleted categories and ingredients cannot be assigned', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->categoryA->delete();
    $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $category = Category::factory()->create();
    $this->ingredientA->delete();
    $this->postJson('/api/v1/admin/menu-items', array_replace($this->payload, ['category_id' => $category->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('ingredients.0.inventory_item_id');
});

test('recipe replacement updates quantities removes ingredients and preserves omitted recipes', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertCreated()->json('data.id');
    $url = '/api/v1/admin/menu-items/'.$id;
    $this->putJson($url, ['ingredients' => [
        ['inventory_item_id' => $this->ingredientA->id, 'quantity' => '0.001'],
        ['inventory_item_id' => $this->ingredientA2->id, 'quantity' => '2'],
    ]])->assertOk()->assertJsonCount(2, 'data.ingredients')->assertJsonPath('data.ingredients.0.quantity', '0.001');
    $this->putJson($url, ['name' => 'Otro'])->assertOk()->assertJsonCount(2, 'data.ingredients');
    $this->putJson($url, ['ingredients' => []])->assertOk()->assertJsonCount(0, 'data.ingredients');
    expect(MenuItemIngredient::count())->toBe(0);
    $quantityLog = MenuAuditLog::where('menu_item_id', $id)->where('action', 'edit')->get()
        ->first(fn ($log) => ($value = json_decode($log->new_value ?? 'null', true)) && ($value['ingredients']['quantity'] ?? null) === '0.001');
    expect(json_decode($quantityLog->old_value, true)['ingredients']['quantity'])->toBe('0.125');
});

test('observers audit each action with previous values and actual employee', function () {
    $this->travelTo(now()->startOfSecond());
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertCreated()->json('data.id');
    $url = '/api/v1/admin/menu-items/'.$id;
    $this->putJson($url, ['sale_price' => '150.00', 'name' => 'Nuevo nombre', 'active' => false])->assertOk();
    $this->putJson($url, ['active' => true])->assertOk();
    $before = MenuAuditLog::count();
    $this->putJson($url, ['active' => true, 'sale_price' => '150.00', 'ingredients' => $this->payload['ingredients']])->assertOk();
    expect(MenuAuditLog::count())->toBe($before);
    $this->deleteJson($url)->assertNoContent();
    $logs = MenuAuditLog::where('menu_item_id', $id)->get();
    expect($logs->pluck('action')->unique()->sort()->values()->all())->toBe(['activate', 'create', 'deactivate', 'delete', 'edit', 'price_change']);
    expect($logs->pluck('employee_id')->unique()->all())->toBe([$this->adminA->id]);
    $price = $logs->firstWhere('action', 'price_change');
    expect(json_decode($price->old_value, true))->toBe(['sale_price' => '125.50']);
    expect(json_decode($price->new_value, true))->toBe(['sale_price' => '150.00']);
    expect($logs->firstWhere('action', 'create')->created_at->equalTo(now()))->toBeTrue();
    expect($logs->firstWhere('action', 'delete')->new_value)->toBeNull();
    expect($this->ingredientA->fresh()->current_stock)->toBe('10.000');
});

test('catalog and inventory protect recipe relationships and audit history survives ancestor deletion', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertCreated()->json('data.id');
    $this->deleteJson('/api/v1/admin/categories/'.$this->categoryA->id)->assertConflict();
    $this->deleteJson('/api/v1/admin/inventory-items/'.$this->ingredientA->id)->assertConflict();
    $this->putJson('/api/v1/admin/inventory-items/'.$this->ingredientA->id, ['unit' => 'g'])->assertConflict();
    $super = $this->categoryA->superCategory;
    $this->deleteJson('/api/v1/admin/menu-items/'.$id)->assertNoContent();
    $this->deleteJson('/api/v1/admin/categories/'.$this->categoryA->id)->assertNoContent();
    $this->deleteJson('/api/v1/admin/super-categories/'.$super->id)->assertNoContent();
    $this->deleteJson('/api/v1/admin/inventory-items/'.$this->ingredientA->id)->assertNoContent();
    expect(MenuAuditLog::where('menu_item_id', $id)->count())->toBe(3);
    $this->actingAs($this->adminB, 'sanctum');
    expect(MenuAuditLog::where('menu_item_id', $id)->count())->toBe(0);
});

test('audit failure rolls back menu metadata recipe and previous audit writes', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertCreated()->json('data.id');
    $dispatcher = MenuAuditLog::getEventDispatcher();
    MenuAuditLog::setEventDispatcher(clone $dispatcher);
    MenuAuditLog::creating(function (MenuAuditLog $log) {
        if (str_contains($log->new_value ?? '', 'ingredients')) {
            throw new RuntimeException('Audit unavailable');
        }
    });
    try {
        $this->putJson('/api/v1/admin/menu-items/'.$id, ['name' => 'No guardar', 'ingredients' => [
            ['inventory_item_id' => $this->ingredientA->id, 'quantity' => '1'],
        ]])->assertServerError();
    } finally {
        MenuAuditLog::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseHas('menu_items', ['id' => $id, 'name' => 'Margarita']);
    $this->assertDatabaseHas('menu_item_ingredients', ['menu_item_id' => $id, 'quantity' => 0.125]);
    expect(MenuAuditLog::count())->toBe(2);
});

test('audit text capacity is validated before committing data', function () {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/menu-items', array_replace($this->payload, ['description' => str_repeat('"', 40000)]))
        ->assertUnprocessable()->assertJsonValidationErrors('description');
    expect(MenuItem::count())->toBe(0);
    expect(MenuAuditLog::count())->toBe(0);
});

test('combined edits cannot create a snapshot too large to audit on deletion', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertCreated()->json('data.id');
    $this->putJson('/api/v1/admin/menu-items/'.$id, [
        'description' => str_repeat('x', 65400), 'name' => str_repeat('x', 255),
    ])->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->assertDatabaseHas('menu_items', ['id' => $id, 'name' => 'Margarita', 'description' => 'Con limón']);
    $this->deleteJson('/api/v1/admin/menu-items/'.$id)->assertNoContent();
});

test('menu operations require authentication and admin role', function (string $role) {
    $this->actingAs($this->adminA, 'sanctum');
    $item = MenuItem::factory()->create(['category_id' => $this->categoryA->id]);
    if ($role === 'guest') {
        auth()->forgetGuards();
    } else {
        $user = in_array($role, ['waiter', 'chef']) ? menuEmployee($this->restaurantA, $role) : User::factory()->create(['user_type' => $role]);
        $this->actingAs($user, 'sanctum');
    }
    $status = $role === 'guest' ? 401 : 403;
    $url = '/api/v1/admin/menu-items';
    $this->getJson($url)->assertStatus($status);
    $this->postJson($url, $this->payload)->assertStatus($status);
    $this->getJson($url.'/'.$item->id)->assertStatus($status);
    $this->putJson($url.'/'.$item->id, ['name' => 'No'])->assertStatus($status);
    $this->deleteJson($url.'/'.$item->id)->assertStatus($status);
})->with(['guest', 'waiter', 'chef', 'customer', 'super_admin']);

test('existing tokens lose menu access when restaurant is suspended', function () {
    $token = $this->adminA->createToken('menu')->plainTextToken;
    $this->restaurantA->update(['status' => 'suspended']);
    $this->withToken($token)->getJson('/api/v1/admin/menu-items')->assertForbidden();
    $this->postJson('/api/v1/admin/menu-items', $this->payload)->assertForbidden();
});

test('menu recipe and audit models fail closed without restaurant context', function () {
    expect(fn () => MenuItem::count())->toThrow(HttpException::class);
    expect(fn () => MenuItemIngredient::count())->toThrow(HttpException::class);
    expect(fn () => MenuAuditLog::count())->toThrow(HttpException::class);
});

test('direct model writes enforce restaurant ownership and audit records cannot be edited', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $item = MenuItem::factory()->create(['category_id' => $this->categoryA->id]);
    $ingredient = $item->ingredients()->create(['inventory_item_id' => $this->ingredientA->id, 'quantity' => '1']);
    $log = $item->auditLogs()->firstOrFail();
    expect(fn () => $item->ingredients()->create(['inventory_item_id' => $this->ingredientB->id, 'quantity' => '1']))->toThrow(HttpException::class);
    expect(fn () => $item->update(['category_id' => $this->categoryB->id]))->toThrow(HttpException::class);
    $item->refresh();
    expect(fn () => $log->update(['action' => 'delete']))->toThrow(HttpException::class);
    expect(fn () => $log->delete())->toThrow(HttpException::class);
    $this->actingAs($this->adminB, 'sanctum');
    expect(MenuItemIngredient::count())->toBe(0);
    expect(fn () => $item->update(['name' => 'Ataque']))->toThrow(HttpException::class);
    expect(fn () => $item->delete())->toThrow(HttpException::class);
    expect(fn () => $ingredient->update(['quantity' => '2']))->toThrow(HttpException::class);
    expect(fn () => $ingredient->delete())->toThrow(HttpException::class);
});
