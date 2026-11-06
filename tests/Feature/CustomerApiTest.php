<?php

use App\Models\AdvanceOrderPayment;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\LoyaltyBalance;
use App\Models\LoyaltyTransaction;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Restaurant;
use App\Models\SuperCategory;
use App\Models\TaxClass;
use App\Models\User;
use App\Services\StripeConnectGateway;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function clientOrder(?Restaurant $restaurant = null, ?MenuItem $menu = null, ?string $key = null): int
{
    $restaurant ??= test()->a;
    $menu ??= test()->menuA;

    return test()->postJson('/api/v1/customer/restaurants/'.$restaurant->id.'/orders', ['items' => [
        ['menu_item_id' => $menu->id, 'quantity' => 1, 'modifiers' => 'Sin cebolla', 'kitchen_notes' => 'Alergia']]],
        ['Idempotency-Key' => $key ?? (string) Str::uuid()])->assertCreated()->json('data.id');
}

function clientIntent(int $order): string
{
    return test()->postJson("/api/v1/customer/orders/$order/payment")->assertOk()->json('data.payment_intent_id');
}

function clientEvent(int $order, array $intentOverrides = [], array $eventOverrides = [], int $offset = 0, string $secret = 'whsec_local')
{
    $payment = AdvanceOrderPayment::withoutGlobalScope('restaurant')->where('order_id', $order)->firstOrFail();
    $object = test()->intents[$payment->payment_reference]->toArray();
    $object = array_replace_recursive($object, ['status' => 'succeeded', 'amount_received' => $object['amount']], $intentOverrides);
    $event = array_replace(['id' => 'evt_'.Str::random(12), 'object' => 'event', 'type' => 'payment_intent.succeeded',
        'created' => time(), 'livemode' => false, 'account' => $payment->stripe_account_id, 'data' => ['object' => $object]], $eventOverrides);
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = time() + $offset;
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return test()->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t=$timestamp,v1=$signature"], $body);
}

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->a = Restaurant::create(['name' => 'A', 'currency' => 'USD', 'created_by_user_id' => $creator->id,
        'loyalty_enabled' => true, 'loyalty_factor' => '2.0000', 'loyalty_redeem_value' => '10.0000',
        'dual_pricing_enabled' => true, 'dual_pricing_percentage' => '3.00']);
    $this->b = Restaurant::create(['name' => 'B', 'currency' => 'MXN', 'created_by_user_id' => $creator->id]);
    $this->adminA = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->adminB = app(RestaurantContext::class)->run($this->b, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->waiterA = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create())->user;
    $this->chefA = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'chef', 'station' => 'kitchen']))->user;
    foreach (['A', 'B'] as $suffix) {
        $this->actingAs($this->{'admin'.$suffix}, 'sanctum');
        $ingredient = InventoryItem::factory()->create(['current_stock' => '50.000']);
        $tax = TaxClass::factory()->create(['percentage' => '10.00']);
        $category = Category::factory()->create(['super_category_id' => SuperCategory::factory()->create(['tax_class_id' => $tax->id])->id]);
        $menu = MenuItem::factory()->create(['category_id' => $category->id, 'name' => 'Menu '.$suffix, 'sale_price' => '100.00']);
        $menu->ingredients()->create(['inventory_item_id' => $ingredient->id, 'quantity' => '2.000']);
        $this->{'menu'.$suffix} = $menu;
        $this->{'ingredient'.$suffix} = $ingredient;
        $this->{'cash'.$suffix} = CashRegister::factory()->create(['opening_amount' => '20.00']);
    }
    $this->customer = Customer::factory()->create();
    $this->other = Customer::factory()->create();
    $this->actingAs($this->customer->user, 'sanctum');
    config(['services.stripe.secret' => 'sk_test_local', 'services.stripe.publishable_key' => 'pk_test_local',
        'services.stripe.webhook_secret' => 'whsec_local', 'services.stripe.livemode' => false,
        'services.stripe.accounts' => [$this->a->id => 'acct_A', $this->b->id => 'acct_B']]);
    $this->intents = [];
    $this->intentCreates = 0;
    $this->refunds = 0;
    $this->refundStatus = 'succeeded';
    $this->refundFailure = false;
    $gateway = Mockery::mock(StripeConnectGateway::class)->makePartial();
    $gateway->shouldReceive('createIntent')->andReturnUsing(function (AdvanceOrderPayment $payment) {
        $this->intentCreates++;
        $id = 'pi_'.$payment->id;

        return $this->intents[$id] = PaymentIntent::constructFrom(['id' => $id, 'object' => 'payment_intent',
            'amount' => (new StripeConnectGateway)->minorUnits($payment->amount), 'amount_received' => 0,
            'currency' => strtolower($payment->currency), 'metadata' => ['delishio_order_id' => (string) $payment->order_id],
            'client_secret' => $id.'_secret_local', 'status' => 'requires_payment_method']);
    });
    $gateway->shouldReceive('retrieveIntent')->andReturnUsing(fn ($payment) => $this->intents[$payment->payment_reference]);
    $gateway->shouldReceive('refund')->andReturnUsing(function ($payment) {
        $this->refunds++;
        abort_if($this->refundFailure, 503, 'refund temporarily unavailable');

        return Refund::constructFrom(['id' => 're_'.$payment->id, 'object' => 'refund', 'status' => $this->refundStatus]);
    });
    $this->app->instance(StripeConnectGateway::class, $gateway);
});

