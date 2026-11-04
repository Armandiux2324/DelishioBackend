<?php

use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function configurationEmployee(Restaurant $restaurant, string $role = 'admin'): User
{
    $user = User::factory()->create(['user_type' => 'employee']);
    app(RestaurantContext::class)->run($restaurant, fn () => Employee::create([
        'user_id' => $user->id, 'role' => $role, 'hourly_rate' => 100, 'pin' => '123456',
    ]));

    return $user->refresh();
}

beforeEach(function () {
    $this->restaurant = Restaurant::create([
        'name' => 'Restaurante A', 'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ])->refresh();
    $this->admin = configurationEmployee($this->restaurant);
});

test('admin reads and updates every configuration field of their restaurant', function () {
    $this->withToken($this->admin->createToken('test')->plainTextToken);
    $this->getJson('/api/v1/admin/restaurant/configuration')->assertOk()
        ->assertJsonPath('data.id', $this->restaurant->id)->assertJsonPath('data.currency', 'MXN')
        ->assertJsonPath('data.loyalty_enabled', false)->assertJsonPath('data.loyalty_factor', null)
        ->assertJsonPath('data.dual_pricing_enabled', false);
    $payload = [
        'name' => 'Delishio', 'description' => 'Comida y bebidas', 'address' => 'Calle 1',
        'phone' => '+1 555 123 4567', 'logo_url' => 'https://delishio.com/logo.png', 'schedule' => 'Lunes a sábado, 09:00–22:00',
        'currency' => 'USD', 'loyalty_enabled' => true, 'loyalty_factor' => '1.2500', 'loyalty_redeem_value' => '100.0000',
        'discount_limit_per_shift' => '250.50', 'courtesy_percentage' => '15.25', 'courtesy_daily_limit' => 3,
        'dual_pricing_enabled' => true, 'dual_pricing_percentage' => '3.50',
    ];
    $response = $this->patchJson('/api/v1/admin/restaurant/configuration', $payload)->assertOk();
    foreach ($payload as $field => $value) {
        $response->assertJsonPath('data.'.$field, $value);
    }
    $this->assertDatabaseHas('restaurants', ['id' => $this->restaurant->id] + $payload);
    $this->getJson('/api/v1/admin/restaurant/configuration')->assertOk()->assertJsonPath('data.loyalty_factor', '1.2500');
});

test('each administrator sees only their restaurant and cannot select another by query or URL', function () {
    $other = Restaurant::create(['name' => 'Restaurante B', 'created_by_user_id' => $this->restaurant->created_by_user_id])->refresh();
    $otherAdmin = configurationEmployee($other);
    $this->actingAs($this->admin, 'sanctum');
    $this->getJson('/api/v1/admin/restaurant/configuration?restaurant_id='.$other->id)->assertOk()
        ->assertJsonPath('data.id', $this->restaurant->id)->assertJsonPath('data.name', 'Restaurante A');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['name' => 'A actualizado'])->assertOk();
    $this->assertDatabaseHas('restaurants', ['id' => $other->id, 'name' => 'Restaurante B']);
    $this->getJson('/api/v1/admin/restaurants/'.$other->id.'/configuration')->assertNotFound();
    $this->patchJson('/api/v1/admin/restaurants/'.$other->id.'/configuration', ['name' => 'Ataque'])->assertNotFound();
    $this->actingAs($otherAdmin, 'sanctum');
    $this->getJson('/api/v1/admin/restaurant/configuration')->assertOk()->assertJsonPath('data.id', $other->id);
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['name' => 'B actualizado'])->assertOk();
    $this->assertDatabaseHas('restaurants', ['id' => $this->restaurant->id, 'name' => 'A actualizado']);
});

test('guests cannot read or change restaurant configuration', function () {
    $this->getJson('/api/v1/admin/restaurant/configuration')->assertUnauthorized();
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['name' => 'Ataque'])->assertUnauthorized();
    $this->assertDatabaseHas('restaurants', ['id' => $this->restaurant->id, 'name' => 'Restaurante A']);
});

