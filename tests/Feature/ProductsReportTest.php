<?php

use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\TaxExemption;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function productReportOrder(array $attributes = []): Order
{
    return Order::factory()->create($attributes + ['cash_register_id' => null, 'waiter_employee_id' => null]);
}

function productReportSale(MenuItem $menu, string $date = '2026-11-06 12:00:00', ?string $paymentStatus = null): void
{
    $order = productReportOrder(['source' => $paymentStatus === null ? 'pos' : 'customer_app']);
    OrderItem::factory()->for($order)->for($menu)->create(['quantity' => 1, 'unit_price' => '5.00']);
    $order->update(['status' => 'closed', 'closed_at' => $date, 'subtotal' => '5.00', 'total' => '5.00']);
    if ($paymentStatus !== null) {
        $order->advancePayment()->create([
            'stripe_account_id' => 'acct_Products',
            'currency' => 'USD',
            'amount' => '5.00',
            'request_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64),
            'status' => $paymentStatus,
        ]);
    }
}

beforeEach(function () {
    $this->restaurant = Restaurant::create([
        'name' => 'Restaurant A',
        'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ]);
    $this->admin = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->url = '/api/v1/admin/reports/products?from=2026-11-05&to=2026-11-06';
});

test('product totals preserve adjustments and paid tax when filters hide other order lines', function () {
    $this->actingAs($this->admin, 'sanctum');
    $waiter = Employee::factory()->create();
    $food = MenuItem::factory()->create(['name' => 'Platillo', 'sale_price' => '999.00']);
    $drink = MenuItem::factory()->create(['name' => 'Bebida']);
    $food->category->superCategory->taxClass->update(['percentage' => '20.00']);
    $ingredient = InventoryItem::factory()->create(['unit_cost' => '1.2345']);
    $food->ingredients()->create(['inventory_item_id' => $ingredient->id, 'quantity' => '2.500']);
    $order = productReportOrder(['waiter_employee_id' => $waiter->user_id]);
    $foodLine = OrderItem::factory()->for($order)->for($food)->create(['quantity' => 2, 'unit_price' => '30.00', 'modifiers' => 'Sin cebolla']);
    $drinkLine = OrderItem::factory()->for($order)->for($drink)->create(['quantity' => 1, 'unit_price' => '40.00', 'modifiers' => 'Con hielo']);
    OrderItem::factory()->for($order)->for($food)->create(['quantity' => 1, 'unit_price' => '100.00', 'cancelled' => true]);
    OrderDiscount::factory()->for($order)->create(['order_item_id' => $foodLine->id, 'amount' => '10.00']);
    OrderDiscount::factory()->for($order)->create(['kind' => 'courtesy', 'amount' => '9.00']);
    TaxExemption::factory()->for($order)->create(['order_item_id' => $drinkLine->id]);
    $order->update([
        'status' => 'closed', 'closed_at' => '2026-11-06 23:59:59', 'subtotal' => '100.00',
        'discount_total' => '19.00', 'loyalty_redeemed_amount' => '9.00', 'tax_total' => '4.00',
        'tip_amount' => '3.00', 'delivery_fee' => '3.00', 'surcharge_total' => '0.25', 'total' => '82.25',
    ]);
    $food->delete();

    $full = $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'data.products')
        ->assertJsonPath('data.products.0.estimated_unit_cost', '3.0863')
        ->assertJsonPath('data.products.0.menu_item_id', $food->id)
        ->assertJsonPath('data.products.1.estimated_unit_cost', null)
        ->assertJsonPath('data.products.1.tax_total', '0.00')
        ->assertJsonPath('data.totals.quantity_sold', 3)
        ->assertJsonPath('data.totals.gross_sales', '100.00')
        ->assertJsonPath('data.totals.discount_total', '19.00')
        ->assertJsonPath('data.totals.loyalty_redeemed_amount', '9.00')
        ->assertJsonPath('data.totals.net_sales', '72.00')
        ->assertJsonPath('data.totals.tax_total', '4.00')
        ->assertJsonPath('data.totals.total_sales', '76.00')
        ->assertJsonPath('data.totals.returns_amount', '0.00');

    foreach ([
        ['super_category_id' => $food->category->super_category_id],
        ['category_id' => $food->category_id],
        ['modifier' => 'CEBOLLA'],
        ['category_id' => $food->category_id, 'modifier' => 'cebolla'],
    ] as $filters) {
        $filtered = $this->getJson($this->url.'&'.http_build_query($filters))->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.totals.discount_total', '15.00')
            ->assertJsonPath('data.totals.loyalty_redeemed_amount', '5.00')
            ->assertJsonPath('data.totals.total_sales', '44.00');
        expect($filtered->json('data.products.0'))->toBe($full->json('data.products.0'));
    }
    $this->getJson($this->url.'&category_id='.$food->category_id.'&modifier=hielo')->assertOk()
        ->assertJsonCount(0, 'data.products')->assertJsonPath('data.totals.total_sales', '0.00');

    $food->category->superCategory->taxClass->update(['percentage' => '0.00']);
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.products.0.tax_total', '4.00')
        ->assertJsonPath('data.products.1.tax_total', '0.00');
});

test('the report uses closing dates and counts captured online sales only', function () {
    $this->actingAs($this->admin, 'sanctum');
    $menu = MenuItem::factory()->create();
    productReportSale($menu, '2026-11-04 23:59:59');
    productReportSale($menu, '2026-11-05 00:00:00');
    productReportSale($menu, '2026-11-06 23:59:59', 'captured');
    productReportSale($menu, '2026-11-06 23:59:59', 'refunded');
    productReportSale($menu, '2026-11-06 23:59:59', 'authorized');
    productReportSale($menu, '2026-11-07 00:00:00');

    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data.products')
        ->assertJsonPath('data.products.0.quantity_sold', 2)
        ->assertJsonPath('data.totals.total_sales', '10.00');
});

test('product sales and category filters are scoped to the administrator restaurant', function () {
    $other = Restaurant::create(['name' => 'Restaurant B', 'created_by_user_id' => $this->restaurant->created_by_user_id]);
    $otherAdmin = app(RestaurantContext::class)->run($other, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->actingAs($otherAdmin, 'sanctum');
    $foreign = MenuItem::factory()->create();
    $foreignSuper = $foreign->category->super_category_id;
    productReportSale($foreign);

    $this->actingAs($this->admin, 'sanctum');
    $local = MenuItem::factory()->create();
    productReportSale($local);
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data.products')
        ->assertJsonPath('data.products.0.menu_item_id', $local->id)->assertJsonPath('data.totals.total_sales', '5.00');
    $this->getJson($this->url.'&category_id='.$foreign->category_id)
        ->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->getJson($this->url.'&super_category_id='.$foreignSuper)
        ->assertUnprocessable()->assertJsonValidationErrors('super_category_id');
    $this->getJson($this->url.'&restaurant_id='.$other->id)
        ->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');
    $this->actingAs($otherAdmin, 'sanctum')->getJson($this->url)->assertOk()->assertJsonCount(1, 'data.products')
        ->assertJsonPath('data.products.0.menu_item_id', $foreign->id);
});

test('the report validates dates and requires an authenticated administrator', function () {
    $this->getJson($this->url)->assertUnauthorized();
    $waiter = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create())->user;
    $this->actingAs($waiter, 'sanctum')->getJson($this->url)->assertForbidden();
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/reports/products?from=2026-11-06&to=2026-11-05')
        ->assertUnprocessable()->assertJsonValidationErrors('to');
});
