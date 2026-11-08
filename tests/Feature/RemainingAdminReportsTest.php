<?php

use App\Models\Employee;
use App\Models\ExternalPlatformSale;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\TaxExemption;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\RestaurantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function remainingReportsFixture(Restaurant $restaurant, Employee $admin, string $day): array
{
    return app(RestaurantContext::class)->run($restaurant, function () use ($admin, $day) {
        $date = CarbonImmutable::parse($day);
        $eventTime = $date->endOfDay()->format('Y-m-d H:i:s');
        $waiter = Employee::factory()->create(['hourly_rate' => '19.99']);
        $shift = WorkShift::factory()->create(['employee_id' => $waiter->user_id,
            'clock_in' => $date->subHours(2), 'clock_out' => $date->addHours(2)->addMinutes(30)]);
        WorkShift::factory()->create(['employee_id' => $admin->user_id, 'clock_in' => $date->addHours(10)]);

        $menu = MenuItem::factory()->create(['name' => 'Soup']);
        $uncosted = MenuItem::factory()->create(['name' => 'Water']);
        $ingredient = InventoryItem::factory()->create(['unit_cost' => '2.5000']);
        $menu->ingredients()->create(['inventory_item_id' => $ingredient->id, 'quantity' => '2.000']);
        $order = Order::factory()->create(['waiter_employee_id' => $waiter->user_id, 'cash_register_id' => null,
            'created_at' => $date->subDay()]);
        $line = OrderItem::factory()->for($order)->for($menu)->create(['quantity' => 2, 'unit_price' => '15.00']);
        OrderItem::factory()->for($order)->for($uncosted)->create(['quantity' => 1, 'unit_price' => '10.00']);
        $cancelled = OrderItem::factory()->for($order)->for($menu)->create(['quantity' => 3,
            'unit_price' => '15.00', 'cancelled' => true, 'created_at' => $date->subDay(),
            'cancelled_at' => $eventTime, 'cancel_reason' => 'Wrong item', 'cancel_authorized_by_employee_id' => $admin->user_id]);
        foreach (['courtesy' => '4.00', 'discount' => '6.00'] as $kind => $amount) {
            OrderDiscount::factory()->for($order)->create(['kind' => $kind, 'amount' => $amount, 'work_shift_id' => $shift->id,
                'authorized_by_employee_id' => $admin->user_id, 'created_at' => $eventTime]);
        }
        TaxExemption::factory()->for($order)->create(['order_item_id' => $line->id, 'created_at' => $eventTime]);
        $order->update(['status' => 'closed', 'closed_at' => $eventTime, 'subtotal' => '40.00', 'discount_total' => '10.00',
            'loyalty_redeemed_amount' => '2.00', 'tax_total' => '1.25', 'delivery_fee' => '3.00',
            'tip_amount' => '6.20', 'surcharge_total' => '0.75', 'total' => '39.20']);

        $void = Order::factory()->create(['waiter_employee_id' => $waiter->user_id, 'cash_register_id' => null,
            'status' => 'cancelled', 'created_at' => $date->subDay(), 'cancelled_at' => $eventTime,
            'cancel_reason' => 'Customer left', 'cancel_authorized_by_employee_id' => $admin->user_id]);

        foreach (['doordash' => '20.00', 'rappi' => '5.00'] as $platform => $commission) {
            ExternalPlatformSale::factory()->create(['platform' => $platform, 'registered_by_employee_id' => $admin->user_id,
                'total_amount' => '50.00', 'commission_amount' => $commission, 'sale_date' => $eventTime]);
        }

        return compact('waiter', 'shift', 'menu', 'order', 'void', 'cancelled');
    });
}

beforeEach(function () {
    $super = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurants = collect(['A', 'B'])->map(fn (string $name) => Restaurant::create([
        'name' => $name,
        'created_by_user_id' => $super->id,
    ]));
    $this->admins = $this->restaurants->map(fn (Restaurant $restaurant) => app(RestaurantContext::class)
        ->run($restaurant, fn () => Employee::factory()->create(['role' => 'admin'])));
    $this->actingAs($this->admins[0]->user, 'sanctum');
    $this->own = remainingReportsFixture($this->restaurants[0], $this->admins[0], '2026-11-06');
    $this->actingAs($this->admins[1]->user, 'sanctum');
    $this->foreign = remainingReportsFixture($this->restaurants[1], $this->admins[1], '2026-11-06');
    $this->actingAs($this->admins[0]->user, 'sanctum');
    $this->outside = remainingReportsFixture($this->restaurants[0], $this->admins[0], '2026-11-10');
    $this->actingAs($this->admins[0]->user, 'sanctum');
});

