<?php

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\LoyaltyBalance;
use App\Models\LoyaltyTransaction;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\SuperCategory;
use App\Models\TaxClass;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function checkoutOrder(bool $sent = true): int
{
    $id = test()->postJson('/api/v1/waiter/orders', ['service_type' => 'takeout'])->assertCreated()->json('data.id');
    test()->postJson("/api/v1/waiter/orders/$id/items", ['menu_item_id' => test()->menu->id, 'quantity' => 1])->assertCreated();
    if ($sent) {
        test()->postJson("/api/v1/waiter/orders/$id/confirm")->assertOk();
    }

    return $id;
}

function checkoutPayments(string $amount = '110.00', string $method = 'cash'): array
{
    return ['payments' => [['method' => $method, 'amount' => $amount]]];
}

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->a = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id, 'currency' => 'USD',
        'loyalty_enabled' => true, 'loyalty_factor' => '2.0000', 'loyalty_redeem_value' => '10.0000',
        'dual_pricing_enabled' => true, 'dual_pricing_percentage' => '3.00', 'discount_limit_per_shift' => '50.00']);
    $this->b = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->admin = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'admin', 'pin' => '1234']))->user;
    $this->waiter = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create())->user;
    $this->other = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create())->user;
    $this->foreignWaiter = app(RestaurantContext::class)->run($this->b, fn () => Employee::factory()->create())->user;
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->admin, 'sanctum');
    $tax = TaxClass::factory()->create(['percentage' => '10.00']);
    $category = Category::factory()->create(['super_category_id' => SuperCategory::factory()->create(['tax_class_id' => $tax->id])->id]);
    $this->menu = MenuItem::factory()->create(['category_id' => $category->id, 'sale_price' => '100.00']);
    $this->cash = CashRegister::factory()->create(['opening_amount' => '20.00']);
    $this->actingAs($this->waiter, 'sanctum');
});

test('cash checkout closes atomically and feeds register cash including the tip exactly once', function () {
    $id = checkoutOrder();
    $url = "/api/v1/waiter/orders/$id";
    $payload = checkoutPayments('120.25') + ['tip_amount' => '10.25'];
    $this->postJson("$url/close", $payload)->assertOk()->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.tip_amount', '10.25')->assertJsonPath('data.total', '120.25')
        ->assertJsonPath('data.surcharge_total', '0.00')->assertJsonPath('data.payments.0.amount', '120.25')
        ->assertJsonCount(0, 'data.loyalty_transactions');
    $this->postJson("$url/close", $payload)->assertConflict();
    expect(Payment::count())->toBe(1)->and((string) $this->cash->expectedCash())->toBe('140.25');
    $this->getJson($url)->assertOk()->assertJsonPath('data.payments.0.method', 'cash');
    $this->getJson('/api/v1/waiter/orders')->assertOk()->assertJsonPath('data.0.payments.0.amount', '120.25');
    $this->postJson('/api/v1/cash-registers/'.$this->cash->id.'/close', ['counted_cash' => '140.25'])->assertOk()
        ->assertJsonPath('data.expected_cash', '140.25')->assertJsonPath('data.difference', '0.00');
});

test('dual pricing applies only to actual card funds in a mixed settlement', function () {
    $id = checkoutOrder();
    $payload = ['payments' => [['method' => 'cash', 'amount' => '40.00'], ['method' => 'transfer', 'amount' => '20.00'],
        ['method' => 'card', 'amount' => '60.00']], 'tip_amount' => '10.00'];
    $this->postJson("/api/v1/waiter/orders/$id/checkout-preview", $payload)->assertOk()
        ->assertJsonPath('data.surcharge_total', '1.50')->assertJsonPath('data.total', '121.50')
        ->assertJsonPath('data.payment_difference', '0.00')->assertJsonPath('data.currency', 'USD');
    expect(Payment::count())->toBe(0)->and(Order::findOrFail($id)->status)->toBe('sent');
    $this->postJson("/api/v1/waiter/orders/$id/close", $payload)->assertOk()
        ->assertJsonPath('data.surcharge_total', '1.50')->assertJsonPath('data.total', '121.50')->assertJsonCount(3, 'data.payments');
    expect((string) $this->cash->expectedCash())->toBe('60.00');
});

