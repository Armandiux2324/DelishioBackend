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
    $this->actingAs($this->adminB, 'sanctum');
    $this->registerB = CashRegister::factory()->create();
    $this->movementB = CashMovement::factory()->create(['cash_register_id' => $this->registerB->id]);
    $this->actingAs($this->adminA, 'sanctum');
    $this->registerA = CashRegister::factory()->create();
    $this->payload = ['cash_register_id' => $this->registerA->id, 'type' => 'cash_out', 'amount' => '10.05', 'reason' => 'minor_purchase'];
});

test('administrators record consult and preserve manual cash entries and exits', function () {
    $this->travelTo(now()->startOfSecond());
    $response = $this->postJson('/api/v1/admin/cash-movements', $this->payload)->assertCreated()
        ->assertJsonPath('data.amount', '10.05')->assertJsonPath('data.registered_by_employee_id', $this->adminA->id);
    $id = $response->json('data.id');
    $this->assertDatabaseHas('cash_movements', ['id' => $id, 'created_at' => now()->format('Y-m-d H:i:s')]);
    $this->getJson('/api/v1/admin/cash-movements/'.$id)->assertOk()->assertJsonPath('data.reason', 'minor_purchase');
    $this->getJson('/api/v1/admin/cash-movements')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/admin/cash-movements', ['cash_register_id' => $this->registerA->id, 'type' => 'cash_in', 'amount' => '0.05'])->assertCreated()->assertJsonPath('data.reason', null);
    expect((string) $this->registerA->expectedCash())->toBe('90.00');
    $this->patchJson('/api/v1/admin/cash-movements/'.$id, ['amount' => 1])->assertMethodNotAllowed();
    $this->deleteJson('/api/v1/admin/cash-movements/'.$id)->assertMethodNotAllowed();
});

test('payroll records require a local employee and an inclusive period including former staff', function () {
    $this->waiterA->delete();
    $response = $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, [
        'reason' => 'payroll', 'related_employee_id' => $this->waiterA->id,
        'payroll_period_start' => '2026-10-01', 'payroll_period_end' => '2026-10-01',
    ]))->assertCreated()->assertJsonPath('data.related_employee_id', $this->waiterA->id)
        ->assertJsonPath('data.payroll_period_start', '2026-10-01')->assertJsonPath('data.payroll_period_end', '2026-10-01');
    $this->assertDatabaseHas('cash_movements', ['id' => $response->json('data.id'), 'related_employee_id' => $this->waiterA->id]);
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, ['reason' => 'payroll']))
        ->assertUnprocessable()->assertJsonValidationErrors(['related_employee_id', 'payroll_period_start', 'payroll_period_end']);
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, [
        'reason' => 'payroll', 'related_employee_id' => $this->adminB->id,
        'payroll_period_start' => '2026-10-02', 'payroll_period_end' => '2026-10-01',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['related_employee_id', 'payroll_period_end']);
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, [
        'reason' => 'payroll', 'related_employee_id' => 999999,
        'payroll_period_start' => '2026-02-30', 'payroll_period_end' => '2026-10-01',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['related_employee_id', 'payroll_period_start']);
});

test('all DBML cash out reasons are supported', function (string $reason) {
    $data = array_replace($this->payload, ['reason' => $reason]);
    if ($reason === 'payroll') {
        $data += ['related_employee_id' => $this->waiterA->id, 'payroll_period_start' => '2026-10-01', 'payroll_period_end' => '2026-10-31'];
    }
    $this->postJson('/api/v1/admin/cash-movements', $data)->assertCreated()->assertJsonPath('data.reason', $reason);
})->with(['payroll', 'minor_purchase', 'other']);

test('movement input validation rejects invalid amounts identities or inappropriate fields', function (string $field, mixed $value) {
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(CashMovement::count())->toBe(0);
})->with([
    ['amount', 0], ['amount', '-0.01'], ['amount', '1.001'], ['amount', '100000000'], ['amount', null],
    ['type', 'other'], ['reason', null], ['reason', 'misc'], ['cash_register_id', 999999],
    ['related_employee_id', 1], ['payroll_period_start', '2026-10-01'], ['payroll_period_end', '2026-10-31'],
    ['id', null], ['restaurant_id', null], ['registered_by_employee_id', null], ['created_at', null],
]);

test('cash in cannot carry cash out reasons or payroll details', function () {
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, [
        'type' => 'cash_in', 'reason' => 'payroll', 'related_employee_id' => $this->waiterA->id,
        'payroll_period_start' => '2026-10-01', 'payroll_period_end' => '2026-10-31',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['reason', 'related_employee_id', 'payroll_period_start', 'payroll_period_end']);
    $this->postJson('/api/v1/admin/cash-movements', [])->assertUnprocessable()->assertJsonValidationErrors(['cash_register_id', 'type', 'amount']);
});

