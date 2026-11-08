<?php

use App\Models\Employee;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 9)->startOfDay());
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurants = collect(['A', 'B'])->map(fn (string $name) => Restaurant::create([
        'name' => $name, 'created_by_user_id' => $creator->id,
    ]));
    $this->admins = $this->restaurants->map(fn (Restaurant $restaurant) => app(RestaurantContext::class)
        ->run($restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user);
    $this->actingAs($this->admins[0], 'sanctum');
});

function returnableProductSale(string $closedAt = '2026-11-05 12:00:00', string $source = 'pos'): array
{
    $waiter = Employee::factory()->create();
    $order = Order::factory()->create(['waiter_employee_id' => $source === 'pos' ? $waiter->user_id : null,
        'cash_register_id' => null, 'source' => $source, 'created_at' => '2026-11-05 10:00:00']);
    $menu = MenuItem::factory()->create(['name' => 'Sopa']);
    $first = OrderItem::factory()->for($order)->for($menu)->create(['quantity' => 2, 'unit_price' => '20.00', 'modifiers' => 'Sin cebolla']);
    $second = OrderItem::factory()->for($order)->for($menu)->create(['quantity' => 1, 'unit_price' => '10.00', 'modifiers' => 'Con cebolla']);
    $cancelled = OrderItem::factory()->for($order)->for($menu)->create(['cancelled' => true]);
    if ($source === 'pos') {
        OrderDiscount::factory()->for($order)->create(['order_item_id' => $first->id, 'amount' => '5.00']);
        TaxExemption::factory()->for($order)->create(['order_item_id' => $second->id]);
    }
    $order->update(['status' => 'closed', 'closed_at' => $closedAt, 'subtotal' => '50.00',
        'discount_total' => $source === 'pos' ? '5.00' : '0.00', 'tax_total' => '3.00', 'total' => '48.00']);

    return [$order, $first, $second, $cancelled];
}

function lineReturnUrl(Order $order, OrderItem $item): string
{
    return "/api/v1/admin/orders/{$order->id}/items/{$item->id}/returns";
}

function lineReturnPayload(array $overrides = []): array
{
    return $overrides + ['quantity' => 1, 'amount' => '17.50', 'tax_amount' => '1.50',
        'reason' => 'Registro de devolución', 'returned_at' => '2026-11-06 08:00:00'];
}

function lineReturnHeaders(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

test('registering an already completed refund is idempotent and cannot exceed the original sale', function () {
    [$order, $item, $exempt] = returnableProductSale();
    $url = lineReturnUrl($order, $item);
    $data = lineReturnPayload();
    $key = lineReturnHeaders();
    $id = $this->postJson($url, $data, $key)->assertCreated()->assertJsonPath('data.amount', '17.50')
        ->assertJsonPath('data.recorded_by_employee_id', $this->admins[0]->id)->json('data.id');
    $this->postJson($url, $data, $key)->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson($url, lineReturnPayload(['reason' => 'Otra']), $key)->assertConflict();
    $this->postJson($url, lineReturnPayload(['quantity' => 2, 'amount' => '17.51', 'tax_amount' => '1.51']), lineReturnHeaders())
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'amount', 'tax_amount']);
    $this->postJson(lineReturnUrl($order, $exempt), lineReturnPayload(['amount' => '10.00']), lineReturnHeaders())
        ->assertUnprocessable()->assertJsonValidationErrors('tax_amount');
    $this->postJson($url, lineReturnPayload(['returned_at' => '2026-11-04 12:00:00']), lineReturnHeaders())
        ->assertUnprocessable()->assertJsonValidationErrors('returned_at');
    $this->postJson($url, lineReturnPayload(['returned_at' => '2026-11-10 00:00:00']), lineReturnHeaders())
        ->assertUnprocessable()->assertJsonValidationErrors('returned_at');
    $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('request_key');
    $this->getJson("/api/v1/admin/orders/{$order->id}/items")->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $item->id)->assertJsonPath('data.0.quantity_returned', 1)
        ->assertJsonPath('data.1.quantity_returned', 0);
    $this->assertDatabaseCount('order_item_returns', 1);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'closed', 'total' => '48.00']);
    $this->assertDatabaseCount('cash_movements', 0);
});