test('customers browse only active restaurants and a public menu without recipes or employee settings', function () {
    $this->getJson('/api/v1/customer/restaurants')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.0.created_by_user_id')->assertJsonMissingPath('data.0.discount_limit_per_shift');
    $this->getJson('/api/v1/customer/restaurants/'.$this->a->id.'/menu')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Menu A')->assertJsonPath('data.0.tax_percentage', '10.00')
        ->assertJsonMissingPath('data.0.ingredients')->assertJsonMissingPath('data.0.preparation_station');
    $this->getJson('/api/v1/customer/restaurants/'.$this->b->id.'/menu')->assertOk()->assertJsonPath('data.0.name', 'Menu B');
    $this->b->update(['status' => 'suspended']);
    $this->getJson('/api/v1/customer/restaurants')->assertOk()->assertJsonCount(1, 'data');
    foreach (['', '/menu', '/promotions'] as $path) {
        $this->getJson('/api/v1/customer/restaurants/'.$this->b->id.$path)->assertNotFound();
    }
    expect(fn () => MenuItem::count())->toThrow(HttpException::class);
});

test('advance checkout freezes totals and account without preparing or consuming inventory before payment', function () {
    $id = clientOrder();
    $this->getJson("/api/v1/customer/orders/$id")->assertOk()->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.total', '113.30')->assertJsonPath('data.tax_total', '10.00')
        ->assertJsonPath('data.dual_pricing_applied', true)->assertJsonPath('data.preparation_status', 'pending')
        ->assertJsonPath('data.advance_payment.payment_confirmed', false)->assertJsonMissingPath('data.advance_payment.stripe_account_id');
    clientIntent($id);
    $this->postJson("/api/v1/customer/orders/$id/payment")->assertOk()->assertJsonPath('data.stripe_account_id', 'acct_A')
        ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.amount', '113.30')->assertJsonPath('data.publishable_key', 'pk_test_local');
    expect($this->intentCreates)->toBe(1);
    app(RestaurantContext::class)->run($this->a, function () {
        expect($this->ingredientA->fresh()->current_stock)->toBe('50.000')->and(InventoryMovement::count())->toBe(0);
    });
    $this->actingAs($this->chefA, 'sanctum')->getJson('/api/v1/chef/queue')->assertOk()->assertJsonCount(0, 'data');
});

test('signed Connect success fulfills exactly once credits integer consumption points and reaches chef polling', function () {
    $id = clientOrder();
    clientIntent($id);
    clientEvent($id)->assertOk();
    clientEvent($id)->assertOk();
    expect($this->refunds)->toBe(0);
    $this->getJson("/api/v1/customer/orders/$id")->assertOk()->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.advance_payment.payment_confirmed', true)->assertJsonPath('data.preparation_status', 'pending');
    app(RestaurantContext::class)->run($this->a, function () {
        expect($this->ingredientA->fresh()->current_stock)->toBe('48.000')->and(InventoryMovement::count())->toBe(1)
            ->and(Payment::sole()->amount)->toBe('113.30')->and(LoyaltyBalance::sole()->points_balance)->toBe('200.00')
            ->and(LoyaltyTransaction::sole()->points)->toBe('200.00')->and((string) $this->cashA->expectedCash())->toBe('20.00');
    });
    $this->actingAs($this->chefA, 'sanctum');
    $item = $this->getJson('/api/v1/chef/queue')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
    $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertOk();
    $this->actingAs($this->customer->user, 'sanctum')->getJson("/api/v1/customer/orders/$id")->assertOk()->assertJsonPath('data.preparation_status', 'in_preparation');
    $this->actingAs($this->chefA, 'sanctum')->patchJson("/api/v1/chef/queue/$item", ['status' => 'ready'])->assertOk();
    $this->actingAs($this->customer->user, 'sanctum')->getJson("/api/v1/customer/orders/$id")->assertOk()->assertJsonPath('data.preparation_status', 'ready');
    $this->postJson("/api/v1/customer/orders/$id/payment")->assertConflict();
});

