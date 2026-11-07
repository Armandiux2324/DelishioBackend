<?php

use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function reportOrder(array $attributes = []): Order
{
    return Order::factory()->create($attributes + [
        'waiter_employee_id' => null,
        'cash_register_id' => null,
        'status' => 'closed',
        'closed_at' => '2026-11-06 12:00:00',
    ]);
}

beforeEach(function () {
    $this->restaurant = Restaurant::create([
        'name' => 'Restaurant A',
        'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ]);
    $this->admin = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->reportUrl = '/api/v1/admin/reports/payment-transactions?from=2026-11-05&to=2026-11-06';
});

test('mixed POS payments are grouped by method and a captured app payment appears only online', function () {
    $this->actingAs($this->admin, 'sanctum');
    $pos = reportOrder();
    foreach ([['cash', '30.00', '0.00'], ['card', '70.00', '2.10'], ['transfer', '17.20', '0.00']] as [$method, $amount, $surcharge]) {
        Payment::factory()->for($pos)->create(['method' => $method, 'amount' => $amount, 'surcharge_amount' => $surcharge]);
    }
    $online = reportOrder(['source' => 'customer_app']);
    $online->advancePayment()->create([
        'stripe_account_id' => 'acct_Report',
        'currency' => 'USD',
        'amount' => '113.20',
        'request_key' => (string) Str::uuid(),
        'request_hash' => str_repeat('a', 64),
        'status' => 'captured',
        'captured_at' => '2026-11-06 12:00:00',
    ]);
    Payment::factory()->for($online)->create(['method' => 'card', 'amount' => '109.90', 'surcharge_amount' => '3.30']);

    $this->getJson($this->reportUrl)->assertOk()->assertExactJson(['data' => [
        'from' => '2026-11-05',
        'to' => '2026-11-06',
        'timezone' => 'UTC',
        'by_type' => [
            'cash' => ['transaction_count' => 1, 'amount' => '30.00', 'surcharge_amount' => '0.00', 'total_amount' => '30.00'],
            'card' => ['transaction_count' => 1, 'amount' => '70.00', 'surcharge_amount' => '2.10', 'total_amount' => '72.10'],
            'transfer' => ['transaction_count' => 1, 'amount' => '17.20', 'surcharge_amount' => '0.00', 'total_amount' => '17.20'],
            'online' => ['transaction_count' => 1, 'amount' => '109.90', 'surcharge_amount' => '3.30', 'total_amount' => '113.20'],
        ],
        'totals' => ['transaction_count' => 4, 'amount' => '227.10', 'surcharge_amount' => '5.40', 'total_amount' => '232.50'],
    ]]);
});

test('the report includes both date boundaries and counts only closed orders and captured online payments', function () {
    $this->actingAs($this->admin, 'sanctum');
    foreach ([
        ['2026-11-04 23:59:59', 'closed', '50.00'],
        ['2026-11-05 00:00:00', 'closed', '0.10'],
        ['2026-11-06 23:59:59', 'closed', '0.20'],
        ['2026-11-07 00:00:00', 'closed', '60.00'],
        ['2026-11-06 11:00:00', 'open', '70.00'],
        ['2026-11-06 11:00:00', 'cancelled', '80.00'],
    ] as [$closedAt, $status, $amount]) {
        $order = reportOrder(['closed_at' => $closedAt, 'status' => $status]);
        Payment::factory()->for($order)->create(['amount' => $amount]);
    }
    foreach (['authorized', 'refund_pending', 'refunded'] as $status) {
        $online = reportOrder(['source' => 'customer_app']);
        $online->advancePayment()->create([
            'stripe_account_id' => 'acct_Report',
            'currency' => 'USD',
            'amount' => '90.00',
            'request_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64),
            'status' => $status,
        ]);
        Payment::factory()->for($online)->create(['method' => 'card', 'amount' => '90.00']);
    }

    $this->getJson($this->reportUrl)->assertOk()
        ->assertJsonPath('data.totals.transaction_count', 2)
        ->assertJsonPath('data.totals.total_amount', '0.30')
        ->assertJsonPath('data.by_type.online.total_amount', '0.00');
    $this->getJson('/api/v1/admin/reports/payment-transactions?from=2026-11-06&to=2026-11-06')->assertOk()
        ->assertJsonPath('data.totals.total_amount', '0.20');
});

test('each admin sees totals only for their restaurant and cannot set a restaurant id', function () {
    $other = Restaurant::create(['name' => 'Restaurant B', 'created_by_user_id' => $this->restaurant->created_by_user_id]);
    $otherAdmin = app(RestaurantContext::class)->run($other, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->actingAs($otherAdmin, 'sanctum');
    Payment::factory()->for(reportOrder())->create(['amount' => '99.00']);
    $this->actingAs($this->admin, 'sanctum');
    Payment::factory()->for(reportOrder())->create(['amount' => '10.00']);

    $this->getJson($this->reportUrl)->assertOk()
        ->assertJsonPath('data.totals.transaction_count', 1)->assertJsonPath('data.totals.amount', '10.00');
    $this->getJson($this->reportUrl.'&restaurant_id='.$other->id)->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');
    $this->actingAs($otherAdmin, 'sanctum')->getJson($this->reportUrl)->assertOk()
        ->assertJsonPath('data.totals.transaction_count', 1)->assertJsonPath('data.totals.amount', '99.00');
});

test('a valid range with no transactions keeps all four groups', function () {
    $this->actingAs($this->admin, 'sanctum')->getJson($this->reportUrl)->assertOk()
        ->assertJsonCount(4, 'data.by_type')
        ->assertJsonPath('data.totals.transaction_count', 0)
        ->assertJsonPath('data.totals.total_amount', '0.00');
});

test('the report requires an ordered range of calendar dates', function (string $query, array $errors) {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/reports/payment-transactions'.$query)
        ->assertUnprocessable()->assertJsonValidationErrors($errors);
})->with([
    'missing' => ['', ['from', 'to']],
    'reversed' => ['?from=2026-11-07&to=2026-11-06', ['to']],
    'invalid calendar date' => ['?from=2026-02-30&to=2026-03-01', ['from']],
    'invalid date format' => ['?from=11-06-2026&to=2026-11-07', ['from']],
]);

test('only authenticated restaurant administrators can read the report', function () {
    $this->getJson($this->reportUrl)->assertUnauthorized();
    $waiter = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create())->user;
    $this->actingAs($waiter, 'sanctum')->getJson($this->reportUrl)->assertForbidden();
    $this->actingAs(User::factory()->create(['user_type' => 'super_admin'])->refresh(), 'sanctum')
        ->getJson($this->reportUrl)->assertForbidden();
});
