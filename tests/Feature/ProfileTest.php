<?php

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('customers edit their own contact details and login alias while preserving their QR code', function () {
    $customer = Customer::factory()->create();
    $user = $customer->user;
    $token = $user->createToken('profile')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/user', [
        'name' => 'Nombre actualizado', 'username' => 'delishio_cliente', 'locale' => 'es', 'phone' => '5550123456',
    ])->assertOk()->assertJsonPath('id', $user->id)->assertJsonPath('name', 'Nombre actualizado')
        ->assertJsonPath('username', 'delishio_cliente')->assertJsonPath('locale', 'es')
        ->assertJsonPath('customer.phone', '5550123456')->assertJsonPath('customer.qr_code', $customer->qr_code)
        ->assertJsonMissingPath('password');

    $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('customer.phone', '5550123456');
    $this->postJson('/api/v1/auth/login', ['login' => 'delishio_cliente', 'password' => 'password'])
        ->assertOk()->assertJsonStructure(['access_token']);
    $this->patchJson('/api/v1/user', ['username' => null, 'phone' => null])->assertOk()
        ->assertJsonPath('username', null)->assertJsonPath('customer.phone', null);
});

test('employees can update their own phone without changing any other restaurant employee', function () {
    $super = User::factory()->create(['user_type' => 'super_admin']);
    $restaurants = collect(['A', 'B'])->map(fn (string $name) => Restaurant::create(['name' => $name, 'created_by_user_id' => $super->id]));
    [$employee, $other] = $restaurants->map(fn (Restaurant $restaurant) => app(RestaurantContext::class)
        ->run($restaurant, fn () => Employee::factory()->create(['role' => 'chef', 'station' => 'bar', 'phone' => '123'])))->all();

    $this->withToken($employee->user->createToken('profile')->plainTextToken)
        ->patchJson('/api/v1/user', ['phone' => '456', 'locale' => 'es'])->assertOk()
        ->assertJsonPath('employee.phone', '456')->assertJsonPath('employee.role', 'chef')
        ->assertJsonPath('employee.station', 'bar')->assertJsonMissingPath('employee.pin');

    $this->patchJson('/api/v1/user', ['user_id' => $other->user_id, 'phone' => '999', 'role' => 'admin'])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'role']);
    $this->assertDatabaseHas('employees', ['user_id' => $employee->user_id, 'phone' => '456']);
    $this->assertDatabaseHas('employees', ['user_id' => $other->user_id, 'phone' => '123']);
});

test('super administrators can edit personal fields while keeping two factor requirements', function () {
    $super = User::factory()->create(['user_type' => 'super_admin', 'username' => 'super']);
    $this->withToken($super->createToken('profile')->plainTextToken)
        ->patchJson('/api/v1/user', ['name' => 'Super actualizado', 'username' => 'super'])->assertOk()
        ->assertJsonPath('name', 'Super actualizado')->assertJsonPath('username', 'super');
    $this->patchJson('/api/v1/user', ['phone' => '123', 'two_factor_enabled' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'two_factor_enabled']);
});

test('profile validation rejects invalid fields and rolls back the whole request', function () {
    $customer = Customer::factory()->create();
    User::factory()->create(['username' => 'ocupado']);
    $this->withToken($customer->user->createToken('profile')->plainTextToken)
        ->patchJson('/api/v1/user', [
            'username' => 'ocupado', 'name' => 'Nuevo', 'locale' => 'fr', 'phone' => '999',
            'email' => 'another@example.com', 'password' => 'newPassword123', 'active' => false,
            'qr_code' => 'other', 'restaurant_id' => 123,
        ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'locale', 'email', 'password', 'active', 'qr_code', 'restaurant_id']);
    expect($customer->fresh()->phone)->toBe($customer->phone)
        ->and($customer->user->fresh()->name)->toBe($customer->user->name);
});

test('anonymous requests cannot edit a profile', function () {
    $this->patchJson('/api/v1/user', ['name' => 'No'])->assertUnauthorized();
});

test('profile editing requires an active verified account', function (array $attributes) {
    $user = User::factory()->create(['user_type' => 'customer', ...$attributes]);
    $this->withToken($user->createToken('profile')->plainTextToken)->patchJson('/api/v1/user', ['name' => 'No'])->assertForbidden();
})->with([
    'inactive' => [['active' => false]],
    'unverified' => [['email_verified_at' => null]],
]);