test('order submission is idempotent by customer restaurant key and cart content', function () {
    $key = (string) Str::uuid();
    $first = clientOrder(key: $key);
    expect(clientOrder(key: $key))->toBe($first);
    $this->postJson('/api/v1/customer/restaurants/'.$this->a->id.'/orders', ['items' => [['menu_item_id' => $this->menuA->id, 'quantity' => 2]]],
        ['Idempotency-Key' => $key])->assertConflict();
    $this->actingAs($this->other->user, 'sanctum');
    $this->postJson('/api/v1/customer/restaurants/'.$this->a->id.'/orders', ['items' => [['menu_item_id' => $this->menuA->id, 'quantity' => 1]]],
        ['Idempotency-Key' => $key])->assertConflict();
    expect(Order::withoutGlobalScope('restaurant')->count())->toBe(1);
});

test('prices taxes currency and loyalty snapshot cannot change under an issued payment', function () {
    $id = clientOrder();
    clientIntent($id);
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->update(['sale_price' => '200.00']);
    TaxClass::query()->update(['percentage' => '20.00']);
    $this->a->update(['loyalty_factor' => '5.0000']);
    config(['services.stripe.accounts.'.$this->a->id => 'acct_Replaced']);
    clientEvent($id)->assertOk();
    $this->actingAs($this->customer->user, 'sanctum')->getJson("/api/v1/customer/orders/$id")->assertOk()
        ->assertJsonPath('data.total', '113.30')->assertJsonPath('data.tax_total', '10.00')->assertJsonPath('data.items.0.unit_price', '100.00');
    app(RestaurantContext::class)->run($this->a, fn () => expect(LoyaltyBalance::sole()->points_balance)->toBe('200.00'));
});

test('own history and balances are isolated across customers and restaurants including deleted restaurant history', function () {
    $a = clientOrder();
    clientIntent($a);
    clientEvent($a)->assertOk();
    $b = clientOrder($this->b, $this->menuB);
    clientIntent($b);
    clientEvent($b)->assertOk();
    $this->getJson('/api/v1/customer/orders')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/customer/loyalty-balances')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.points_balance', '200.00')->assertJsonPath('data.1.points_balance', '0.00');
    $this->a->delete();
    $this->getJson("/api/v1/customer/orders/$a")->assertOk()->assertJsonPath('data.items.0.name', 'Menu A');
    $this->actingAs($this->other->user, 'sanctum');
    $this->getJson('/api/v1/customer/orders')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/customer/loyalty-balances')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/customer/orders/$a")->assertNotFound();
    $this->postJson("/api/v1/customer/orders/$b/payment")->assertNotFound();
});

