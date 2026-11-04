<?php

use App\Models\Customer;
use App\Models\Employee;
use App\Models\LoyaltyBalance;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->adminA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->adminB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->customerA = Customer::factory()->create();
    $this->customerB = Customer::factory()->create();
    $this->shared = Customer::factory()->create();
    $this->unaffiliated = Customer::factory()->create();
    app(RestaurantContext::class)->run($this->restaurantA, function () {
        LoyaltyBalance::factory()->create(['customer_id' => $this->customerA->user_id]);
        LoyaltyBalance::factory()->create(['customer_id' => $this->shared->user_id, 'points_balance' => '12.34']);
    });
    app(RestaurantContext::class)->run($this->restaurantB, function () {
        LoyaltyBalance::factory()->create(['customer_id' => $this->customerB->user_id, 'points_balance' => '50.00']);
        LoyaltyBalance::factory()->create(['customer_id' => $this->shared->user_id, 'points_balance' => '999.99']);
    });
});

test('admins see only affiliated customers including zero balances when loyalty is disabled', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $response = $this->getJson('/api/v1/admin/customers?restaurant_id='.$this->restaurantB->id)->assertOk()->assertJsonCount(2, 'data');
    expect(collect($response->json('data'))->pluck('user_id')->all())->toBe([$this->customerA->user_id, $this->shared->user_id]);
    $this->getJson('/api/v1/admin/customers/'.$this->customerA->user_id)->assertOk()
        ->assertJsonPath('data.points_balance', '0.00')->assertJsonPath('data.name', $this->customerA->user->name)
        ->assertJsonPath('data.email', $this->customerA->user->email)->assertJsonPath('data.phone', '5551234567')
        ->assertJsonMissingPath('data.qr_code')->assertJsonMissingPath('data.password');
    $this->getJson('/api/v1/admin/customers/'.$this->shared->user_id)->assertOk()->assertJsonPath('data.points_balance', '12.34')
        ->assertJsonPath('data.restaurant_id', $this->restaurantA->id);
    $this->getJson('/api/v1/admin/customers/'.$this->customerB->user_id)->assertNotFound();
    $this->getJson('/api/v1/admin/customers/'.$this->unaffiliated->user_id)->assertNotFound();
    $this->getJson('/api/v1/admin/customers/999999')->assertNotFound();
    $this->getJson('/api/v1/admin/customers/not-an-id')->assertNotFound();
    $this->actingAs($this->adminB, 'sanctum')->getJson('/api/v1/admin/customers/'.$this->shared->user_id)
        ->assertOk()->assertJsonPath('data.points_balance', '999.99');
});

test('customer consultation paginates without including another restaurant customers', function () {
    app(RestaurantContext::class)->run($this->restaurantA, fn () => LoyaltyBalance::factory()->count(21)->create());
    $this->actingAs($this->adminA, 'sanctum');
    $this->getJson('/api/v1/admin/customers')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
    $this->getJson('/api/v1/admin/customers?page=2')->assertOk()->assertJsonCount(3, 'data');
});

test('deleted or invalid type customers are excluded but disabled affiliates remain visible', function () {
    $this->customerA->user->delete();
    $this->shared->user->forceFill(['active' => false])->save();
    $this->actingAs($this->adminA, 'sanctum')->getJson('/api/v1/admin/customers')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $this->shared->user_id)->assertJsonPath('data.0.active', false);
    $this->getJson('/api/v1/admin/customers/'.$this->customerA->user_id)->assertNotFound();
    $this->shared->user->update(['user_type' => 'employee']);
    $this->getJson('/api/v1/admin/customers/'.$this->shared->user_id)->assertNotFound();
});

test('admin customer consultation exposes no write routes', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $url = '/api/v1/admin/customers/'.$this->customerA->user_id;
    $this->postJson('/api/v1/admin/customers', ['user_id' => $this->unaffiliated->user_id])->assertMethodNotAllowed();
    $this->patchJson($url, ['points_balance' => 999])->assertMethodNotAllowed();
    $this->deleteJson($url)->assertMethodNotAllowed();
    expect(Customer::count())->toBe(4);
    expect(LoyaltyBalance::count())->toBe(2);
});

test('customer consultation requires authentication and administrator role', function (string $role) {
    if ($role !== 'guest') {
        $user = in_array($role, ['waiter', 'chef'])
            ? app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['role' => $role, 'station' => $role === 'chef' ? 'bar' : null]))->user
            : User::factory()->create(['user_type' => $role]);
        $this->actingAs($user, 'sanctum');
    }
    $status = $role === 'guest' ? 401 : 403;
    $this->getJson('/api/v1/admin/customers')->assertStatus($status);
    $this->getJson('/api/v1/admin/customers/'.$this->customerA->user_id)->assertStatus($status);
})->with(['guest', 'waiter', 'chef', 'customer', 'super_admin']);

test('suspended restaurants cannot consult customers with existing tokens', function () {
    $token = $this->adminA->createToken('test')->plainTextToken;
    $this->restaurantA->update(['status' => 'suspended']);
    $this->withToken($token)->getJson('/api/v1/admin/customers')->assertForbidden();
    $this->getJson('/api/v1/admin/customers/'.$this->customerA->user_id)->assertForbidden();
});

test('balances fail closed without context and cannot be reassigned across restaurants or customers', function () {
    expect(fn () => LoyaltyBalance::count())->toThrow(HttpException::class);
    $this->actingAs($this->adminA, 'sanctum');
    $balance = LoyaltyBalance::where('customer_id', $this->customerA->user_id)->firstOrFail();
    expect(fn () => $balance->update(['customer_id' => $this->unaffiliated->user_id]))->toThrow(HttpException::class);
    $balance->refresh();
    $this->actingAs($this->adminB, 'sanctum');
    expect(fn () => $balance->update(['points_balance' => 999]))->toThrow(HttpException::class);
    expect(fn () => $balance->delete())->toThrow(HttpException::class);
});
