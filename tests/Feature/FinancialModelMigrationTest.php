<?php

use App\Models\Customer;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function financialMigration(): object
{
    return require database_path('migrations/2026_11_06_074813_align_order_financial_breakdown_with_updated_model.php');
}

function lifecycleMigration(): object
{
    return require database_path('migrations/2026_11_06_074814_align_stripe_payment_lifecycle_with_updated_model.php');
}

beforeEach(function () {
    config(['services.stripe.legacy_accounts' => []]);
    $this->restaurant = Restaurant::create(['name' => 'Historical', 'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id]);
    $this->customer = Customer::factory()->create();
});

test('financial migration preserves historical totals and separates redemption surcharge and earn base', function () {
    $migration = financialMigration();
    $migration->down();
    $order = DB::table('orders')->insertGetId(['restaurant_id' => $this->restaurant->id, 'customer_id' => $this->customer->user_id,
        'source' => 'pos', 'service_type' => 'delivery', 'subtotal' => 100, 'discount_total' => 20, 'tax_total' => 8,
        'delivery_fee' => 5, 'tip_amount' => 10, 'total' => 105.79, 'dual_pricing_applied' => true, 'created_at' => now()]);
    // The historical recipe and current point exchange rate are irrelevant to the immutable recorded totals.
    DB::table('payments')->insert(['order_id' => $order, 'method' => 'card', 'amount' => 105.79, 'created_at' => now()]);
    foreach (['earn', 'redeem'] as $type) {
        DB::table('loyalty_transactions')->insert(['restaurant_id' => $this->restaurant->id, 'customer_id' => $this->customer->user_id,
            'order_id' => $order, 'type' => $type, 'points' => 2, 'created_at' => now()]);
    }
    $migration->up();
    $result = DB::table('orders')->find($order);
    expect((float) $result->total)->toBe(105.79)->and((float) $result->discount_total)->toBe(0.0)
        ->and((float) $result->loyalty_redeemed_amount)->toBe(20.0)->and((float) $result->surcharge_total)->toBe(2.79);
    $payment = DB::table('payments')->where('order_id', $order)->sole();
    expect((float) $payment->amount)->toBe(103.0)->and((float) $payment->surcharge_amount)->toBe(2.79)
        ->and((float) DB::table('loyalty_transactions')->where('type', 'earn')->value('base_amount'))->toBe(80.0)
        ->and(DB::table('loyalty_transactions')->where('type', 'redeem')->value('base_amount'))->toBeNull();
    $migration->down();
    expect((float) DB::table('payments')->value('amount'))->toBe(105.79)
        ->and((float) DB::table('orders')->value('discount_total'))->toBe(20.0);
    $migration->up();
});

test('ambiguous historical money fails before any schema or data is changed', function () {
    $migration = financialMigration();
    $migration->down();
    $id = DB::table('orders')->insertGetId(['restaurant_id' => $this->restaurant->id, 'source' => 'pos', 'service_type' => 'takeout',
        'subtotal' => 100, 'total' => 50, 'created_at' => now()]);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Review legacy financial breakdown');
    expect(Schema::hasColumn('orders', 'surcharge_total'))->toBeFalse()
        ->and((float) DB::table('orders')->find($id)->total)->toBe(50.0);
    DB::table('orders')->where('id', $id)->update(['total' => 100]);
    $migration->up();
});

test('migration replays capped discounts and ignores cancelled lines when separating redemption', function () {
    app(RestaurantContext::class)->run($this->restaurant, function () {
        $employee = Employee::factory()->create(['role' => 'admin']);
        $this->actingAs($employee->user, 'sanctum');
        $shift = WorkShift::factory()->create(['employee_id' => $employee->user_id]);
        $menu = MenuItem::factory()->create();
        $migration = financialMigration();
        $migration->down();
        $order = DB::table('orders')->insertGetId(['restaurant_id' => $this->restaurant->id, 'customer_id' => $this->customer->user_id,
            'source' => 'pos', 'service_type' => 'takeout', 'subtotal' => 100, 'discount_total' => 40, 'total' => 60, 'created_at' => now()]);
        $active = DB::table('order_items')->insertGetId(['order_id' => $order, 'menu_item_id' => $menu->id, 'quantity' => 1, 'unit_price' => 100, 'station' => 'kitchen', 'created_at' => now()]);
        $cancelled = DB::table('order_items')->insertGetId(['order_id' => $order, 'menu_item_id' => $menu->id, 'quantity' => 1, 'unit_price' => 50, 'station' => 'kitchen', 'cancelled' => true, 'created_at' => now()]);
        foreach ([[$active, 30], [$cancelled, 50]] as [$line, $amount]) {
            DB::table('order_discounts')->insert(['order_id' => $order, 'order_item_id' => $line, 'kind' => 'discount', 'amount' => $amount,
                'requested_by_employee_id' => $employee->user_id, 'authorized_by_employee_id' => $employee->user_id,
                'work_shift_id' => $shift->id, 'created_at' => now()]);
        }
        DB::table('loyalty_transactions')->insert(['restaurant_id' => $this->restaurant->id, 'customer_id' => $this->customer->user_id,
            'order_id' => $order, 'type' => 'redeem', 'points' => 999, 'created_at' => now()]);
        $migration->up();
        $result = DB::table('orders')->find($order);
        expect((float) $result->discount_total)->toBe(30.0)->and((float) $result->loyalty_redeemed_amount)->toBe(10.0)
            ->and((float) $result->total)->toBe(60.0);
    });
});

test('Stripe lifecycle migration preserves references captured timestamps and frozen account and imports the server map', function () {
    $migration = lifecycleMigration();
    $migration->down();
    $order = DB::table('orders')->insertGetId(['restaurant_id' => $this->restaurant->id, 'source' => 'customer_app', 'service_type' => 'takeout', 'created_at' => now()]);
    $stamp = now()->subDay()->startOfSecond();
    DB::table('advance_order_payments')->insert(['order_id' => $order, 'payment_reference' => 'pi_legacy', 'payment_confirmed' => true,
        'paid_at' => $stamp, 'stripe_account_id' => 'acct_frozen', 'currency' => 'USD', 'amount' => 100,
        'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_at' => $stamp]);
    config(['services.stripe.legacy_accounts' => [$this->restaurant->id => 'acct_current']]);
    $migration->up();
    $payment = DB::table('advance_order_payments')->sole();
    expect($payment->payment_intent_id)->toBe('pi_legacy')->and($payment->status)->toBe('captured')
        ->and($payment->stripe_account_id)->toBe('acct_frozen')->and($payment->captured_at)->toBe($stamp->toDateTimeString())
        ->and($payment->cancellation_requested_at)->toBeNull()->and($payment->updated_at)->not->toBeNull()
        ->and(DB::table('restaurants')->value('stripe_account_id'))->toBe('acct_current');
    DB::table('advance_order_payments')->where('id', $payment->id)->update(['status' => 'authorized', 'refund_attempts' => 256]);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Settle pending Stripe');
    expect(Schema::hasColumn('advance_order_payments', 'status'))->toBeTrue();
    DB::table('advance_order_payments')->where('id', $payment->id)->update(['status' => 'captured']);
});

test('rollback refuses to discard Super Admin account assignments without a legacy map', function () {
    $this->restaurant->update(['stripe_account_id' => 'acct_new']);
    expect(fn () => lifecycleMigration()->down())->toThrow(RuntimeException::class, 'Export current Stripe');
    expect(Schema::hasColumn('restaurants', 'stripe_account_id'))->toBeTrue();
    config(['services.stripe.legacy_accounts' => [$this->restaurant->id => 'acct_new']]);
});