test('foreign restaurant menu IDs and forged monetary or ownership fields are rejected', function (array $override, string $key) {
    $payload = array_replace(['items' => [['menu_item_id' => $this->menuA->id, 'quantity' => 1]]], $override);
    if ($key === 'foreign') {
        $payload['items'][0]['menu_item_id'] = $this->menuB->id;
        $key = 'items';
    }
    $this->postJson('/api/v1/customer/restaurants/'.$this->a->id.'/orders', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors($key);
    expect(Order::withoutGlobalScope('restaurant')->count())->toBe(0);
})->with([
    [[], 'foreign'], [['total' => 1], 'total'], [['customer_id' => 1], 'customer_id'],
    [['stripe_account_id' => 'acct_evil'], 'stripe_account_id'], [['payment_confirmed' => true], 'payment_confirmed'],
    [['cash_register_id' => 1], 'cash_register_id'], [['service_type' => 'delivery'], 'service_type'],
    [['tip_amount' => 1], 'tip_amount'], [['redeem_points' => 10], 'redeem_points'],
    [['promotion_id' => 1], 'promotion_id'], [['items' => []], 'items'],
    [['items' => [['menu_item_id' => 0, 'quantity' => 1]]], 'items'],
    [['items' => [['menu_item_id' => 1, 'quantity' => 0]]], 'items.0.quantity'],
    [['items' => [['menu_item_id' => 1, 'quantity' => '1.5']]], 'items.0.quantity'],
    [['items' => [['menu_item_id' => 1, 'quantity' => 1, 'unit_price' => 1]]], 'items.0'],
]);

test('a UUID idempotency header is required and an arbitrary body key does not override it', function (string $key) {
    $this->postJson('/api/v1/customer/restaurants/'.$this->a->id.'/orders',
        ['request_key' => (string) Str::uuid(), 'items' => [['menu_item_id' => $this->menuA->id, 'quantity' => 1]]],
        ['Idempotency-Key' => $key])->assertUnprocessable()->assertJsonValidationErrors('request_key');
})->with(['', 'arbitrary']);

test('cart creation validates open cash stock and configured Connect account', function (string $state, int $status) {
    if ($state === 'cash') {
        app(RestaurantContext::class)->run($this->a, fn () => $this->cashA->update(['closed_at' => now()]));
    } elseif ($state === 'stock') {
        app(RestaurantContext::class)->run($this->a, fn () => $this->ingredientA->update(['current_stock' => '1.000']));
    } else {
        config(['services.stripe.accounts' => []]);
    }
    $this->postJson('/api/v1/customer/restaurants/'.$this->a->id.'/orders',
        ['items' => [['menu_item_id' => $this->menuA->id, 'quantity' => 1]]], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus($status);
    expect(Order::withoutGlobalScope('restaurant')->count())->toBe(0);
})->with([['cash', 409], ['stock', 422], ['account', 503]]);

test('Stripe signatures replay tolerance and mode are enforced before any fulfillment', function (string $case) {
    $id = clientOrder();
    clientIntent($id);
    $response = match ($case) {
        'signature' => clientEvent($id, secret: 'bad'),
        'expired' => clientEvent($id, offset: -600),
        'mode' => clientEvent($id, eventOverrides: ['livemode' => true]),
    };
    $response->assertBadRequest();
    expect(AdvanceOrderPayment::withoutGlobalScope('restaurant')->sole()->payment_confirmed)->toBeFalse();
})->with(['signature', 'expired', 'mode']);

test('signed mismatched payment values cannot fulfill an order', function (array $changes) {
    $id = clientOrder();
    clientIntent($id);
    clientEvent($id, $changes)->assertBadRequest();
    expect(AdvanceOrderPayment::withoutGlobalScope('restaurant')->sole()->payment_confirmed)->toBeFalse();
})->with([
    [['amount' => 1]], [['currency' => 'mxn']], [['amount_received' => 1]], [['status' => 'processing']],
    [['metadata' => ['delishio_order_id' => '99999']]],
]);

test('events from a different connected account unknown intents or unrelated types have no effect', function (string $case) {
    $id = clientOrder();
    clientIntent($id);
    $response = match ($case) {
        'account' => clientEvent($id, eventOverrides: ['account' => 'acct_B']),
        'unknown' => clientEvent($id, ['id' => 'pi_unknown']),
        'failed' => clientEvent($id, eventOverrides: ['type' => 'payment_intent.payment_failed']),
    };
    $response->assertOk();
    expect(AdvanceOrderPayment::withoutGlobalScope('restaurant')->sole()->payment_confirmed)->toBeFalse()->and($this->refunds)->toBe(0);
    $this->postJson("/api/v1/customer/orders/$id/payment")->assertOk();
    expect($this->intentCreates)->toBe(1);
})->with(['account', 'unknown', 'failed']);

test('paid orders that can no longer be fulfilled are cancelled and refunded without stock points or sales', function (string $state) {
    $id = clientOrder();
    clientIntent($id);
    if ($state === 'stock') {
        app(RestaurantContext::class)->run($this->a, fn () => $this->ingredientA->update(['current_stock' => '0.000']));
    } elseif ($state === 'restaurant') {
        $this->a->update(['status' => 'suspended']);
    } elseif ($state === 'deleted_restaurant') {
        $this->a->delete();
    } elseif ($state === 'customer') {
        $this->customer->user->forceFill(['active' => false])->save();
    } elseif ($state === 'points_overflow') {
        app(RestaurantContext::class)->run($this->a, fn () => LoyaltyBalance::create(['customer_id' => $this->customer->user_id, 'points_balance' => '9999999999.99']));
    } else {
        $this->actingAs($this->adminA, 'sanctum');
        $this->menuA->update(['active' => false]);
    }
    clientEvent($id)->assertOk();
    clientEvent($id)->assertOk();
    $payment = AdvanceOrderPayment::withoutGlobalScope('restaurant')->sole();
    expect($payment->refunded_at)->not->toBeNull()->and($payment->refund_pending)->toBeFalse()
        ->and($payment->payment_confirmed)->toBeFalse()->and($this->refunds)->toBe(1)
        ->and(Order::withoutGlobalScope('restaurant')->sole()->status)->toBe('cancelled')
        ->and(DB::table('inventory_movements')->count())->toBe(0)->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('loyalty_transactions')->count())->toBe(0);
})->with(['stock', 'restaurant', 'deleted_restaurant', 'customer', 'points_overflow', 'menu']);

test('abandoned unpaid app orders do not block cash closing and a late payment is refunded', function () {
    $id = clientOrder();
    clientIntent($id);
    $this->actingAs($this->waiterA, 'sanctum')->postJson('/api/v1/cash-registers/'.$this->cashA->id.'/close', ['counted_cash' => 20])->assertOk();
    clientEvent($id)->assertOk();
    expect($this->refunds)->toBe(1)->and(Order::withoutGlobalScope('restaurant')->sole()->status)->toBe('cancelled');
});

test('rejection is durable when refund HTTP fails and retries cannot fulfill after inventory recovers', function () {
    $id = clientOrder();
    clientIntent($id);
    app(RestaurantContext::class)->run($this->a, fn () => $this->ingredientA->update(['current_stock' => '0.000']));
    $this->refundFailure = true;
    clientEvent($id)->assertStatus(503);
    expect(AdvanceOrderPayment::withoutGlobalScope('restaurant')->sole()->refund_pending)->toBeTrue();
    $this->refundFailure = false;
    app(RestaurantContext::class)->run($this->a, fn () => $this->ingredientA->update(['current_stock' => '50.000']));
    clientEvent($id)->assertOk();
    expect(Order::withoutGlobalScope('restaurant')->sole()->status)->toBe('cancelled')->and(DB::table('inventory_movements')->count())->toBe(0)
        ->and($this->refunds)->toBe(2);
});

test('pending refunds remain visible and the scheduled retry can finish them', function () {
    $id = clientOrder();
    clientIntent($id);
    app(RestaurantContext::class)->run($this->a, fn () => $this->ingredientA->update(['current_stock' => '0.000']));
    $this->refundStatus = 'pending';
    clientEvent($id)->assertOk();
    $this->getJson("/api/v1/customer/orders/$id")->assertOk()->assertJsonPath('data.advance_payment.refund_pending', true)
        ->assertJsonPath('data.advance_payment.refunded_at', null)->assertJsonMissingPath('data.advance_payment.refund_reference');
    $this->refundStatus = 'succeeded';
    $this->artisan('payments:retry-refunds')->assertExitCode(0);
    expect(AdvanceOrderPayment::withoutGlobalScope('restaurant')->sole()->refunded_at)->not->toBeNull();
});

test('unexpected database failure rolls back all fulfillment and a webhook retry succeeds once', function () {
    $id = clientOrder();
    clientIntent($id);
    foreach ([LoyaltyBalance::class, LoyaltyTransaction::class, Payment::class, InventoryMovement::class] as $model) {
        $model::query();
    }
    $dispatcher = AdvanceOrderPayment::getEventDispatcher();
    AdvanceOrderPayment::setEventDispatcher(clone $dispatcher);
    AdvanceOrderPayment::updated(fn () => throw new RuntimeException('failed commit'));
    $this->withoutExceptionHandling();
    try {
        expect(fn () => clientEvent($id))->toThrow(RuntimeException::class, 'failed commit');
    } finally {
        AdvanceOrderPayment::setEventDispatcher($dispatcher);
    }
    expect(DB::table('payments')->count())->toBe(0)->and(DB::table('inventory_movements')->count())->toBe(0)
        ->and(DB::table('loyalty_transactions')->count())->toBe(0);
    clientEvent($id)->assertOk();
    expect(DB::table('payments')->count())->toBe(1)->and(DB::table('inventory_movements')->count())->toBe(1);
});

test('only active verified customers can use customer endpoints', function (string $actor, int $status) {
    if ($actor === 'guest') {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();
    } else {
        $user = match ($actor) {
            'admin' => $this->adminA, 'waiter' => $this->waiterA, 'chef' => $this->chefA,
            'inactive' => $this->customer->user->forceFill(['active' => false]),
            'unverified' => $this->customer->user->forceFill(['email_verified_at' => null]),
            default => User::factory()->create(['user_type' => 'super_admin']),
        };
        $this->actingAs($user, 'sanctum');
    }
    $this->getJson('/api/v1/customer/restaurants')->assertStatus($status);
    $this->getJson('/api/v1/customer/orders')->assertStatus($status);
    $this->getJson('/api/v1/customer/loyalty-balances')->assertStatus($status);
})->with([['guest', 401], ['admin', 403], ['waiter', 403], ['chef', 403], ['super_admin', 403], ['inactive', 403], ['unverified', 403]]);

test('current promotions honor restaurant dates weekdays and overnight windows', function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay()->addMinutes(30));
    app(RestaurantContext::class)->run($this->a, function () {
        Promotion::factory()->create(['name' => 'Monday late', 'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09',
            'days_of_week' => 'Mon', 'time_start' => '22:00:00', 'time_end' => '02:00:00']);
        Promotion::factory()->create(['name' => 'Tuesday daytime', 'valid_from' => '2026-11-10', 'valid_to' => '2026-11-10',
            'days_of_week' => 'Tue', 'time_start' => '10:00:00', 'time_end' => '12:00:00']);
        Promotion::factory()->create(['name' => 'Inactive', 'active' => false, 'valid_from' => '2026-11-01', 'valid_to' => '2026-11-30']);
    });
    app(RestaurantContext::class)->run($this->b, fn () => Promotion::factory()->create([
        'name' => 'B promotion', 'valid_from' => '2026-11-01', 'valid_to' => '2026-11-30']));
    $this->getJson('/api/v1/customer/restaurants/'.$this->a->id.'/promotions')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Monday late');
    $this->travel(3)->hours();
    $this->getJson('/api/v1/customer/restaurants/'.$this->a->id.'/promotions')->assertOk()->assertJsonCount(0, 'data');
    $this->travelTo(now()->setTime(11, 0));
    $this->getJson('/api/v1/customer/restaurants/'.$this->a->id.'/promotions')->assertOk()->assertJsonPath('data.0.name', 'Tuesday daytime');
});

