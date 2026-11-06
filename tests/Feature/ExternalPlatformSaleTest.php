<?php

use App\Models\Employee;
use App\Models\ExternalPlatformSale;
use App\Models\ExternalPlatformSaleItem;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->super = User::factory()->create(['user_type' => 'super_admin'])->refresh();
    $this->a = Restaurant::create(['name' => 'A', 'created_by_user_id' => $this->super->id]);
    $this->b = Restaurant::create(['name' => 'B', 'created_by_user_id' => $this->super->id]);
    $this->adminA = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->waiterA = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'waiter']))->user;
    $this->adminB = app(RestaurantContext::class)->run($this->b, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->chef = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'chef']))->user;
    $this->actingAs($this->adminB, 'sanctum');
    $this->menuB = MenuItem::factory()->create();
    $this->foreignSale = ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $this->adminB->id]);
    $this->foreignItem = ExternalPlatformSaleItem::factory()->create(['external_sale_id' => $this->foreignSale->id, 'menu_item_id' => $this->menuB->id]);
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA = MenuItem::factory()->create(['sale_price' => '50.00']);
    $this->payload = ['platform' => 'doordash', 'total_amount' => '100.05', 'commission_amount' => '15.03',
        'items' => [
            ['menu_item_id' => $this->menuA->id, 'description' => 'Platillo vendido', 'quantity' => 2, 'unit_price' => '40.00'],
            ['description' => 'Extra de la plataforma', 'quantity' => 1, 'unit_price' => '20.05'],
        ]];
});

test('administrators and waiters register external sales with exact net amounts and automatic ownership', function (string $actor) {
    $this->travelTo(now()->startOfSecond());
    $this->actingAs($this->{$actor}, 'sanctum');
    $response = $this->postJson('/api/v1/external-platform-sales', $this->payload)->assertCreated()
        ->assertJsonPath('data.total_amount', '100.05')->assertJsonPath('data.commission_amount', '15.03')
        ->assertJsonPath('data.net_amount', '85.02')->assertJsonPath('data.registered_by_employee_id', $this->{$actor}->id)
        ->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.unit_price', '40.00')
        ->assertJsonPath('data.items.1.menu_item_id', null);
    $id = $response->json('data.id');
    $this->assertDatabaseHas('external_platform_sales', ['id' => $id, 'restaurant_id' => $this->a->id,
        'sale_date' => now()->format('Y-m-d H:i:s'), 'registered_by_employee_id' => $this->{$actor}->id]);
    $this->assertDatabaseHas('external_platform_sale_items', ['external_sale_id' => $id, 'menu_item_id' => $this->menuA->id, 'quantity' => 2]);
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0)->and(InventoryMovement::count())->toBe(0);
    $this->getJson('/api/v1/external-platform-sales/'.$id)->assertOk()->assertJsonPath('data.net_amount', '85.02');
    $this->patchJson('/api/v1/external-platform-sales/'.$id, ['total_amount' => 1])->assertMethodNotAllowed();
    $this->deleteJson('/api/v1/external-platform-sales/'.$id)->assertMethodNotAllowed();
})->with(['adminA', 'waiterA']);

test('all database platforms and commission boundaries are supported', function (string $platform, string $commission, string $net) {
    $this->postJson('/api/v1/external-platform-sales', array_replace($this->payload,
        ['platform' => $platform, 'total_amount' => '0.10', 'commission_amount' => $commission]))
        ->assertCreated()->assertJsonPath('data.platform', $platform)->assertJsonPath('data.net_amount', $net);
})->with([
    ['doordash', '0.00', '0.10'], ['ubereats', '0.03', '0.07'], ['didi', '0.10', '0.00'], ['rappi', '0.01', '0.09'],
]);

test('a supplied sale date is retained and the list is paginated in descending date order', function () {
    $this->postJson('/api/v1/external-platform-sales', $this->payload + ['sale_date' => '2026-10-01 12:30:00'])->assertCreated();
    ExternalPlatformSale::factory()->count(20)->create(['registered_by_employee_id' => $this->adminA->id, 'sale_date' => '2026-10-02 12:00:00']);
    $this->getJson('/api/v1/external-platform-sales')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21);
    $this->getJson('/api/v1/external-platform-sales?page=2')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.items.0.description', 'Platillo vendido');
});