test('only restaurant administrators can read and change configuration', function (string $role) {
    $user = in_array($role, ['waiter', 'chef'], true)
        ? configurationEmployee($this->restaurant, $role)
        : User::factory()->create(['user_type' => $role]);
    $this->withToken($user->createToken('test')->plainTextToken);
    $this->getJson('/api/v1/admin/restaurant/configuration')->assertForbidden();
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['name' => 'Ataque'])->assertForbidden();
    $this->assertDatabaseHas('restaurants', ['id' => $this->restaurant->id, 'name' => 'Restaurante A']);
})->with(['waiter', 'chef', 'customer', 'super_admin']);

test('blocked accounts and unavailable restaurants cannot read or change configuration', function (string $condition) {
    $token = $this->admin->createToken('test')->plainTextToken;
    match ($condition) {
        'inactive' => $this->admin->forceFill(['active' => false])->save(),
        'unverified' => $this->admin->forceFill(['email_verified_at' => null])->save(),
        'suspended' => $this->restaurant->update(['status' => 'suspended']),
        'deleted restaurant' => $this->restaurant->delete(),
        'missing employee' => app(RestaurantContext::class)->run($this->restaurant, fn () => $this->admin->employee->delete()),
    };
    $this->withToken($token)->getJson('/api/v1/admin/restaurant/configuration')->assertForbidden();
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['name' => 'Ataque'])->assertForbidden();
    expect($this->restaurant->fresh()->name)->toBe('Restaurante A');
})->with(['inactive', 'unverified', 'suspended', 'deleted restaurant', 'missing employee']);

test('invalid configuration is rejected without saving any field', function (string $field, mixed $value) {
    $before = $this->restaurant->getAttributes();
    $this->withToken($this->admin->createToken('test')->plainTextToken)
        ->patchJson('/api/v1/admin/restaurant/configuration', [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->restaurant->fresh()->getAttributes())->toBe($before);
})->with([
    ['name', null], ['name', ''], ['name', str_repeat('a', 256)],
    ['description', ['invalid']], ['description', str_repeat('é', 32768)],
    ['address', str_repeat('a', 256)], ['schedule', ['Monday' => '09:00']], ['schedule', str_repeat('a', 256)],
    ['phone', str_repeat('1', 21)], ['logo_url', 'javascript:alert(1)'], ['logo_url', 'data:image/png;base64,abc'],
    ['logo_url', 'https://delishio.com/'.str_repeat('a', 256)], ['currency', 'EUR'], ['currency', 'mxn'], ['currency', null],
    ['loyalty_enabled', 'yes'], ['loyalty_enabled', null], ['dual_pricing_enabled', 'false'], ['dual_pricing_enabled', null],
    ['loyalty_factor', 0], ['loyalty_factor', -1], ['loyalty_factor', '1.00001'], ['loyalty_factor', '10000'],
    ['loyalty_redeem_value', 0], ['loyalty_redeem_value', -1], ['loyalty_redeem_value', '1.00001'], ['loyalty_redeem_value', '10000'],
    ['discount_limit_per_shift', -1], ['discount_limit_per_shift', '1000000'], ['discount_limit_per_shift', '1.001'],
    ['courtesy_percentage', -1], ['courtesy_percentage', '100.01'], ['courtesy_percentage', '1.001'],
    ['courtesy_daily_limit', -1], ['courtesy_daily_limit', 1.5], ['courtesy_daily_limit', '4294967296'],
    ['dual_pricing_percentage', -1], ['dual_pricing_percentage', '1000'], ['dual_pricing_percentage', '1.001'],
    ['id', 123], ['restaurant_id', 123], ['restaurant_id', null], ['status', 'suspended'], ['status', null],
    ['created_by_user_id', 123], ['created_at', '2026-01-01'], ['updated_at', null], ['deleted_at', '2026-01-01'],
]);

test('enabling loyalty requires both positive factors and does not save other edits on failure', function () {
    $this->withToken($this->admin->createToken('test')->plainTextToken);
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['name' => 'Cambio', 'loyalty_enabled' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['loyalty_factor', 'loyalty_redeem_value']);
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['loyalty_enabled' => true, 'loyalty_factor' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('loyalty_redeem_value');
    expect($this->restaurant->fresh()->name)->toBe('Restaurante A');
    expect($this->restaurant->fresh()->loyalty_enabled)->toBeFalse();
});

test('partial updates preserve active loyalty factors and prohibit clearing them until disabled', function () {
    $this->restaurant->update(['loyalty_enabled' => true, 'loyalty_factor' => '2.0000', 'loyalty_redeem_value' => '100.0000']);
    $this->withToken($this->admin->createToken('test')->plainTextToken);
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['name' => 'Nuevo'])->assertOk()
        ->assertJsonPath('data.loyalty_factor', '2.0000')->assertJsonPath('data.loyalty_redeem_value', '100.0000');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['loyalty_factor' => null])->assertUnprocessable()->assertJsonValidationErrors('loyalty_factor');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['loyalty_redeem_value' => null])->assertUnprocessable()->assertJsonValidationErrors('loyalty_redeem_value');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['loyalty_enabled' => false, 'loyalty_factor' => null, 'loyalty_redeem_value' => null])
        ->assertOk()->assertJsonPath('data.loyalty_enabled', false)->assertJsonPath('data.loyalty_factor', null);
});