test('single method card and transfer honor the configured dual pricing switch', function (string $method, bool $enabled, string $amount, string $surcharge) {
    $this->a->update(['dual_pricing_enabled' => $enabled]);
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('110.00', $method))->assertOk()
        ->assertJsonPath('data.total', $amount)->assertJsonPath('data.surcharge_total', $surcharge);
    expect((string) $this->cash->expectedCash())->toBe('20.00');
})->with([['card', true, '113.30', '3.30'], ['card', false, '110.00', '0.00'], ['transfer', true, '110.00', '0.00']]);

test('QR links a global customer and only creates the local affiliation', function () {
    app(RestaurantContext::class)->run($this->b, fn () => LoyaltyBalance::create(['customer_id' => $this->customer->user_id, 'points_balance' => '999.00']));
    $id = checkoutOrder(false);
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk()
        ->assertJsonPath('data.customer_id', $this->customer->user_id)->assertJsonMissingPath('data.customer.email');
    expect(LoyaltyBalance::sole()->points_balance)->toBe('0.00');
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    expect(LoyaltyBalance::count())->toBe(1);
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/customers')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.points_balance', '0.00');
});

test('redemption and earning use separate immutable local ledger entries excluding tip and card adjustment', function () {
    $id = checkoutOrder();
    LoyaltyBalance::create(['customer_id' => $this->customer->user_id, 'points_balance' => '200.00']);
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    $payload = checkoutPayments('109.00', 'card') + ['tip_amount' => '10.00', 'redeem_points' => '100.00'];
    $this->postJson("/api/v1/waiter/orders/$id/checkout-preview", $payload)->assertOk()
        ->assertJsonPath('data.loyalty_redeemed_amount', '10.00')->assertJsonPath('data.points_earned', '180.00')
        ->assertJsonPath('data.points_balance', '280.00')->assertJsonPath('data.surcharge_total', '2.97');
    expect(LoyaltyBalance::sole()->points_balance)->toBe('200.00')->and(LoyaltyTransaction::count())->toBe(0);
    $this->postJson("/api/v1/waiter/orders/$id/close", $payload)->assertOk()->assertJsonPath('data.discount_total', '0.00')->assertJsonPath('data.loyalty_redeemed_amount', '10.00')
        ->assertJsonCount(2, 'data.loyalty_transactions')->assertJsonPath('data.loyalty_transactions.0.type', 'redeem')
        ->assertJsonPath('data.loyalty_transactions.0.points', '100.00')->assertJsonPath('data.loyalty_transactions.1.points', '180.00');
    expect(LoyaltyBalance::sole()->points_balance)->toBe('280.00');
    $this->postJson("/api/v1/waiter/orders/$id/close", $payload)->assertConflict();
    expect(LoyaltyTransaction::count())->toBe(2);
});

test('full redemption accepts no payments and does not earn points on redeemed consumption', function () {
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    LoyaltyBalance::sole()->update(['points_balance' => '1000.00']);
    $this->postJson("/api/v1/waiter/orders/$id/close", ['payments' => [], 'redeem_points' => '1000'])->assertOk()
        ->assertJsonPath('data.total', '0.00')->assertJsonCount(0, 'data.payments')->assertJsonCount(1, 'data.loyalty_transactions');
    expect(LoyaltyBalance::sole()->points_balance)->toBe('0.00');
});

test('points round down to integers and cannot buy tip or exceed available local balance', function () {
    $this->a->update(['loyalty_redeem_value' => '3.0000', 'loyalty_factor' => '0.1234']);
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    LoyaltyBalance::sole()->update(['points_balance' => '400.00']);
    $this->postJson("/api/v1/waiter/orders/$id/checkout-preview", checkoutPayments('109.64') + ['redeem_points' => 1])->assertOk()
        ->assertJsonPath('data.loyalty_redeemed_amount', '0.33')->assertJsonPath('data.points_earned', '12.00');
    foreach (['0.01', '300.03', '401.00'] as $points) {
        $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('1') + ['redeem_points' => $points, 'tip_amount' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('redeem_points');
    }
    expect(LoyaltyBalance::sole()->points_balance)->toBe('400.00')->and(Payment::count())->toBe(0);
});

test('another restaurant balance cannot fund a redemption or be mutated by earning', function () {
    app(RestaurantContext::class)->run($this->b, fn () => LoyaltyBalance::create(['customer_id' => $this->customer->user_id, 'points_balance' => '9999.00']));
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('99') + ['redeem_points' => 100])->assertUnprocessable();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertOk();
    expect(LoyaltyBalance::sole()->points_balance)->toBe('200.00');
    app(RestaurantContext::class)->run($this->b, function () {
        expect(LoyaltyBalance::sole()->points_balance)->toBe('9999.00')->and(LoyaltyTransaction::count())->toBe(0)->and(Payment::count())->toBe(0);
    });
});