test('sales and their items are isolated across restaurants for both allowed roles', function (string $actor) {
    $this->actingAs($this->{$actor}, 'sanctum');
    $id = $this->postJson('/api/v1/external-platform-sales', $this->payload)->assertCreated()->json('data.id');
    $this->getJson('/api/v1/external-platform-sales?restaurant_id='.$this->b->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson('/api/v1/external-platform-sales/'.$this->foreignSale->id)->assertNotFound();
    expect(ExternalPlatformSale::count())->toBe(1)->and(ExternalPlatformSaleItem::count())->toBe(2)
        ->and(ExternalPlatformSaleItem::find($this->foreignItem->id))->toBeNull();
    $this->actingAs($this->adminB, 'sanctum');
    $this->getJson('/api/v1/external-platform-sales/'.$id)->assertNotFound();
    $this->getJson('/api/v1/external-platform-sales')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->foreignSale->id);
})->with(['adminA', 'waiterA']);

test('foreign or deleted menu references cannot be registered', function (string $case) {
    $menuId = $this->menuB->id;
    if ($case === 'deleted') {
        $this->menuA->delete();
        $menuId = $this->menuA->id;
    }
    $payload = $this->payload;
    $payload['items'][0]['menu_item_id'] = $menuId;
    $this->postJson('/api/v1/external-platform-sales', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.menu_item_id');
    expect(ExternalPlatformSale::count())->toBe(0);
})->with(['foreign', 'deleted']);

test('the historical description and price survive menu edits and soft deletion', function () {
    $this->menuA->update(['active' => false]);
    $id = $this->postJson('/api/v1/external-platform-sales', $this->payload)->assertCreated()->json('data.id');
    $this->menuA->update(['name' => 'Nombre nuevo', 'sale_price' => '999.00']);
    $this->menuA->delete();
    $this->getJson('/api/v1/external-platform-sales/'.$id)->assertOk()->assertJsonPath('data.items.0.description', 'Platillo vendido')
        ->assertJsonPath('data.items.0.unit_price', '40.00')->assertJsonCount(2, 'data.items');
});

test('guests cannot access any external sale endpoint', function () {
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/external-platform-sales')->assertUnauthorized();
    $this->getJson('/api/v1/external-platform-sales/'.$this->foreignSale->id)->assertUnauthorized();
    $this->postJson('/api/v1/external-platform-sales', $this->payload)->assertUnauthorized();
});

test('unprivileged accounts cannot access any external sale endpoint', function (string $actor) {
    $sale = ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $this->adminA->id]);
    $user = $actor === 'customer' ? User::factory()->create(['user_type' => 'customer'])->refresh() : $this->{$actor};
    $this->actingAs($user, 'sanctum');
    $this->getJson('/api/v1/external-platform-sales')->assertForbidden();
    $this->getJson('/api/v1/external-platform-sales/'.$sale->id)->assertForbidden();
    $this->postJson('/api/v1/external-platform-sales', $this->payload)->assertForbidden();
})->with(['chef', 'super', 'customer']);

test('suspended restaurants and inactive employees cannot register sales', function (string $case) {
    if ($case === 'restaurant') {
        $this->a->update(['status' => 'suspended']);
    } else {
        $this->adminA->forceFill(['active' => false])->save();
    }
    $this->actingAs($this->adminA->fresh(), 'sanctum')->postJson('/api/v1/external-platform-sales', $this->payload)->assertForbidden();
    expect(DB::table('external_platform_sales')->where('restaurant_id', $this->a->id)->count())->toBe(0);
})->with(['restaurant', 'employee']);

