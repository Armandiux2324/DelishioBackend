<?php

use App\Models\CashRegister;
use App\Models\Employee;
use App\Models\ExternalPlatformSale;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function salesSummaryOrder(array $attributes = []): Order
{
    return Order::factory()->create($attributes + [
        'waiter_employee_id' => null,
        'cash_register_id' => null,
        'status' => 'closed',
        'closed_at' => '2026-11-06 10:30:00',
    ]);
}

function salesSummaryOnlineOrder(string $status, array $attributes = []): Order
{
    $order = salesSummaryOrder(['source' => 'customer_app'] + $attributes);
    $order->advancePayment()->create([
        'stripe_account_id' => 'acct_Report',
        'currency' => 'USD',
        'amount' => $order->total,
        'request_key' => (string) Str::uuid(),
        'request_hash' => str_repeat('a', 64),
        'status' => $status,
        'captured_at' => $status === 'captured' ? $order->closed_at : null,
    ]);

    return $order;
}

beforeEach(function () {
    $this->restaurant = Restaurant::create([
        'name' => 'Restaurant A',
        'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ]);
    $this->admin = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->summaryUrl = '/api/v1/admin/reports/sales-summary?from=2026-11-05&to=2026-11-06';
});

test('the summary counts each closed sale once and separates marketplace commission', function () {
    $this->actingAs($this->admin, 'sanctum');
    $pos = salesSummaryOrder([
        'closed_at' => '2026-11-05 00:00:00', 'subtotal' => '120.00', 'discount_total' => '20.00',
        'loyalty_redeemed_amount' => '10.00', 'tax_total' => '9.00', 'delivery_fee' => '3.00',
        'tip_amount' => '5.00', 'surcharge_total' => '2.25', 'total' => '109.25',
    ]);
    Payment::factory()->for($pos)->create(['method' => 'cash', 'amount' => '27.00']);
    Payment::factory()->for($pos)->create(['method' => 'card', 'amount' => '80.00', 'surcharge_amount' => '2.25']);
    salesSummaryOnlineOrder('captured', [
        'closed_at' => '2026-11-06 23:59:59', 'subtotal' => '50.00', 'tax_total' => '5.00',
        'surcharge_total' => '1.65', 'total' => '56.65',
    ]);
    salesSummaryOnlineOrder('refunded', ['subtotal' => '70.00', 'total' => '70.00']);
    salesSummaryOnlineOrder('authorized', ['subtotal' => '80.00', 'total' => '80.00']);
    salesSummaryOrder(['status' => 'cancelled', 'total' => '90.00']);
    salesSummaryOrder(['status' => 'open', 'closed_at' => null, 'total' => '90.00']);
    salesSummaryOrder(['closed_at' => '2026-11-07 00:00:00', 'total' => '90.00']);
    ExternalPlatformSale::factory()->create([
        'registered_by_employee_id' => $this->admin->id, 'sale_date' => '2026-11-06 23:59:59',
        'total_amount' => '100.05', 'commission_amount' => '15.03',
    ]);
    ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $this->admin->id, 'sale_date' => '2026-11-04 23:59:59']);

    $this->getJson($this->summaryUrl)->assertOk()->assertExactJson(['data' => [
        'from' => '2026-11-05', 'to' => '2026-11-06', 'from_time' => null, 'to_time' => null,
        'cash_register_id' => null, 'timezone' => 'UTC',
        'orders' => [
            'order_count' => 2, 'subtotal' => '170.00', 'discount_total' => '20.00', 'loyalty_redeemed_amount' => '10.00',
            'net_consumption' => '140.00', 'tax_total' => '14.00', 'delivery_fee' => '3.00',
            'tip_amount' => '5.00', 'surcharge_total' => '3.90', 'total' => '165.90',
        ],
        'external_platform' => ['sale_count' => 1, 'total_amount' => '100.05', 'commission_amount' => '15.03', 'net_amount' => '85.02'],
        'totals' => ['sale_count' => 3, 'total_amount' => '265.95', 'amount_after_platform_commission' => '250.92'],
    ]]);
});