test('disabled loyalty still allows customer linking and ordinary settlement but rejects redemption', function () {
    $this->a->update(['loyalty_enabled' => false]);
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    LoyaltyBalance::sole()->update(['points_balance' => '100.00']);
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('109.00') + ['redeem_points' => 10])->assertUnprocessable();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertOk()->assertJsonCount(0, 'data.loyalty_transactions');
    expect(LoyaltyBalance::sole()->points_balance)->toBe('100.00');
});

test('payment deficits surpluses and empty settlements roll back all state', function (array $payments) {
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/close", ['payments' => $payments])->assertUnprocessable()->assertJsonValidationErrors('payments');
    expect(Payment::count())->toBe(0)->and(Order::findOrFail($id)->closed_at)->toBeNull();
})->with([[[]], [[['method' => 'cash', 'amount' => '109.99']]], [[['method' => 'cash', 'amount' => '110.01']]]]);

test('checkout input validates money scales methods and server owned fields', function (array $override, string $key) {
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/close", array_replace(checkoutPayments(), $override))->assertUnprocessable()->assertJsonValidationErrors($key);
    expect(Payment::count())->toBe(0);
})->with([
    [['payments' => null], 'payments'], [['payments' => [['method' => 'online', 'amount' => 110]]], 'payments.0.method'],
    [['payments' => [['method' => 'cash', 'amount' => 0]]], 'payments.0.amount'],
    [['payments' => [['method' => 'cash', 'amount' => '-1']]], 'payments.0.amount'],
    [['payments' => [['method' => 'cash', 'amount' => '1.001']]], 'payments.0.amount'],
    [['payments' => [['method' => 'cash', 'amount' => '100000000']]], 'payments.0.amount'],
    [['payments' => [['method' => 'cash', 'amount' => 50], ['method' => 'cash', 'amount' => 60]]], 'payments.0.method'],
    [['payments' => [['method' => 'cash', 'amount' => 110, 'order_id' => 1]]], 'payments.0'],
    [['tip_amount' => '-0.01'], 'tip_amount'], [['tip_amount' => '1.001'], 'tip_amount'],
    [['redeem_points' => '1.001'], 'redeem_points'], [['redeem_points' => '-1'], 'redeem_points'],
    [['redeem_points' => '10000000000'], 'redeem_points'], [['total' => 0], 'total'],
    [['discount_total' => 100], 'discount_total'], [['customer_id' => 1], 'customer_id'],
    [['loyalty_redeemed_amount' => 100], 'loyalty_redeemed_amount'], [['surcharge_total' => 0], 'surcharge_total'],
    [['dual_pricing_applied' => false], 'dual_pricing_applied'], [['loyalty_factor' => 100], 'loyalty_factor'],
]);

test('orders in another restaurant or owned by another waiter are hidden for all checkout actions', function (string $actor) {
    $id = checkoutOrder();
    $this->actingAs($this->{$actor}, 'sanctum');
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertNotFound();
    $this->postJson("/api/v1/waiter/orders/$id/checkout-preview", checkoutPayments())->assertNotFound();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertNotFound();
    expect(Payment::count())->toBe(0);
})->with(['other', 'foreignWaiter']);

test('checkout endpoints are restricted to active verified waiters', function (string $actor, int $status) {
    $id = checkoutOrder();
    if ($actor === 'guest') {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();
    } else {
        $user = match ($actor) {
            'admin' => $this->admin,
            'customer' => $this->customer->user,
            'chef' => app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'chef', 'station' => 'kitchen']))->user,
            default => User::factory()->create(['user_type' => 'super_admin']),
        };
        $this->actingAs($user, 'sanctum');
    }
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertStatus($status);
    $this->postJson("/api/v1/waiter/orders/$id/checkout-preview", checkoutPayments())->assertStatus($status);
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertStatus($status);
})->with([['guest', 401], ['admin', 403], ['customer', 403], ['chef', 403], ['super_admin', 403]]);