test('exits cannot exceed available cash and incoming amounts cannot overflow the balance', function () {
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, ['amount' => '100.01']))->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect(CashMovement::count())->toBe(0);
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, ['amount' => '100.00']))->assertCreated();
    expect((string) $this->registerA->expectedCash())->toBe('0.00');
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, ['amount' => '0.01']))->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson('/api/v1/admin/cash-movements', ['cash_register_id' => $this->registerA->id, 'type' => 'cash_in', 'amount' => '99999999.99'])->assertCreated();
    $this->postJson('/api/v1/admin/cash-movements', ['cash_register_id' => $this->registerA->id, 'type' => 'cash_in', 'amount' => '0.01'])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect((string) $this->registerA->expectedCash())->toBe('99999999.99');
});

test('movements cannot target foreign registers or disclose foreign records', function () {
    $own = $this->postJson('/api/v1/admin/cash-movements', $this->payload)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/admin/cash-movements', array_replace($this->payload, ['cash_register_id' => $this->registerB->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
    $this->getJson('/api/v1/admin/cash-movements?restaurant_id='.$this->restaurantB->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
    $this->getJson('/api/v1/admin/cash-movements/'.$this->movementB->id)->assertNotFound();
    $this->actingAs($this->adminB, 'sanctum')->getJson('/api/v1/admin/cash-movements')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->movementB->id);
});

test('closed registers preserve history and reject both kinds of new movement', function () {
    $id = $this->postJson('/api/v1/admin/cash-movements', $this->payload)->assertCreated()->json('data.id');
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$this->registerA->id.'/close', ['counted_cash' => '89.95'])->assertOk();
    $this->actingAs($this->adminA, 'sanctum');
    $this->getJson('/api/v1/admin/cash-movements/'.$id)->assertOk();
    $this->postJson('/api/v1/admin/cash-movements', $this->payload)->assertConflict();
    $this->postJson('/api/v1/admin/cash-movements', ['cash_register_id' => $this->registerA->id, 'type' => 'cash_in', 'amount' => '1'])->assertConflict();
    expect(CashMovement::count())->toBe(1);
});

test('movement insert failures roll back the ledger row and leave the balance unchanged', function () {
    $dispatcher = CashMovement::getEventDispatcher();
    CashMovement::setEventDispatcher(clone $dispatcher);
    CashMovement::created(fn () => throw new RuntimeException('Movement insert failed'));
    try {
        $this->postJson('/api/v1/admin/cash-movements', $this->payload)->assertServerError();
    } finally {
        CashMovement::setEventDispatcher($dispatcher);
    }
    expect(CashMovement::count())->toBe(0);
    expect((string) $this->registerA->expectedCash())->toBe('100.00');
});

test('movement model writes retain tenant checks and existing rows are immutable', function () {
    expect(fn () => CashMovement::factory()->create(['cash_register_id' => $this->registerB->id]))->toThrow(HttpException::class);
    expect(fn () => CashMovement::factory()->create(['cash_register_id' => $this->registerA->id, 'registered_by_employee_id' => $this->adminB->id]))->toThrow(HttpException::class);
    expect(fn () => CashMovement::factory()->create(['cash_register_id' => $this->registerA->id, 'registered_by_employee_id' => $this->waiterA->id]))->toThrow(HttpException::class);
    expect(fn () => CashMovement::factory()->create(['cash_register_id' => $this->registerA->id, 'related_employee_id' => $this->adminB->id]))->toThrow(HttpException::class);
    $movement = CashMovement::factory()->create(['cash_register_id' => $this->registerA->id]);
    expect(fn () => $movement->update(['amount' => 1]))->toThrow(HttpException::class);
    expect(fn () => $movement->delete())->toThrow(HttpException::class);
    auth()->forgetGuards();
    expect(fn () => CashMovement::count())->toThrow(HttpException::class);
});

test('only administrators can read or create cash movements', function (string $role) {
    $movement = CashMovement::factory()->create(['cash_register_id' => $this->registerA->id]);
    if ($role === 'guest') {
        auth()->forgetGuards();
    } elseif (in_array($role, ['waiter', 'chef'])) {
        $this->actingAs(Employee::factory()->create(['role' => $role, 'station' => $role === 'chef' ? 'bar' : null])->user, 'sanctum');
    } else {
        $this->actingAs(User::factory()->create(['user_type' => $role]), 'sanctum');
    }
    $status = $role === 'guest' ? 401 : 403;
    $this->getJson('/api/v1/admin/cash-movements')->assertStatus($status);
    $this->getJson('/api/v1/admin/cash-movements/'.$movement->id)->assertStatus($status);
    $this->postJson('/api/v1/admin/cash-movements', $this->payload)->assertStatus($status);
})->with(['guest', 'waiter', 'chef', 'customer', 'super_admin']);

test('suspended restaurants cannot record cash movements with existing tokens', function () {
    $token = $this->adminA->createToken('test')->plainTextToken;
    auth()->forgetGuards();
    $this->restaurantA->update(['status' => 'suspended']);
    $this->withToken($token)->postJson('/api/v1/admin/cash-movements', $this->payload)->assertForbidden();
});