test('inactive menus and deleted catalog ancestors are not offered to customers', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->update(['active' => false]);
    $this->actingAs($this->customer->user, 'sanctum')->getJson('/api/v1/customer/restaurants/'.$this->a->id.'/menu')->assertOk()->assertJsonCount(0, 'data');
    DB::table('menu_items')->where('id', $this->menuA->id)->update(['active' => true]);
    DB::table('categories')->where('id', $this->menuA->category_id)->update(['deleted_at' => now()]);
    $this->getJson('/api/v1/customer/restaurants/'.$this->a->id.'/menu')->assertOk()->assertJsonCount(0, 'data');
});

test('payment endpoints reject server fields and other owners without exposing client secrets', function () {
    $id = clientOrder();
    $this->postJson("/api/v1/customer/orders/$id/payment", ['amount' => 1, 'stripe_account_id' => 'acct_B'])->assertUnprocessable();
    $this->actingAs($this->other->user, 'sanctum')->postJson("/api/v1/customer/orders/$id/payment")->assertNotFound();
    expect($this->intentCreates)->toBe(0);
});

test('aged orders cannot create a new Stripe intent after the idempotency retention window', function () {
    $id = clientOrder();
    DB::table('advance_order_payments')->where('order_id', $id)->update(['created_at' => now()->subDay()]);
    $this->postJson("/api/v1/customer/orders/$id/payment")->assertConflict();
    expect($this->intentCreates)->toBe(0);
});