test('unconfirmed cancelled closed or closed cash register orders cannot settle', function (string $state) {
    $id = checkoutOrder($state !== 'draft');
    if ($state === 'cancelled') {
        $this->postJson("/api/v1/waiter/orders/$id/cancel", ['reason' => 'Cancelada', 'admin_user_id' => $this->admin->id, 'pin' => '1234'])->assertOk();
    } elseif ($state === 'closed') {
        $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertOk();
    } elseif ($state === 'cash_closed') {
        DB::table('cash_registers')->where('id', $this->cash->id)->update(['closed_at' => now()]);
    }
    $this->postJson("/api/v1/waiter/orders/$id/checkout-preview", checkoutPayments())->assertConflict();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertConflict();
    if ($state === 'closed' || $state === 'cancelled' || $state === 'cash_closed') {
        $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertConflict();
    }
})->with(['draft', 'cancelled', 'closed', 'cash_closed']);

test('invalid missing inactive unverified or deleted customer QR cannot link', function (string $state) {
    $id = checkoutOrder();
    $qr = $this->customer->qr_code;
    if ($state === 'unknown') {
        $qr = (string) Str::uuid();
    } elseif ($state === 'malformed') {
        $qr = 'bad';
    } elseif ($state === 'inactive') {
        $this->customer->user->forceFill(['active' => false])->save();
    } elseif ($state === 'unverified') {
        $this->customer->user->forceFill(['email_verified_at' => null])->save();
    } else {
        $this->customer->user->delete();
    }
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $qr])->assertUnprocessable();
    expect(Order::findOrFail($id)->customer_id)->toBeNull()->and(LoyaltyBalance::count())->toBe(0);
})->with(['unknown', 'malformed', 'inactive', 'unverified', 'deleted']);

test('deactivated linked customers cannot earn or redeem during closing', function () {
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    $this->customer->user->forceFill(['active' => false])->save();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertUnprocessable();
    expect(Payment::count())->toBe(0);
});

test('unexpected failure rolls back payments ledger balance and order together', function (string $failAt) {
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    LoyaltyBalance::sole()->update(['points_balance' => '100.00']);
    $class = $failAt === 'ledger' ? LoyaltyTransaction::class : Order::class;
    $dispatcher = $class::getEventDispatcher();
    $class::setEventDispatcher(clone $dispatcher);
    if ($failAt === 'ledger') {
        $class::created(fn () => throw new RuntimeException('fail checkout'));
    } else {
        $class::updated(fn () => throw new RuntimeException('fail checkout'));
    }
    $this->withoutExceptionHandling();
    try {
        expect(fn () => $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('99') + ['redeem_points' => 100]))
            ->toThrow(RuntimeException::class, 'fail checkout');
    } finally {
        $class::setEventDispatcher($dispatcher);
    }
    expect(Payment::count())->toBe(0)->and(LoyaltyTransaction::count())->toBe(0)
        ->and(LoyaltyBalance::sole()->points_balance)->toBe('100.00')->and(Order::findOrFail($id)->status)->toBe('sent');
})->with(['ledger', 'order']);

test('balance and monetary overflow fail before committing any payment', function (string $field) {
    $id = checkoutOrder();
    $payload = checkoutPayments();
    if ($field === 'points') {
        $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
        LoyaltyBalance::sole()->update(['points_balance' => '9999999999.99']);
    } else {
        $payload['tip_amount'] = '99999999.99';
    }
    $this->postJson("/api/v1/waiter/orders/$id/close", $payload)->assertUnprocessable();
    expect(Payment::count())->toBe(0);
})->with(['points', 'total']);

test('payment and ledger model scopes prevent foreign parent writes and reads', function () {
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertOk();
    $payment = Payment::sole();
    $ledger = LoyaltyTransaction::sole();
    expect(fn () => $payment->update(['amount' => 1]))->toThrow(HttpException::class);
    expect(fn () => $ledger->delete())->toThrow(HttpException::class);
    $this->actingAs($this->foreignWaiter, 'sanctum');
    expect(Payment::count())->toBe(0)->and(LoyaltyTransaction::count())->toBe(0);
    expect(fn () => Payment::create(['order_id' => $id, 'method' => 'cash', 'amount' => 1]))->toThrow(HttpException::class);
    expect(fn () => LoyaltyTransaction::create(['order_id' => $id, 'customer_id' => $this->customer->user_id, 'type' => 'earn', 'points' => 1]))
        ->toThrow(HttpException::class);
});