test('only closed non-cancelled POS lines in the administrators restaurant can be returned', function () {
    [$order, $item, , $cancelled] = returnableProductSale();
    [$online, $onlineItem] = returnableProductSale(source: 'customer_app');
    $this->postJson(lineReturnUrl($online, $onlineItem), lineReturnPayload(), lineReturnHeaders())->assertConflict();
    $this->postJson(lineReturnUrl($order, $cancelled), lineReturnPayload(), lineReturnHeaders())->assertConflict();

    $this->actingAs($this->admins[1], 'sanctum');
    [$foreign, $foreignItem] = returnableProductSale();
    $this->postJson(lineReturnUrl($order, $item), lineReturnPayload(), lineReturnHeaders())->assertNotFound();
    $this->actingAs($this->admins[0], 'sanctum');
    $this->getJson("/api/v1/admin/orders/{$foreign->id}/items")->assertNotFound();
    $this->postJson(lineReturnUrl($foreign, $foreignItem), lineReturnPayload(), lineReturnHeaders())->assertNotFound();
    $this->postJson(lineReturnUrl($order, $foreignItem), lineReturnPayload(), lineReturnHeaders())->assertNotFound();

    $waiter = Employee::factory()->create();
    $this->actingAs($waiter->user, 'sanctum');
    $this->postJson(lineReturnUrl($order, $item), lineReturnPayload(), lineReturnHeaders())->assertForbidden();
    $this->assertDatabaseCount('order_item_returns', 0);
});

test('product JSON and XLSX reconcile returns by refund date and retain tenant and modifier filters', function () {
    [$order, $item, $second] = returnableProductSale();
    $this->postJson(lineReturnUrl($order, $item), lineReturnPayload(), lineReturnHeaders())->assertCreated();
    $this->postJson(lineReturnUrl($order, $second), lineReturnPayload([
        'amount' => '10.00', 'tax_amount' => '0.00', 'returned_at' => '2026-11-07 00:00:00',
    ]), lineReturnHeaders())->assertCreated();
    $this->actingAs($this->admins[1], 'sanctum');
    [$foreign, $foreignItem] = returnableProductSale();
    $this->postJson(lineReturnUrl($foreign, $foreignItem), lineReturnPayload(), lineReturnHeaders())->assertCreated();
    $this->actingAs($this->admins[0], 'sanctum');

    $report = '/api/v1/admin/reports/products?';
    $this->getJson($report.'from=2026-11-05&to=2026-11-05')->assertOk()
        ->assertJsonPath('data.totals.returns_amount', '0.00')->assertJsonPath('data.totals.total_sales', '48.00');
    $this->getJson($report.'from=2026-11-05&to=2026-11-06')->assertOk()
        ->assertJsonPath('data.totals.quantity_sold', 3)->assertJsonPath('data.totals.quantity_returned', 1)
        ->assertJsonPath('data.totals.gross_sales', '50.00')->assertJsonPath('data.totals.discount_total', '5.00')
        ->assertJsonPath('data.totals.returns_amount', '17.50')->assertJsonPath('data.totals.returns_tax_amount', '1.50')
        ->assertJsonPath('data.totals.net_sales', '27.50')->assertJsonPath('data.totals.tax_total', '1.50')
        ->assertJsonPath('data.totals.total_sales', '29.00');

    $filtered = $report.'from=2026-11-06&to=2026-11-06&modifier=sin';
    $this->getJson($filtered)->assertOk()->assertJsonCount(1, 'data.products')
        ->assertJsonPath('data.products.0.menu_item_id', $item->menu_item_id)
        ->assertJsonPath('data.totals.quantity_sold', 0)->assertJsonPath('data.totals.quantity_returned', 1)
        ->assertJsonPath('data.totals.total_sales', '-19.00');
    $this->getJson(str_replace('modifier=sin', 'modifier=con', $filtered))->assertOk()
        ->assertJsonCount(0, 'data.products')->assertJsonPath('data.totals.returns_amount', '0.00');

    $response = $this->getJson($filtered.'&format=xlsx')->assertOk()->assertDownload('products.xlsx');
    $path = $response->baseResponse->getFile()->getPathname();
    try {
        $workbook = IOFactory::createReader('Xlsx')->load($path);
        [$headers, $row] = $workbook->getSheetByName('products')->toArray(null, false, false);
        $product = array_combine($headers, $row);
        $summary = array_column(array_slice($workbook->getSheetByName('Resumen')->toArray(null, false, false), 1), 1, 0);
        expect($product['returns_amount'])->toBe(17.5)->and($product['returns_tax_amount'])->toBe(1.5)
            ->and($summary['totals.total_sales'])->toBe(-19.0);
    } finally {
        unlink($path);
    }
});