test('a cancelled intent cancels only an unpaid order and stale cancellation cannot undo fulfillment', function () {
    $first = clientOrder();
    clientIntent($first);
    clientEvent($first, ['status' => 'canceled', 'amount_received' => 0], ['type' => 'payment_intent.canceled'])->assertOk();
    $this->getJson("/api/v1/customer/orders/$first")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/customer/orders/$first/payment")->assertConflict();
    $second = clientOrder();
    clientIntent($second);
    clientEvent($second)->assertOk();
    clientEvent($second, ['status' => 'canceled', 'amount_received' => 0], ['type' => 'payment_intent.canceled'])->assertOk();
    $this->getJson("/api/v1/customer/orders/$second")->assertOk()->assertJsonPath('data.status', 'closed');
    expect($this->refunds)->toBe(0)->and(DB::table('payments')->count())->toBe(1);
});

test('customer preparation aggregates kitchen and bar without declaring ready too early', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $barMenu = MenuItem::factory()->create(['category_id' => $this->menuA->category_id, 'preparation_station' => 'bar', 'sale_price' => '50.00']);
    $barChef = Employee::factory()->create(['role' => 'chef', 'station' => 'bar'])->user;
    $this->actingAs($this->customer->user, 'sanctum');
    $id = $this->postJson('/api/v1/customer/restaurants/'.$this->a->id.'/orders', ['items' => [
        ['menu_item_id' => $this->menuA->id, 'quantity' => 1], ['menu_item_id' => $barMenu->id, 'quantity' => 1]]],
        ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    clientIntent($id);
    clientEvent($id)->assertOk();
    foreach ([$this->chefA, $barChef] as $index => $chef) {
        $this->actingAs($chef, 'sanctum');
        $item = $this->getJson('/api/v1/chef/queue')->assertOk()->json('data.0.id');
        $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'in_preparation'])->assertOk();
        $this->patchJson("/api/v1/chef/queue/$item", ['status' => 'ready'])->assertOk();
        $this->actingAs($this->customer->user, 'sanctum')->getJson("/api/v1/customer/orders/$id")->assertOk()
            ->assertJsonPath('data.preparation_status', $index === 0 ? 'in_preparation' : 'ready');
    }
    $this->getJson('/api/v1/customer/orders')->assertOk()->assertJsonPath('data.0.preparation_status', 'ready');
});