test('discounted and tax exempt confirmed totals stay frozen during checkout', function () {
    $id = checkoutOrder(false);
    WorkShift::factory()->create(['employee_id' => $this->waiter->id]);
    $this->postJson("/api/v1/waiter/orders/$id/discounts", ['amount' => 10, 'admin_user_id' => $this->admin->id, 'pin' => '1234'])->assertCreated();
    $this->postJson("/api/v1/waiter/orders/$id/tax-exemptions", ['reason' => 'Exento'])->assertCreated();
    $this->postJson("/api/v1/waiter/orders/$id/confirm")->assertOk();
    TaxClass::query()->update(['percentage' => '30.00']);
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('90'))->assertOk()
        ->assertJsonPath('data.tax_total', '0.00')->assertJsonPath('data.discount_total', '10.00')->assertJsonPath('data.total', '90.00');
});

test('a newer close after route binding is rechecked under lock', function () {
    $id = checkoutOrder();
    $dispatcher = Order::getEventDispatcher();
    Order::setEventDispatcher(clone $dispatcher);
    $changed = false;
    Order::retrieved(function (Order $order) use ($id, &$changed) {
        if ($order->id === $id && ! $changed) {
            $changed = true;
            DB::table('orders')->where('id', $id)->update(['status' => 'closed', 'closed_at' => now()]);
        }
    });
    try {
        $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments())->assertConflict();
    } finally {
        Order::setEventDispatcher($dispatcher);
    }
    expect(Payment::count())->toBe(0);
});

test('current balance rather than a prior preview determines the next redemption', function () {
    $this->a->update(['loyalty_factor' => '0.0001']);
    $first = checkoutOrder();
    $second = checkoutOrder();
    foreach ([$first, $second] as $id) {
        $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    }
    LoyaltyBalance::sole()->update(['points_balance' => '100.00']);
    $payload = checkoutPayments('99') + ['redeem_points' => 100];
    $this->postJson("/api/v1/waiter/orders/$second/checkout-preview", $payload)->assertOk();
    $this->postJson("/api/v1/waiter/orders/$first/close", $payload)->assertOk();
    $this->postJson("/api/v1/waiter/orders/$second/close", $payload)->assertUnprocessable()->assertJsonValidationErrors('redeem_points');
    expect(LoyaltyBalance::sole()->points_balance)->toBe('0.00')->and(Payment::count())->toBe(1);
});

test('missing or invalid enabled configuration fails closed', function (string $field) {
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    $this->a->update([$field => null]);
    $payload = checkoutPayments('113.30', 'card');
    if ($field === 'loyalty_redeem_value') {
        LoyaltyBalance::sole()->update(['points_balance' => '100']);
        $payload['redeem_points'] = 10;
    }
    $this->postJson("/api/v1/waiter/orders/$id/close", $payload)->assertUnprocessable();
    expect(Payment::count())->toBe(0)->and(LoyaltyTransaction::count())->toBe(0);
})->with(['dual_pricing_percentage', 'loyalty_factor', 'loyalty_redeem_value']);

test('a fully exempt free order can close without fabricating a zero payment', function () {
    $this->actingAs($this->admin, 'sanctum');
    $this->menu->update(['sale_price' => '0.00']);
    $this->actingAs($this->waiter, 'sanctum');
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/close", ['payments' => []])->assertOk()
        ->assertJsonPath('data.total', '0.00')->assertJsonPath('data.status', 'closed')->assertJsonCount(0, 'data.payments');
});

test('delivery fee is collected and points are scoped to the chosen customer', function () {
    $id = $this->postJson('/api/v1/waiter/orders', ['service_type' => 'delivery', 'delivery_fee' => '5.00'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/waiter/orders/$id/items", ['menu_item_id' => $this->menu->id, 'quantity' => 1])->assertCreated();
    $this->postJson("/api/v1/waiter/orders/$id/confirm")->assertOk();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    $new = Customer::factory()->create();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $new->qr_code])->assertOk();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('115'))->assertOk();
    expect(LoyaltyBalance::where('customer_id', $new->user_id)->sole()->points_balance)->toBe('200.00')
        ->and(LoyaltyBalance::where('customer_id', $this->customer->user_id)->sole()->points_balance)->toBe('0.00');
});