test('invalid external sale fields are rejected without partial registration', function (array $override, string $key) {
    $this->postJson('/api/v1/external-platform-sales', array_replace($this->payload, $override))
        ->assertUnprocessable()->assertJsonValidationErrors($key);
    expect(ExternalPlatformSale::count())->toBe(0)->and(ExternalPlatformSaleItem::count())->toBe(0);
})->with([
    'unknown platform' => [['platform' => 'other'], 'platform'],
    'display label is not an enum' => [['platform' => 'Uber Eats'], 'platform'],
    'zero total' => [['total_amount' => 0], 'total_amount'],
    'negative total' => [['total_amount' => -1], 'total_amount'],
    'excess precision' => [['total_amount' => '0.001'], 'total_amount'],
    'money overflow' => [['total_amount' => '100000000.00'], 'total_amount'],
    'negative commission' => [['commission_amount' => -1], 'commission_amount'],
    'commission precision' => [['commission_amount' => '1.001'], 'commission_amount'],
    'commission exceeds total' => [['commission_amount' => '100.06'], 'commission_amount'],
    'invalid date' => [['sale_date' => 'not-a-date'], 'sale_date'],
    'null date' => [['sale_date' => null], 'sale_date'],
    'empty items' => [['items' => []], 'items'],
    'too many items' => [['items' => array_fill(0, 101, ['description' => 'x', 'quantity' => 1, 'unit_price' => 1])], 'items'],
    'items must be a list' => [['items' => ['key' => ['description' => 'x', 'quantity' => 1, 'unit_price' => 1]]], 'items'],
    'zero quantity' => [['items' => [['description' => 'x', 'quantity' => 0, 'unit_price' => 1]]], 'items.0.quantity'],
    'fractional quantity' => [['items' => [['description' => 'x', 'quantity' => 1.5, 'unit_price' => 1]]], 'items.0.quantity'],
    'quantity overflow' => [['items' => [['description' => 'x', 'quantity' => 2147483648, 'unit_price' => 1]]], 'items.0.quantity'],
    'negative unit price' => [['items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => -1]]], 'items.0.unit_price'],
    'unit price precision' => [['items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => '1.001']]], 'items.0.unit_price'],
    'unit price overflow' => [['items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => '100000000.00']]], 'items.0.unit_price'],
    'invalid menu reference type' => [['items' => [['menu_item_id' => [], 'description' => 'x', 'quantity' => 1, 'unit_price' => 1]]], 'items.0.menu_item_id'],
    'description required' => [['items' => [['quantity' => 1, 'unit_price' => 1]]], 'items.0.description'],
    'description too long' => [['items' => [['description' => str_repeat('x', 256), 'quantity' => 1, 'unit_price' => 1]]], 'items.0.description'],
    'forged parent' => [['items' => [['external_sale_id' => 1, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1]]], 'items.0'],
    'forged tenant' => [['restaurant_id' => 1], 'restaurant_id'],
    'forged actor' => [['registered_by_employee_id' => 1], 'registered_by_employee_id'],
    'forged net' => [['net_amount' => 100], 'net_amount'],
    'forged id' => [['id' => 1], 'id'],
    'forged timestamp' => [['created_at' => '2026-01-01'], 'created_at'],
]);

test('missing required fields return localized validation messages', function () {
    $this->adminA->update(['locale' => 'es']);
    $this->actingAs($this->adminA->fresh(), 'sanctum')->postJson('/api/v1/external-platform-sales', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['platform', 'total_amount', 'commission_amount', 'items'])
        ->assertJsonPath('errors.platform.0', 'El campo plataforma es obligatorio.');
});

test('all sale and item writes roll back when a later item fails', function () {
    ExternalPlatformSaleItem::query();
    $dispatcher = ExternalPlatformSaleItem::getEventDispatcher();
    ExternalPlatformSaleItem::setEventDispatcher(clone $dispatcher);
    $count = 0;
    ExternalPlatformSaleItem::creating(function () use (&$count) {
        if (++$count === 2) {
            throw new RuntimeException('simulated storage failure');
        }
    });
    try {
        $this->postJson('/api/v1/external-platform-sales', $this->payload)->assertStatus(500);
        expect(ExternalPlatformSale::count())->toBe(0)->and(ExternalPlatformSaleItem::count())->toBe(0);
    } finally {
        ExternalPlatformSaleItem::setEventDispatcher($dispatcher);
    }
});

test('model guards prevent foreign parent menu and employee assignments', function () {
    expect(fn () => ExternalPlatformSaleItem::factory()->create(['external_sale_id' => $this->foreignSale->id]))->toThrow(HttpException::class);
    $sale = ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $this->adminA->id]);
    expect(fn () => $sale->items()->create(['menu_item_id' => $this->menuB->id, 'description' => 'foreign', 'quantity' => 1, 'unit_price' => 1]))->toThrow(HttpException::class);
    expect(fn () => ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $this->adminB->id]))->toThrow(HttpException::class);
    expect(fn () => $this->foreignSale->update(['platform' => 'rappi']))->toThrow(HttpException::class);
    expect(fn () => $this->foreignItem->update(['external_sale_id' => $sale->id]))->toThrow(HttpException::class);
});