test('items needing no preparation are ready only after verified payment', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->menuA->update(['preparation_station' => 'none']);
    $this->actingAs($this->customer->user, 'sanctum');
    $id = clientOrder();
    $this->getJson("/api/v1/customer/orders/$id")->assertOk()->assertJsonPath('data.preparation_status', 'pending');
    clientIntent($id);
    clientEvent($id)->assertOk();
    $this->getJson("/api/v1/customer/orders/$id")->assertOk()->assertJsonPath('data.preparation_status', 'ready');
});

test('stock validation aggregates repeated ingredients across the entire customer cart', function () {
    $payload = ['items' => [['menu_item_id' => $this->menuA->id, 'quantity' => 15], ['menu_item_id' => $this->menuA->id, 'quantity' => 15]]];
    $this->postJson('/api/v1/customer/restaurants/'.$this->a->id.'/orders', $payload,
        ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('inventory');
    expect(Order::withoutGlobalScope('restaurant')->count())->toBe(0)->and($this->intentCreates)->toBe(0);
});

test('fractional loyalty factors issue whole consumption points rather than points on tax or the card adjustment', function () {
    $this->a->update(['loyalty_factor' => '1.2345']);
    $id = clientOrder();
    clientIntent($id);
    clientEvent($id)->assertOk();
    $this->getJson('/api/v1/customer/loyalty-balances')->assertOk()->assertJsonPath('data.0.points_balance', '123.00');
});

test('the customer cannot trigger waiter confirmation or submit card details to the backend', function () {
    $id = clientOrder();
    $this->postJson("/api/v1/waiter/orders/$id/confirm")->assertForbidden();
    $this->postJson("/api/v1/customer/orders/$id/payment", ['card_number' => 'not-a-card'])->assertUnprocessable();
    expect(DB::table('inventory_movements')->count())->toBe(0)->and($this->intentCreates)->toBe(0);
});
