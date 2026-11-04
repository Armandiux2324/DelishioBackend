<?php

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Employee;
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
    $this->waiterA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create())->user;
    $this->adminB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->waiterB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create())->user;
});

test('admin and waiter open a shared register and only waiter closes it', function (string $opener) {
    $this->travelTo(now()->startOfSecond());
    $user = $opener === 'admin' ? $this->adminA : $this->waiterA;
    $this->actingAs($user, 'sanctum');
    $id = $this->postJson('/api/v1/cash-registers', ['opening_amount' => '100.00'])->assertCreated()
        ->assertJsonPath('data.restaurant_id', $this->restaurantA->id)->assertJsonPath('data.opened_by_employee_id', $user->id)
        ->assertJsonPath('data.opening_amount', '100.00')->assertJsonPath('data.closed_at', null)->json('data.id');
    $this->assertDatabaseHas('cash_registers', ['id' => $id, 'opened_at' => now()->format('Y-m-d H:i:s')]);
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/cash-registers', ['opening_amount' => 0])->assertConflict();
    $this->postJson('/api/v1/cash-registers/'.$id.'/close', ['counted_cash' => 100])->assertForbidden();
    CashMovement::factory()->create(['cash_register_id' => $id, 'type' => 'cash_in', 'amount' => '20.10', 'reason' => null]);
    CashMovement::factory()->create(['cash_register_id' => $id, 'amount' => '30.05', 'reason' => 'minor_purchase']);
    CashMovement::factory()->create(['cash_register_id' => $id, 'amount' => '50.00', 'reason' => 'payroll',
        'related_employee_id' => $this->waiterA->id, 'payroll_period_start' => '2026-10-01', 'payroll_period_end' => '2026-10-31']);
    $this->actingAs($this->waiterA, 'sanctum');
    $this->getJson('/api/v1/cash-registers/'.$id)->assertOk();
    $this->travel(8)->hours();
    $this->postJson('/api/v1/cash-registers/'.$id.'/close', ['counted_cash' => '40.00'])->assertOk()
        ->assertJsonPath('data.expected_cash', '40.05')->assertJsonPath('data.difference', '-0.05')
        ->assertJsonPath('data.closed_by_employee_id', $this->waiterA->id);
    $this->assertDatabaseHas('cash_registers', ['id' => $id, 'closed_at' => now()->format('Y-m-d H:i:s')]);
    $this->postJson('/api/v1/cash-registers/'.$id.'/close', ['counted_cash' => '40'])->assertConflict();
    $this->postJson('/api/v1/cash-registers', ['opening_amount' => 0])->assertCreated();
    $this->getJson('/api/v1/cash-registers')->assertOk()->assertJsonCount(2, 'data');
})->with(['admin', 'waiter']);

test('cash calculations preserve cents and support positive negative and zero differences', function (string $counted, string $difference) {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->postJson('/api/v1/cash-registers', ['opening_amount' => '0.10'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/admin/cash-movements', ['cash_register_id' => $id, 'type' => 'cash_in', 'amount' => '0.20'])->assertCreated();
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$id.'/close', ['counted_cash' => $counted])
        ->assertOk()->assertJsonPath('data.expected_cash', '0.30')->assertJsonPath('data.difference', $difference);
})->with([['0.29', '-0.01'], ['0.30', '0.00'], ['0.31', '0.01']]);

test('opening and closing amounts enforce DBML bounds and scale', function (mixed $value) {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create();
    $this->postJson('/api/v1/cash-registers', ['opening_amount' => $value])->assertUnprocessable()->assertJsonValidationErrors('opening_amount');
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
})->with([null, '-0.01', '100000000', '1.001', 'not-money']);

test('cash endpoints require their amount fields', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create();
    $this->postJson('/api/v1/cash-registers', [])->assertUnprocessable()->assertJsonValidationErrors('opening_amount');
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$register->id.'/close', [])
        ->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
});

test('server controlled register identity close values and dates cannot be forged', function (string $field) {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create();
    $this->postJson('/api/v1/cash-registers', ['opening_amount' => 10, $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => 10, $field => null])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['id', 'restaurant_id', 'opened_by_employee_id', 'opened_at', 'closed_by_employee_id', 'expected_cash', 'difference', 'closed_at']);

test('register lists reads and closes cannot cross restaurants', function () {
    $this->actingAs($this->adminB, 'sanctum');
    $foreign = CashRegister::factory()->create();
    $this->actingAs($this->adminA, 'sanctum');
    $own = CashRegister::factory()->create();
    $this->getJson('/api/v1/cash-registers?restaurant_id='.$this->restaurantB->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson('/api/v1/cash-registers/'.$foreign->id)->assertNotFound();
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$foreign->id.'/close', ['counted_cash' => 100])->assertNotFound();
    $this->assertDatabaseHas('cash_registers', ['id' => $foreign->id, 'closed_at' => null]);
    $this->actingAs($this->waiterB, 'sanctum')->getJson('/api/v1/cash-registers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id);
});

test('cash register history remains valid after the opener leaves', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create();
    $this->adminA->delete();
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => 100])->assertOk();
    $this->getJson('/api/v1/cash-registers/'.$register->id)->assertOk()->assertJsonPath('data.opened_by_employee_id', $this->adminA->id);
});

