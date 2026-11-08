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

function ordersReportOrder(array $attributes = []): Order
{
    return Order::factory()->create($attributes + [
        'waiter_employee_id' => null,
        'cash_register_id' => null,
        'created_at' => '2026-11-06 12:00:00',
    ]);
}

function ordersReportOnline(string $paymentStatus, array $attributes = []): Order
{
    $order = ordersReportOrder(['source' => 'customer_app'] + $attributes);
    $order->advancePayment()->create([
        'stripe_account_id' => 'acct_Report',
        'payment_intent_id' => 'pi_'.Str::random(20),
        'currency' => 'USD',
        'amount' => $order->total,
        'request_key' => (string) Str::uuid(),
        'request_hash' => str_repeat('a', 64),
        'status' => $paymentStatus,
    ]);

    return $order;
}

beforeEach(function () {
    $this->restaurant = Restaurant::create([
        'name' => 'Restaurant A',
        'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ]);
    $this->admin = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->url = '/api/v1/admin/reports/orders?from=2026-11-05&to=2026-11-06';
});

test('paid orders use the closing date and return each finalized order once', function () {
    $this->actingAs($this->admin, 'sanctum');
    $pos = ordersReportOrder([
        'status' => 'closed', 'created_at' => '2026-11-04 23:59:59', 'closed_at' => '2026-11-05 00:00:00',
        'subtotal' => '100.00', 'surcharge_total' => '1.80', 'total' => '101.80',
    ]);
    Payment::factory()->for($pos)->create(['method' => 'cash', 'amount' => '40.00']);
    Payment::factory()->for($pos)->create(['method' => 'card', 'amount' => '60.00', 'surcharge_amount' => '1.80']);
    $captured = ordersReportOnline('captured', ['status' => 'closed', 'closed_at' => '2026-11-06 23:59:59', 'total' => '20.00']);
    ordersReportOnline('refunded', ['status' => 'closed', 'closed_at' => '2026-11-06 18:00:00']);
    ordersReportOnline('authorized', ['status' => 'closed', 'closed_at' => '2026-11-06 18:00:00']);
    ordersReportOrder(['status' => 'closed', 'closed_at' => '2026-11-07 00:00:00']);
    ordersReportOrder(['status' => 'sent']);

    $response = $this->getJson($this->url.'&type=paid')->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.advance_payment_status', 'captured')
        ->assertJsonPath('data.1.advance_payment_status', null)
        ->assertJsonPath('data.1.total', '101.80')
        ->assertJsonPath('data.1.surcharge_total', '1.80')
        ->assertJsonPath('filters.timezone', 'UTC');
    expect(array_column($response->json('data'), 'id'))->toBe([$captured->id, $pos->id]);
});

test('open and online filters use creation dates and keep order and payment states distinct', function () {
    $this->actingAs($this->admin, 'sanctum');
    $open = ordersReportOrder(['created_at' => '2026-11-05 00:00:00']);
    $sent = ordersReportOrder(['status' => 'sent']);
    $pending = ordersReportOnline('pending', ['created_at' => '2026-11-06 23:59:59']);
    $cancelled = ordersReportOnline('voided', ['status' => 'cancelled']);
    $captured = ordersReportOnline('captured', ['status' => 'closed', 'closed_at' => '2026-11-07 00:00:00']);
    ordersReportOnline('pending', ['created_at' => '2026-11-07 00:00:00']);
    ordersReportOrder(['created_at' => '2026-11-04 23:59:59']);

    $openResponse = $this->getJson($this->url.'&type=open')->assertOk()->assertJsonPath('meta.total', 3);
    expect(array_column($openResponse->json('data'), 'id'))->toBe([$pending->id, $sent->id, $open->id]);

    $onlineResponse = $this->getJson($this->url.'&type=online')->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.status', 'open')->assertJsonPath('data.0.advance_payment_status', 'pending')
        ->assertJsonPath('data.1.status', 'closed')->assertJsonPath('data.1.advance_payment_status', 'captured')
        ->assertJsonPath('data.2.status', 'cancelled')->assertJsonPath('data.2.advance_payment_status', 'voided')
        ->assertJsonMissingPath('data.0.payment_intent_id')->assertJsonMissingPath('data.0.stripe_account_id')
        ->assertJsonMissingPath('data.0.delivery_address');
    expect(array_column($onlineResponse->json('data'), 'id'))->toBe([$pending->id, $captured->id, $cancelled->id]);
});

test('every order view only returns orders from the administrator restaurant', function (string $type) {
    $other = Restaurant::create(['name' => 'Restaurant B', 'created_by_user_id' => $this->restaurant->created_by_user_id]);
    $otherAdmin = app(RestaurantContext::class)->run($other, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $attributes = match ($type) {
        'paid' => ['status' => 'closed', 'closed_at' => '2026-11-06 12:00:00'],
        'online' => ['source' => 'customer_app'],
        default => [],
    };
    $this->actingAs($otherAdmin, 'sanctum');
    $foreign = ordersReportOrder($attributes);
    $this->actingAs($this->admin, 'sanctum');
    $local = ordersReportOrder($attributes);

    $url = $this->url.'&type='.$type;
    $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $local->id);
    $this->getJson($url.'&restaurant_id='.$other->id)->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');
    $this->actingAs($otherAdmin, 'sanctum')->getJson($url)->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $foreign->id);
})->with(['paid', 'open', 'online']);

test('pagination preserves the validated filters and breaks date ties by order id', function () {
    $this->actingAs($this->admin, 'sanctum');
    $first = ordersReportOrder();
    for ($i = 0; $i < 20; $i++) {
        $last = ordersReportOrder();
    }

    $url = $this->url.'&type=open';
    $response = $this->getJson($url)->assertOk()->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 21)->assertJsonPath('data.0.id', $last->id);
    parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $next);
    expect($next)->toMatchArray(['type' => 'open', 'from' => '2026-11-05', 'to' => '2026-11-06', 'page' => '2']);
    $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
});

test('order report filters must identify a valid view and date range', function (string $query, string $field) {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/reports/orders?'.$query)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing view' => ['from=2026-11-05&to=2026-11-06', 'type'],
    'unknown view' => ['type=all&from=2026-11-05&to=2026-11-06', 'type'],
    'missing date' => ['type=paid&to=2026-11-06', 'from'],
    'reversed dates' => ['type=paid&from=2026-11-06&to=2026-11-05', 'to'],
    'invalid calendar date' => ['type=open&from=2026-02-30&to=2026-03-01', 'from'],
    'invalid page' => ['type=online&from=2026-11-05&to=2026-11-06&page=0', 'page'],
]);

test('only an authenticated restaurant administrator can read the order report', function () {
    $url = $this->url.'&type=paid';
    $this->getJson($url)->assertUnauthorized();
    $waiter = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create())->user;
    $this->actingAs($waiter, 'sanctum')->getJson($url)->assertForbidden();
});