function remainingReportUrl(string $report, array $filters = []): string
{
    return '/api/v1/admin/reports/'.$report.'?'.http_build_query($filters + ['from' => '2026-11-06', 'to' => '2026-11-06']);
}

test('adjustment reports isolate authorized entries by kind, restaurant and event date', function (string $report, string $amount) {
    $this->getJson(remainingReportUrl($report))->assertOk()->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.order_id', $this->own['order']->id)
        ->assertJsonPath('data.totals.order_count', 1)
        ->assertJsonPath('data.totals.entry_count', 1)
        ->assertJsonPath('data.totals.authorized_amount', $amount);
})->with([['courtesies', '4.00'], ['discounts', '6.00']]);

test('margin uses net consumption and separates products with unknown cost', function () {
    $this->getJson(remainingReportUrl('profit-margins'))->assertOk()->assertJsonCount(2, 'data.products')
        ->assertJsonPath('data.products.0.menu_item_id', $this->own['menu']->id)
        ->assertJsonPath('data.products.0.estimated_profit', '11.00')
        ->assertJsonPath('data.products.0.margin_percentage', '52.38')
        ->assertJsonPath('data.products.1.estimated_profit', null)
        ->assertJsonPath('data.totals.net_sales', '28.00')
        ->assertJsonPath('data.totals.uncosted_net_sales', '7.00')
        ->assertJsonPath('data.totals.estimated_cost', '10.00');
});

test('tax and tip reports total paid amounts and track exemptions separately', function () {
    $this->getJson(remainingReportUrl('taxes-and-exemptions'))->assertOk()
        ->assertJsonCount(1, 'data.orders')->assertJsonCount(1, 'data.exemptions')
        ->assertJsonPath('data.exemptions.0.order_id', $this->own['order']->id)
        ->assertJsonPath('data.totals.exemption_count', 1)
        ->assertJsonPath('data.totals.tax_total', '1.25');
    $this->getJson(remainingReportUrl('tips'))->assertOk()->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $this->own['order']->id)
        ->assertJsonPath('data.totals.tip_amount', '6.20');
});

test('cancellation report uses action date and preserves legacy date provenance', function () {
    $legacy = Order::factory()->create(['source' => 'customer_app', 'waiter_employee_id' => null, 'cash_register_id' => null,
        'status' => 'cancelled', 'created_at' => '2026-11-05 12:00:00']);
    $legacy->advancePayment()->create(['stripe_account_id' => 'acct_Report', 'currency' => 'USD', 'amount' => '5.00',
        'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64),
        'status' => 'voided', 'cancellation_requested_at' => '2026-11-06 00:00:00']);
    $this->getJson(remainingReportUrl('cancellations'))->assertOk()->assertJsonCount(2, 'data.orders')
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.orders.0.id', $this->own['void']->id)
        ->assertJsonPath('data.orders.1.id', $legacy->id)
        ->assertJsonPath('data.items.0.id', $this->own['cancelled']->id)
        ->assertJsonPath('data.items.0.cancel_authorized_by_employee_id', $this->admins[0]->user_id)
        ->assertJsonPath('data.totals.gross_item_amount', '45.00')
        ->assertJsonPath('data.totals.order_count', 2)->assertJsonPath('data.totals.item_quantity', 3);
    $this->getJson(remainingReportUrl('cancellations', ['from' => '2026-11-05', 'to' => '2026-11-05']))
        ->assertOk()->assertJsonCount(0, 'data.orders')->assertJsonCount(0, 'data.items');
});

test('external sales report groups platforms and reconciles gross commission and net', function () {
    $this->getJson(remainingReportUrl('external-platform-sales'))->assertOk()->assertJsonCount(2, 'data.platforms')
        ->assertJsonFragment(['platform' => 'doordash', 'net_amount' => '30.00'])
        ->assertJsonFragment(['platform' => 'rappi', 'net_amount' => '45.00'])
        ->assertJsonPath('data.totals.sale_count', 2)
        ->assertJsonPath('data.totals.total_amount', '100.00')
        ->assertJsonPath('data.totals.commission_amount', '25.00')
        ->assertJsonPath('data.totals.net_amount', '75.00');
});

