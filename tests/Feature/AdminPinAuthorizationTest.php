<?php

use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\AdminPinAuthorization;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->adminA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['role' => 'admin', 'pin' => '012345']));
    $this->adminB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create(['role' => 'admin', 'pin' => '654321']));
    $this->waiter = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['pin' => '1234']));
});

test('authorization returns the local admin and logs identities action and time without credentials', function () {
    $this->travelTo(now()->startOfSecond());
    $this->actingAs($this->waiter->user, 'sanctum');
    Log::spy();
    $result = app(AdminPinAuthorization::class)->authorize($this->adminA->user_id, '012345', 'order.discount');
    expect($result->user_id)->toBe($this->adminA->user_id);
    Log::shouldHaveReceived('info')->once()->with('admin_pin_authorized', [
        'restaurant_id' => $this->restaurantA->id,
        'requested_by_employee_id' => $this->waiter->user_id,
        'authorized_by_employee_id' => $this->adminA->user_id,
        'action' => 'order.discount',
        'authorized_at' => now()->toIso8601String(),
    ]);
});

test('all active employee roles can request an administrator authorization', function (string $role) {
    $requester = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create([
        'role' => $role, 'station' => $role === 'chef' ? 'kitchen' : null,
    ]));
    $this->actingAs($requester->user, 'sanctum');
    expect(app(AdminPinAuthorization::class)->authorize($this->adminA->user_id, '012345', 'order.cancel')->user_id)->toBe($this->adminA->user_id);
})->with(['admin', 'waiter', 'chef']);

test('PIN authorization rejects wrong length format or value with the same error', function (string $pin) {
    $this->actingAs($this->waiter->user, 'sanctum');
    app()->setLocale('es');
    Log::spy();
    try {
        app(AdminPinAuthorization::class)->authorize($this->adminA->user_id, $pin, 'order.discount');
        $this->fail('PIN should have been rejected');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['pin' => ['PIN de administrador inválido.']]);
    }
    Log::shouldNotHaveReceived('info');
})->with(['', '123', '0123456', '12ab', '123456', ' 012345', '012345 ']);

test('foreign admins non admins and missing IDs cannot authorize even with correct PINs', function (string $target) {
    $this->actingAs($this->waiter->user, 'sanctum');
    [$id, $pin] = match ($target) {
        'foreign' => [$this->adminB->user_id, '654321'],
        'waiter' => [$this->waiter->user_id, '1234'],
        default => [999999, '012345'],
    };
    expect(fn () => app(AdminPinAuthorization::class)->authorize($id, $pin, 'order.discount'))->toThrow(ValidationException::class);
})->with(['foreign', 'waiter', 'missing']);

test('inactive unverified deleted and invalid type admin accounts cannot authorize', function (string $state) {
    $user = $this->adminA->user;
    match ($state) {
        'inactive' => $user->forceFill(['active' => false])->save(),
        'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
        'invalid_type' => $user->update(['user_type' => 'customer']),
        'deleted' => $user->delete(),
    };
    $this->actingAs($this->waiter->user, 'sanctum');
    expect(fn () => app(AdminPinAuthorization::class)->authorize($this->adminA->user_id, '012345', 'order.discount'))->toThrow(ValidationException::class);
})->with(['inactive', 'unverified', 'invalid_type', 'deleted']);

test('PINs can repeat but admin identity and restaurant are always checked', function () {
    $this->actingAs($this->adminB->user, 'sanctum');
    $this->adminB->update(['pin' => '012345']);
    $this->actingAs($this->waiter->user, 'sanctum');
    expect(fn () => app(AdminPinAuthorization::class)->authorize($this->adminB->user_id, '012345', 'order.discount'))->toThrow(ValidationException::class);
    expect(app(AdminPinAuthorization::class)->authorize($this->adminA->user_id, '012345', 'order.discount')->user_id)->toBe($this->adminA->user_id);
});

test('five failed attempts block target rotation until the cooldown expires', function () {
    $this->travelTo(now()->startOfSecond());
    $this->actingAs($this->waiter->user, 'sanctum');
    $service = app(AdminPinAuthorization::class);
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $service->authorize(999000 + $i, '999999', 'order.discount'))->toThrow(ValidationException::class);
    }
    try {
        $service->authorize($this->adminA->user_id, '012345', 'order.cancel');
        $this->fail('PIN attempts should be throttled');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(429);
        expect((int) $exception->getHeaders()['Retry-After'])->toBe(60);
    }
    $this->travel(61)->seconds();
    expect($service->authorize($this->adminA->user_id, '012345', 'order.cancel')->user_id)->toBe($this->adminA->user_id);
});

test('a valid PIN resets failed attempts and other requesters have independent budgets', function () {
    $this->actingAs($this->waiter->user, 'sanctum');
    $service = app(AdminPinAuthorization::class);
    for ($i = 0; $i < 4; $i++) {
        expect(fn () => $service->authorize($this->adminA->user_id, '999999', 'order.discount'))->toThrow(ValidationException::class);
    }
    $service->authorize($this->adminA->user_id, '012345', 'order.discount');
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $service->authorize($this->adminA->user_id, '999999', 'order.discount'))->toThrow(ValidationException::class);
    }
    $this->actingAs($this->adminA->user, 'sanctum');
    expect($service->authorize($this->adminA->user_id, '012345', 'order.discount')->user_id)->toBe($this->adminA->user_id);
});

test('only authenticated employees can request PIN authorizations', function (string $type) {
    if ($type !== 'guest') {
        $this->actingAs(User::factory()->create(['user_type' => $type]), 'sanctum');
    }
    expect(fn () => app(AdminPinAuthorization::class)->authorize($this->adminA->user_id, '012345', 'order.discount'))->toThrow(HttpException::class);
})->with(['guest', 'customer', 'super_admin']);

test('requester status is read fresh even when authentication has cached the user', function (string $state) {
    $user = $this->waiter->user;
    $this->actingAs($user, 'sanctum');
    match ($state) {
        'inactive' => User::whereKey($user->id)->update(['active' => false]),
        'unverified' => User::whereKey($user->id)->update(['email_verified_at' => null]),
        'deleted' => User::whereKey($user->id)->delete(),
        'suspended' => $this->restaurantA->update(['status' => 'suspended']),
    };
    expect(fn () => app(AdminPinAuthorization::class)->authorize($this->adminA->user_id, '012345', 'order.discount'))->toThrow(HttpException::class);
})->with(['inactive', 'unverified', 'deleted', 'suspended']);

test('an explicit foreign tenant context cannot borrow requester credentials', function () {
    $this->actingAs($this->waiter->user, 'sanctum');
    expect(fn () => app(RestaurantContext::class)->run($this->restaurantB,
        fn () => app(AdminPinAuthorization::class)->authorize($this->adminB->user_id, '654321', 'order.discount')
    ))->toThrow(HttpException::class);
});

test('PIN authorization requires the action being authorized', function () {
    $this->actingAs($this->waiter->user, 'sanctum');
    expect(fn () => app(AdminPinAuthorization::class)->authorize($this->adminA->user_id, '012345', ''))->toThrow(ValidationException::class);
});