test('loyalty can be enabled with previously saved factors', function () {
    $this->restaurant->update(['loyalty_factor' => '0.0001', 'loyalty_redeem_value' => '0.0001']);
    $this->withToken($this->admin->createToken('test')->plainTextToken)
        ->patchJson('/api/v1/admin/restaurant/configuration', ['loyalty_enabled' => true])->assertOk()->assertJsonPath('data.loyalty_enabled', true);
});

test('dual pricing requires an adjustment while enabled and supports partial updates and disabling', function () {
    $this->withToken($this->admin->createToken('test')->plainTextToken);
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['dual_pricing_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('dual_pricing_percentage');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['dual_pricing_enabled' => true, 'dual_pricing_percentage' => '3.50'])->assertOk();
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['name' => 'Nuevo'])->assertOk()->assertJsonPath('data.dual_pricing_percentage', '3.50');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['dual_pricing_percentage' => null])->assertUnprocessable()->assertJsonValidationErrors('dual_pricing_percentage');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['dual_pricing_enabled' => false, 'dual_pricing_percentage' => null])
        ->assertOk()->assertJsonPath('data.dual_pricing_enabled', false)->assertJsonPath('data.dual_pricing_percentage', null);
});

test('zero limits and storage boundaries are accepted without rounding', function () {
    $this->withToken($this->admin->createToken('test')->plainTextToken);
    $this->patchJson('/api/v1/admin/restaurant/configuration', [
        'loyalty_factor' => '9999.9999', 'loyalty_redeem_value' => '9999.9999', 'discount_limit_per_shift' => '999999.99',
        'courtesy_percentage' => '100.00', 'courtesy_daily_limit' => 4294967295, 'dual_pricing_percentage' => '999.99',
    ])->assertOk()->assertJsonPath('data.loyalty_factor', '9999.9999')->assertJsonPath('data.discount_limit_per_shift', '999999.99');
    $this->patchJson('/api/v1/admin/restaurant/configuration', [
        'discount_limit_per_shift' => 0, 'courtesy_percentage' => 0, 'courtesy_daily_limit' => 0,
        'dual_pricing_enabled' => true, 'dual_pricing_percentage' => 0,
    ])->assertOk()->assertJsonPath('data.discount_limit_per_shift', '0.00')->assertJsonPath('data.courtesy_daily_limit', 0);
});

test('optional information can be cleared without changing other settings', function () {
    $this->restaurant->update(['description' => 'Texto', 'address' => 'Calle', 'phone' => '123', 'schedule' => '09–20', 'logo_url' => 'https://delishio.com/logo.png']);
    $this->withToken($this->admin->createToken('test')->plainTextToken)
        ->patchJson('/api/v1/admin/restaurant/configuration', [
            'description' => null, 'address' => null, 'phone' => null, 'schedule' => null, 'logo_url' => null,
        ])->assertOk()->assertJsonPath('data.description', null)->assertJsonPath('data.logo_url', null)->assertJsonPath('data.currency', 'MXN');
});

test('configuration validation follows the administrators preferred language', function () {
    $this->admin->update(['locale' => 'es']);
    $this->withToken($this->admin->createToken('test')->plainTextToken)
        ->patchJson('/api/v1/admin/restaurant/configuration', ['currency' => 'EUR'])
        ->assertUnprocessable()->assertJsonPath('errors.currency.0', 'El valor seleccionado para moneda no es válido.');
});