test('validation messages respect the waiter language', function () {
    $this->waiter->update(['locale' => 'en']);
    $id = checkoutOrder();
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('1'))->assertUnprocessable()
        ->assertJsonPath('errors.payments.0', 'Base payment amounts must add up to exactly 110.00.');
});

test('preview determines the remaining card charge before the waiter registers it without surcharging tip', function () {
    $id = checkoutOrder();
    $url = "/api/v1/waiter/orders/$id";
    $this->postJson("$url/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    LoyaltyBalance::sole()->update(['points_balance' => '100.00']);
    $payload = checkoutPayments('40.00') + ['tip_amount' => 10, 'redeem_points' => 100];
    $this->postJson("$url/checkout-preview", $payload)->assertOk()->assertJsonPath('data.tax_total', '9.00')
        ->assertJsonPath('data.card_amount', '69.00')->assertJsonPath('data.surcharge_total', '1.77')
        ->assertJsonPath('data.total', '110.77')->assertJsonPath('data.points_earned', '180.00');
    $payload['payments'][] = ['method' => 'card', 'amount' => '69.00'];
    $this->postJson("$url/close", $payload)->assertOk()->assertJsonPath('data.total', '110.77');
    expect(LoyaltyBalance::sole()->points_balance)->toBe('180.00');
});

test('a card used only for tip never receives a dual pricing adjustment', function () {
    $id = checkoutOrder();
    $payload = checkoutPayments() + ['tip_amount' => '10.00'];
    $payload['payments'][] = ['method' => 'card', 'amount' => '10.00'];
    $this->postJson("/api/v1/waiter/orders/$id/close", $payload)->assertOk()
        ->assertJsonPath('data.total', '120.00')->assertJsonPath('data.surcharge_total', '0.00');
});

test('redemption is allocated after existing discounts before each tax class with stable cent rounding', function () {
    $this->actingAs($this->admin, 'sanctum');
    $tax = TaxClass::factory()->create(['percentage' => '20.00']);
    $category = Category::factory()->create(['super_category_id' => SuperCategory::factory()->create(['tax_class_id' => $tax->id])->id]);
    $menu = MenuItem::factory()->create(['category_id' => $category->id, 'sale_price' => '50.00']);
    $this->actingAs($this->waiter, 'sanctum');
    $id = checkoutOrder(false);
    $this->postJson("/api/v1/waiter/orders/$id/items", ['menu_item_id' => $menu->id, 'quantity' => 1])->assertCreated();
    WorkShift::factory()->create(['employee_id' => $this->waiter->id]);
    $this->postJson("/api/v1/waiter/orders/$id/discounts", ['amount' => 10, 'admin_user_id' => $this->admin->id, 'pin' => '1234'])->assertCreated();
    $this->postJson("/api/v1/waiter/orders/$id/confirm")->assertOk();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    LoyaltyBalance::sole()->update(['points_balance' => '200.00']);
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('147.34') + ['redeem_points' => 100])->assertOk()
        ->assertJsonPath('data.subtotal', '150.00')->assertJsonPath('data.discount_total', '10.00')
        ->assertJsonPath('data.tax_total', '17.34')->assertJsonPath('data.total', '147.34')
        ->assertJsonPath('data.loyalty_transactions.1.points', '260.00');
});

test('redemption cannot cover delivery or taxes and points exclude both', function () {
    $id = $this->postJson('/api/v1/waiter/orders', ['service_type' => 'delivery', 'delivery_fee' => 5])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/waiter/orders/$id/items", ['menu_item_id' => $this->menu->id, 'quantity' => 1])->assertCreated();
    $this->postJson("/api/v1/waiter/orders/$id/confirm")->assertOk();
    $this->postJson("/api/v1/waiter/orders/$id/customer", ['qr_code' => $this->customer->qr_code])->assertOk();
    LoyaltyBalance::sole()->update(['points_balance' => '2000.00']);
    $this->postJson("/api/v1/waiter/orders/$id/close", ['payments' => [], 'redeem_points' => 1100])->assertUnprocessable()
        ->assertJsonValidationErrors('redeem_points');
    $this->postJson("/api/v1/waiter/orders/$id/close", checkoutPayments('5') + ['redeem_points' => 1000])->assertOk()
        ->assertJsonPath('data.total', '5.00')->assertJsonPath('data.tax_total', '0.00')->assertJsonCount(1, 'data.loyalty_transactions');
});