test('employee product sales exclude cancelled items and keep net adjustments', function () {
    $this->getJson(remainingReportUrl('employee-product-sales'))->assertOk()->assertJsonCount(2, 'data.products')
        ->assertJsonPath('data.products.0.employee_id', $this->own['waiter']->user_id)
        ->assertJsonPath('data.products.0.menu_item_id', $this->own['menu']->id)
        ->assertJsonPath('data.products.0.net_sales', '21.00')
        ->assertJsonPath('data.totals.quantity_sold', 3)
        ->assertJsonPath('data.totals.gross_sales', '40.00')
        ->assertJsonPath('data.totals.net_sales', '28.00');
    $this->getJson(remainingReportUrl('employee-product-sales', ['role' => 'chef']))
        ->assertOk()->assertJsonCount(0, 'data.products');
});

test('shifts and payroll clip overnight hours and omit open shifts from pay', function () {
    $this->getJson(remainingReportUrl('work-shifts'))->assertOk()->assertJsonCount(2, 'data.shifts')
        ->assertJsonPath('data.shifts.0.id', $this->own['shift']->id)
        ->assertJsonPath('data.shifts.1.worked_seconds', null)
        ->assertJsonPath('data.totals.open_shift_count', 1)
        ->assertJsonPath('data.totals.worked_seconds', 9000);
    $this->getJson(remainingReportUrl('payroll', ['employee_id' => $this->own['waiter']->user_id, 'role' => 'waiter']))
        ->assertOk()->assertJsonCount(1, 'data.employees')
        ->assertJsonPath('data.employees.0.shifts.0.id', $this->own['shift']->id)
        ->assertJsonPath('data.employees.0.worked_hours', '2.5000')
        ->assertJsonPath('data.employees.0.regular_hours', '2.5000')
        ->assertJsonPath('data.employees.0.overtime_hours', '0.0000')
        ->assertJsonPath('data.totals.estimated_pay', '49.98');
    $this->getJson(remainingReportUrl('payroll'))->assertOk()->assertJsonCount(2, 'data.employees')
        ->assertJsonPath('data.totals.estimated_pay', '49.98');
});

test('sales versus labor uses the same employee filters and avoids division by zero', function () {
    $this->getJson(remainingReportUrl('sales-vs-labor'))->assertOk()
        ->assertJsonPath('data.totals.net_sales', '103.00')
        ->assertJsonPath('data.totals.estimated_labor_cost', '49.98')
        ->assertJsonPath('data.totals.labor_cost_percentage', '48.52');
    $this->getJson(remainingReportUrl('sales-vs-labor', ['role' => 'waiter', 'employee_id' => $this->own['waiter']->user_id]))
        ->assertOk()->assertJsonPath('data.totals.net_sales', '28.00')
        ->assertJsonPath('data.totals.external_platform_net_sales', '0.00')
        ->assertJsonPath('data.totals.labor_cost_percentage', '178.50');
    $this->getJson(remainingReportUrl('sales-vs-labor', ['role' => 'chef']))
        ->assertOk()->assertJsonPath('data.totals.net_sales', '0.00')
        ->assertJsonPath('data.totals.labor_cost_percentage', null);
});

test('all remaining reports require local administrator access and valid dates', function () {
    $reports = ['courtesies', 'discounts', 'profit-margins', 'taxes-and-exemptions', 'tips', 'cancellations',
        'external-platform-sales', 'employee-product-sales', 'work-shifts', 'payroll', 'sales-vs-labor'];
    foreach ($reports as $report) {
        $this->getJson(remainingReportUrl($report, ['to' => '2026-11-05', 'restaurant_id' => $this->restaurants[1]->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['to', 'restaurant_id']);
    }
    foreach (['employee-product-sales', 'work-shifts', 'payroll', 'sales-vs-labor'] as $report) {
        $this->getJson(remainingReportUrl($report, ['employee_id' => $this->foreign['waiter']->user_id, 'role' => 'super_admin']))
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id', 'role']);
    }
    $this->actingAs($this->own['waiter']->user, 'sanctum');
    foreach ($reports as $report) {
        $this->getJson(remainingReportUrl($report))->assertForbidden();
    }
});