test('close failure after the SQL update rolls back all close fields', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create();
    $this->actingAs($this->waiterA, 'sanctum');
    $dispatcher = CashRegister::getEventDispatcher();
    CashRegister::setEventDispatcher(clone $dispatcher);
    CashRegister::updated(fn () => throw new RuntimeException('Close failed'));
    try {
        $this->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => '90'])->assertServerError();
    } finally {
        CashRegister::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseHas('cash_registers', ['id' => $register->id, 'closed_at' => null, 'closed_by_employee_id' => null, 'counted_cash' => null, 'expected_cash' => null, 'difference' => null]);
});

test('full decimal bounds and invalid ledger balances are handled without truncation', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create(['opening_amount' => '99999999.99']);
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => 0])
        ->assertOk()->assertJsonPath('data.expected_cash', '99999999.99')->assertJsonPath('data.difference', '-99999999.99');
    $this->actingAs($this->adminA, 'sanctum');
    $bad = CashRegister::factory()->create(['opening_amount' => '0.00']);
    CashMovement::factory()->create(['cash_register_id' => $bad->id, 'amount' => '0.01']);
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$bad->id.'/close', ['counted_cash' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
    $this->assertDatabaseHas('cash_registers', ['id' => $bad->id, 'closed_at' => null]);
});

test('register models preserve tenant ownership opening values and closed records', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create();
    expect(fn () => $register->update(['opening_amount' => 999]))->toThrow(HttpException::class);
    $register->refresh();
    expect(fn () => $register->delete())->toThrow(HttpException::class);
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => 100])->assertOk();
    $register->refresh();
    expect(fn () => $register->update(['counted_cash' => 90]))->toThrow(HttpException::class);
    $register->refresh();
    $this->actingAs($this->adminB, 'sanctum');
    expect(fn () => $register->update(['difference' => 50]))->toThrow(HttpException::class);
    auth()->forgetGuards();
    expect(fn () => CashRegister::count())->toThrow(HttpException::class);
});

test('financial history fixes the restaurant currency while unrelated configuration remains editable', function () {
    $this->actingAs($this->adminB, 'sanctum');
    CashRegister::factory()->create();
    $this->actingAs($this->adminA, 'sanctum');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['currency' => 'USD'])->assertOk();
    $id = $this->postJson('/api/v1/cash-registers', ['opening_amount' => 100])->assertCreated()->json('data.id');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['currency' => 'MXN'])->assertConflict();
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['currency' => 'USD', 'name' => 'Nuevo'])->assertOk();
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$id.'/close', ['counted_cash' => 100])->assertOk();
    $this->actingAs($this->adminA, 'sanctum')->patchJson('/api/v1/admin/restaurant/configuration', ['currency' => 'MXN'])->assertConflict();
});

test('cash access denies unauthenticated users chefs customers and super admins', function (string $role) {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create();
    if ($role === 'guest') {
        auth()->forgetGuards();
    } elseif ($role === 'chef') {
        $this->actingAs(Employee::factory()->create(['role' => 'chef', 'station' => 'bar'])->user, 'sanctum');
    } else {
        $this->actingAs(User::factory()->create(['user_type' => $role]), 'sanctum');
    }
    $status = $role === 'guest' ? 401 : 403;
    $this->getJson('/api/v1/cash-registers')->assertStatus($status);
    $this->getJson('/api/v1/cash-registers/'.$register->id)->assertStatus($status);
    $this->postJson('/api/v1/cash-registers', ['opening_amount' => 100])->assertStatus($status);
    $this->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => 100])->assertStatus($status);
})->with(['guest', 'chef', 'customer', 'super_admin']);

test('suspension blocks cash access and cash records have no generic update or delete routes', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $register = CashRegister::factory()->create();
    $this->patchJson('/api/v1/cash-registers/'.$register->id, ['opening_amount' => 0])->assertMethodNotAllowed();
    $this->deleteJson('/api/v1/cash-registers/'.$register->id)->assertMethodNotAllowed();
    $token = $this->waiterA->createToken('test')->plainTextToken;
    auth()->forgetGuards();
    $this->restaurantA->update(['status' => 'suspended']);
    $this->withToken($token)->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => 100])->assertForbidden();
});