test('the hour window applies on every day and cash register excludes unlinked marketplace sales', function () {
    $this->actingAs($this->admin, 'sanctum');
    $cash = CashRegister::factory()->create();
    $otherCash = CashRegister::factory()->create();
    foreach ([
        [$cash->id, '2026-11-05 10:00:00', '10.00'],
        [$cash->id, '2026-11-06 11:00:00', '20.00'],
        [$otherCash->id, '2026-11-06 10:30:00', '40.00'],
        [$cash->id, '2026-11-05 09:59:59', '50.00'],
        [$cash->id, '2026-11-06 11:00:01', '60.00'],
    ] as [$cashId, $date, $total]) {
        salesSummaryOrder(['cash_register_id' => $cashId, 'closed_at' => $date, 'subtotal' => $total, 'total' => $total]);
    }
    ExternalPlatformSale::factory()->create([
        'registered_by_employee_id' => $this->admin->id, 'sale_date' => '2026-11-06 10:30:00',
        'total_amount' => '5.00', 'commission_amount' => '1.00',
    ]);
    ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $this->admin->id, 'sale_date' => '2026-11-06 11:00:01']);

    $url = $this->summaryUrl.'&from_time=10:00:00&to_time=11:00:00';
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.orders.order_count', 3)->assertJsonPath('data.external_platform.sale_count', 1)
        ->assertJsonPath('data.totals.total_amount', '75.00');
    $this->getJson($url.'&cash_register_id='.$cash->id)->assertOk()
        ->assertJsonPath('data.cash_register_id', $cash->id)
        ->assertJsonPath('data.orders.order_count', 2)->assertJsonPath('data.external_platform.sale_count', 0)
        ->assertJsonPath('data.totals.total_amount', '30.00');
});

test('sales and cash register validation are isolated by restaurant', function () {
    $other = Restaurant::create(['name' => 'Restaurant B', 'created_by_user_id' => $this->restaurant->created_by_user_id]);
    $otherAdmin = app(RestaurantContext::class)->run($other, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->actingAs($otherAdmin, 'sanctum');
    $otherCash = CashRegister::factory()->create();
    salesSummaryOrder(['cash_register_id' => $otherCash->id, 'subtotal' => '200.00', 'total' => '200.00']);
    ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $otherAdmin->id, 'sale_date' => '2026-11-06 12:00:00']);

    $this->actingAs($this->admin, 'sanctum');
    salesSummaryOrder(['subtotal' => '10.00', 'total' => '10.00']);
    ExternalPlatformSale::factory()->create([
        'registered_by_employee_id' => $this->admin->id, 'sale_date' => '2026-11-06 12:00:00',
        'total_amount' => '20.00', 'commission_amount' => '2.00',
    ]);

    $this->getJson($this->summaryUrl)->assertOk()->assertJsonPath('data.totals.total_amount', '30.00');
    $this->getJson($this->summaryUrl.'&cash_register_id='.$otherCash->id)->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
    $this->getJson($this->summaryUrl.'&restaurant_id='.$other->id)->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');
    $this->actingAs($otherAdmin, 'sanctum')->getJson($this->summaryUrl)->assertOk()
        ->assertJsonPath('data.totals.total_amount', '300.00');
});

test('an empty summary returns zero amounts and requires a restaurant administrator', function () {
    $this->getJson($this->summaryUrl)->assertUnauthorized();
    $waiter = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create())->user;
    $this->actingAs($waiter, 'sanctum')->getJson($this->summaryUrl)->assertForbidden();
    $this->actingAs($this->admin, 'sanctum')->getJson($this->summaryUrl)->assertOk()
        ->assertJsonPath('data.orders.delivery_fee', '0.00')
        ->assertJsonPath('data.totals.sale_count', 0)->assertJsonPath('data.totals.total_amount', '0.00');
});

test('invalid date and time filters are rejected', function (string $query, string $field) {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/reports/sales-summary?'.$query)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing date' => ['to=2026-11-06', 'from'],
    'reversed date range' => ['from=2026-11-06&to=2026-11-05', 'to'],
    'missing end time' => ['from=2026-11-05&to=2026-11-06&from_time=10:00:00', 'to_time'],
    'missing start time' => ['from=2026-11-05&to=2026-11-06&to_time=11:00:00', 'from_time'],
    'invalid clock time' => ['from=2026-11-05&to=2026-11-06&from_time=24:00:00&to_time=11:00:00', 'from_time'],
    'reversed time range' => ['from=2026-11-05&to=2026-11-06&from_time=11:00:00&to_time=10:00:00', 'to_time'],
]);
