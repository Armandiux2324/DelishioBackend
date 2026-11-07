<?php

use App\Models\Employee;
use App\Models\ExternalPlatformSale;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function dashboardOrder(array $attributes = []): Order
{
    return Order::factory()->create($attributes + ['waiter_employee_id' => null, 'cash_register_id' => null]);
}

function dashboardAdvancePayment(Order $order, string $status): void
{
    $order->advancePayment()->create([
        'stripe_account_id' => 'acct_Dashboard',
        'currency' => 'USD',
        'amount' => $order->total,
        'request_key' => (string) Str::uuid(),
        'request_hash' => str_repeat('a', 64),
        'status' => $status,
        'captured_at' => $order->closed_at,
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00', 'UTC'));
    $this->restaurant = Restaurant::create([
        'name' => 'Restaurant A',
        'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ]);
    $this->admin = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->dashboardUrl = '/api/v1/admin/dashboard';
});

test('the dashboard reconciles sale totals and hours while keeping payment and category bases separate', function () {
    $this->actingAs($this->admin, 'sanctum');
    $waiter = Employee::factory()->create();
    $second = Employee::factory()->create();
    $food = MenuItem::factory()->create(['sale_price' => '400.00']);
    $drink = MenuItem::factory()->create(['sale_price' => '200.00']);
    $pos = dashboardOrder(['waiter_employee_id' => $waiter->user_id]);
    OrderItem::factory()->for($pos)->create(['menu_item_id' => $food->id, 'quantity' => 2, 'unit_price' => '40.00']);
    OrderItem::factory()->for($pos)->create(['menu_item_id' => $drink->id, 'quantity' => 1, 'unit_price' => '20.00']);
    OrderItem::factory()->for($pos)->create(['menu_item_id' => $food->id, 'quantity' => 1, 'unit_price' => '99.00', 'cancelled' => true]);
    $pos->update(['status' => 'closed', 'closed_at' => '2026-11-07 08:00:00', 'subtotal' => '100.00',
        'discount_total' => '10.00', 'tax_total' => '9.00', 'tip_amount' => '5.00', 'surcharge_total' => '1.47', 'total' => '105.47']);
    Payment::factory()->for($pos)->create(['method' => 'cash', 'amount' => '50.00']);
    Payment::factory()->for($pos)->create(['method' => 'card', 'amount' => '54.00', 'surcharge_amount' => '1.47']);

    $secondOrder = dashboardOrder(['waiter_employee_id' => $second->user_id]);
    OrderItem::factory()->for($secondOrder)->create(['menu_item_id' => $food->id, 'quantity' => 1, 'unit_price' => '20.00']);
    $secondOrder->update(['status' => 'closed', 'closed_at' => now(), 'subtotal' => '20.00', 'tax_total' => '2.00', 'total' => '22.00']);
    Payment::factory()->for($secondOrder)->create(['method' => 'transfer', 'amount' => '22.00']);

    $online = dashboardOrder(['source' => 'customer_app']);
    OrderItem::factory()->for($online)->create(['menu_item_id' => $drink->id, 'quantity' => 1, 'unit_price' => '10.00']);
    $online->update(['status' => 'closed', 'closed_at' => '2026-11-07 08:59:59', 'subtotal' => '10.00',
        'tax_total' => '1.00', 'surcharge_total' => '0.33', 'total' => '11.33']);
    dashboardAdvancePayment($online, 'captured');
    Payment::factory()->for($online)->create(['method' => 'card', 'amount' => '11.00', 'surcharge_amount' => '0.33']);

    $refunded = dashboardOrder(['source' => 'customer_app', 'status' => 'closed', 'closed_at' => now(), 'total' => '99.00']);
    dashboardAdvancePayment($refunded, 'refunded');
    dashboardOrder();
    dashboardOrder(['status' => 'sent', 'sent_at' => now()]);
    dashboardOrder(['status' => 'cancelled', 'total' => '99.00']);
    ExternalPlatformSale::factory()->create([
        'registered_by_employee_id' => $this->admin->id, 'sale_date' => '2026-11-07 08:30:00',
        'total_amount' => '20.20', 'commission_amount' => '3.03',
    ]);

    $this->getJson($this->dashboardUrl)->assertOk()
        ->assertJsonPath('data.period', 'today')->assertJsonPath('data.timezone', 'UTC')
        ->assertJsonPath('data.sales_count', 4)->assertJsonPath('data.sales_total', '159.00')
        ->assertJsonPath('data.order_count', 7)->assertJsonPath('data.open_order_count', 2)
        ->assertJsonPath('data.average_ticket', '39.75')
        ->assertJsonPath('data.by_payment_method.cash.total_amount', '50.00')
        ->assertJsonPath('data.by_payment_method.card.total_amount', '55.47')
        ->assertJsonPath('data.by_payment_method.transfer.total_amount', '22.00')
        ->assertJsonPath('data.by_payment_method.online.total_amount', '11.33')
        ->assertJsonCount(2, 'data.by_category')
        ->assertJsonPath('data.by_category.0.category_id', $food->category_id)
        ->assertJsonPath('data.by_category.0.subtotal', '100.00')
        ->assertJsonPath('data.by_category.1.subtotal', '30.00')
        ->assertJsonCount(2, 'data.top_employees')
        ->assertJsonPath('data.top_employees.0.employee_id', $waiter->user_id)
        ->assertJsonPath('data.top_employees.0.total_amount', '105.47')
        ->assertJsonPath('data.top_employees.1.employee_id', $second->user_id)
        ->assertJsonCount(24, 'data.sales_by_hour')
        ->assertJsonPath('data.sales_by_hour.8.total_amount', '137.00')
        ->assertJsonPath('data.sales_by_hour.8.sale_count', 3)
        ->assertJsonPath('data.sales_by_hour.12.total_amount', '22.00')
        ->assertJsonPath('data.sales_by_hour.23.total_amount', '0.00');
});

test('date filters include both days and set the expected period', function (string $query, string $from, string $to, string $total) {
    $this->actingAs($this->admin, 'sanctum');
    foreach ([
        ['2026-11-07 23:59:59', '7.00'],
        ['2026-11-06 00:00:00', '6.00'],
        ['2026-11-01 00:00:00', '1.00'],
        ['2026-10-31 23:59:59', '100.00'],
        ['2026-11-08 00:00:00', '200.00'],
    ] as [$date, $amount]) {
        dashboardOrder(['status' => 'closed', 'created_at' => $date, 'closed_at' => $date, 'total' => $amount]);
    }

    $this->getJson($this->dashboardUrl.$query)->assertOk()
        ->assertJsonPath('data.from', $from)->assertJsonPath('data.to', $to)
        ->assertJsonPath('data.sales_total', $total);
})->with([
    'today' => ['?period=today', '2026-11-07', '2026-11-07', '7.00'],
    'yesterday' => ['?period=yesterday', '2026-11-06', '2026-11-06', '6.00'],
    'week' => ['?period=week', '2026-11-01', '2026-11-07', '14.00'],
    'custom' => ['?from=2026-10-31&to=2026-11-01', '2026-10-31', '2026-11-01', '101.00'],
]);

test('the dashboard scopes every metric to the authenticated restaurant', function () {
    $other = Restaurant::create(['name' => 'Restaurant B', 'created_by_user_id' => $this->restaurant->created_by_user_id]);
    $otherAdmin = app(RestaurantContext::class)->run($other, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->actingAs($otherAdmin, 'sanctum');
    $waiter = Employee::factory()->create();
    $order = dashboardOrder(['waiter_employee_id' => $waiter->user_id]);
    OrderItem::factory()->for($order)->create(['quantity' => 1, 'unit_price' => '10.00']);
    $order->update(['status' => 'closed', 'closed_at' => now(), 'total' => '10.00']);
    Payment::factory()->for($order)->create(['amount' => '10.00']);
    dashboardOrder();
    ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $otherAdmin->id]);
    $this->getJson($this->dashboardUrl)->assertOk()->assertJsonPath('data.sales_total', '110.00');

    $this->actingAs($this->admin, 'sanctum')->getJson($this->dashboardUrl)->assertOk()
        ->assertJsonPath('data.sales_total', '0.00')->assertJsonPath('data.sales_count', 0)
        ->assertJsonPath('data.order_count', 0)->assertJsonPath('data.open_order_count', 0)
        ->assertJsonPath('data.average_ticket', '0.00')
        ->assertJsonPath('data.by_payment_method.cash.total_amount', '0.00')
        ->assertJsonPath('data.by_category', [])->assertJsonPath('data.top_employees', [])
        ->assertJsonPath('data.sales_by_hour.12.total_amount', '0.00');
    $this->getJson($this->dashboardUrl.'?restaurant_id='.$other->id)->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');
});

test('the dashboard rejects invalid filters', function (string $query, string $field) {
    $this->actingAs($this->admin, 'sanctum')->getJson($this->dashboardUrl.$query)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown period' => ['?period=month', 'period'],
    'period mixed with dates' => ['?period=today&from=2026-11-07&to=2026-11-07', 'period'],
    'missing start date' => ['?to=2026-11-07', 'from'],
    'missing end date' => ['?from=2026-11-07', 'to'],
    'reversed range' => ['?from=2026-11-07&to=2026-11-06', 'to'],
    'invalid date' => ['?from=2026-02-30&to=2026-03-01', 'from'],
]);

test('the dashboard is restricted to authenticated restaurant administrators', function () {
    $this->getJson($this->dashboardUrl)->assertUnauthorized();
    $waiter = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create())->user;
    $this->actingAs($waiter, 'sanctum')->getJson($this->dashboardUrl)->assertForbidden();
});
