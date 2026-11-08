<?php

use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->admins = collect(['A', 'B'])->map(function (string $name) use ($creator) {
        $restaurant = Restaurant::create(['name' => $name, 'created_by_user_id' => $creator->id]);

        return app(RestaurantContext::class)->run($restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    });
    $this->actingAs($this->admins[0], 'sanctum');
});

function xlsxReportUrl(string $report, array $filters = []): string
{
    return '/api/v1/admin/reports/'.$report.'?'.http_build_query(['format' => 'xlsx', ...$filters]);
}

function readReportXlsx(TestResponse $response, string $report): Spreadsheet
{
    $response->assertOk()->assertDownload($report.'.xlsx')
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $path = $response->baseResponse->getFile()->getPathname();
    try {
        return IOFactory::createReader('Xlsx')->load($path);
    } finally {
        unlink($path);
    }
}

function xlsxReportSummary(Spreadsheet $workbook): array
{
    return array_column(array_slice($workbook->getSheetByName('Resumen')->toArray(null, false, false), 1), 1, 0);
}

function xlsxReportColumn(Worksheet $sheet, string $field): array
{
    $rows = $sheet->toArray(null, false, false);
    $headings = array_shift($rows);
    $index = array_search($field, $headings, true);
    expect($index)->not->toBeFalse();

    return array_column($rows, $index);
}

test('every administrative report returns a readable workbook', function (string $report, array $filters = []) {
    $workbook = readReportXlsx($this->getJson(xlsxReportUrl($report, $filters)), $report);
    expect(xlsxReportSummary($workbook)['report'])->toBe($report);
})->with(function () {
    $dates = ['from' => '2026-11-06', 'to' => '2026-11-06'];

    return [
        ['payment-transactions', $dates], ['orders', $dates + ['type' => 'paid']], ['tax-associations'],
        ['sales-summary', $dates], ['products', $dates], ['courtesies', $dates], ['discounts', $dates],
        ['profit-margins', $dates], ['taxes-and-exemptions', $dates], ['tips', $dates], ['cancellations', $dates],
        ['external-platform-sales', $dates], ['employee-product-sales', $dates], ['work-shifts', $dates],
        ['payroll', $dates], ['sales-vs-labor', $dates], ['cash-closures', $dates], ['menu-audit', $dates],
        ['inventory-value'], ['inventory-losses', $dates],
    ];
});

test('payment totals are numeric and respect date and tenant filters', function () {
    foreach ([$this->admins[0], $this->admins[1]] as $admin) {
        $this->actingAs($admin, 'sanctum');
        foreach (['2026-11-06 23:59:59', '2026-11-07 00:00:00'] as $date) {
            $order = Order::factory()->create(['waiter_employee_id' => null, 'cash_register_id' => null,
                'status' => 'closed', 'closed_at' => $date]);
            $order->payments()->create(['method' => 'cash', 'amount' => '26.75']);
            $order->payments()->create(['method' => 'card', 'amount' => '12.05', 'surcharge_amount' => '0.15']);
        }
    }

    $report = 'payment-transactions';
    $workbook = readReportXlsx($this->actingAs($this->admins[0], 'sanctum')->getJson(
        xlsxReportUrl($report, ['from' => '2026-11-06', 'to' => '2026-11-06']),
    ), $report);
    $summary = xlsxReportSummary($workbook);
    expect($summary['totals.transaction_count'])->toBe(2)
        ->and($summary['totals.total_amount'])->toBe(38.95)
        ->and($summary['totals.surcharge_amount'])->toBe(0.15);
    $payments = $workbook->getSheetByName('by_type');
    expect(xlsxReportColumn($payments, 'payment_type'))->toBe(['cash', 'card', 'transfer', 'online'])
        ->and(xlsxReportColumn($payments, 'amount'))->toBe([26.75, 12.05, 0.0, 0.0]);
});

test('orders and tax associations export all rows independently of the JSON page', function () {
    $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
    $local = Order::factory()->count(21)->create(['waiter_employee_id' => null, 'cash_register_id' => null]);
    Order::factory()->create(['waiter_employee_id' => null, 'cash_register_id' => null, 'status' => 'cancelled']);
    $items = MenuItem::factory()->count(21)->create();
    $this->actingAs($this->admins[1], 'sanctum');
    Order::factory()->create(['waiter_employee_id' => null, 'cash_register_id' => null]);
    MenuItem::factory()->create();
    $this->actingAs($this->admins[0], 'sanctum');

    $filters = ['from' => '2026-11-06', 'to' => '2026-11-06', 'type' => 'open', 'page' => 2];
    $this->getJson('/api/v1/admin/reports/orders?'.http_build_query($filters))->assertOk()->assertJsonCount(1, 'data');
    $orders = readReportXlsx($this->getJson(xlsxReportUrl('orders', $filters)), 'orders');
    expect(xlsxReportColumn($orders->getSheetByName('orders'), 'id'))->toBe($local->pluck('id')->reverse()->values()->all());
    $this->getJson('/api/v1/admin/reports/tax-associations?page=2')->assertOk()->assertJsonCount(1, 'data');
    $taxes = readReportXlsx($this->getJson(xlsxReportUrl('tax-associations', ['page' => 2])), 'tax-associations');
    expect(collect(xlsxReportColumn($taxes->getSheetByName('items'), 'menu_item_id'))->sort()->values()->all())
        ->toBe($items->pluck('id')->all());
});

test('inventory filters are tenant scoped and formula-like text remains literal', function () {
    $item = InventoryItem::factory()->create(['name' => '=1+1', 'current_stock' => '3.000', 'unit_cost' => '2.1250']);
    InventoryItem::factory()->create();
    $this->actingAs($this->admins[1], 'sanctum');
    $foreign = InventoryItem::factory()->create();
    $this->actingAs($this->admins[0], 'sanctum');
    $report = 'inventory-value';
    $workbook = readReportXlsx($this->getJson(xlsxReportUrl($report, ['inventory_item_id' => $item->id])), $report);

    $items = $workbook->getSheetByName('items');
    $name = $items->getCell('B2');
    expect($name->getValue())->toBe('=1+1')->and($name->getDataType())->toBe(DataType::TYPE_STRING)
        ->and(xlsxReportColumn($items, 'id'))->toBe([$item->id])
        ->and(xlsxReportSummary($workbook)['totals.value'])->toBe(6.38);
    $this->getJson(xlsxReportUrl($report, ['inventory_item_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
    $this->actingAs(Employee::factory()->create()->user, 'sanctum')->getJson(xlsxReportUrl($report))->assertForbidden();
});

test('payroll exports the employee totals and their shift details on separate sheets', function () {
    $employee = Employee::factory()->create(['hourly_rate' => '20.00']);
    $shift = WorkShift::factory()->create(['employee_id' => $employee->user_id,
        'clock_in' => '2026-11-06 12:00:00', 'clock_out' => '2026-11-06 13:30:00']);
    $other = Employee::factory()->create();
    WorkShift::factory()->create(['employee_id' => $other->user_id, 'clock_in' => '2026-11-06 12:00:00']);
    $report = 'payroll';
    $workbook = readReportXlsx($this->getJson(xlsxReportUrl($report, [
        'from' => '2026-11-06', 'to' => '2026-11-06', 'employee_id' => $employee->user_id,
    ])), $report);
    expect(xlsxReportColumn($workbook->getSheetByName('employees'), 'employee_id'))->toBe([$employee->user_id])
        ->and(xlsxReportColumn($workbook->getSheetByName('employees.shifts'), 'id'))->toBe([$shift->id])
        ->and(xlsxReportSummary($workbook)['totals.estimated_pay'])->toBe(30.0);
});

test('audit exports long snapshots without truncating cell text', function () {
    $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
    $description = str_repeat('a', 33000);
    $menu = MenuItem::factory()->create(['description' => $description]);
    $report = 'menu-audit';
    $workbook = readReportXlsx($this->getJson(xlsxReportUrl($report, [
        'from' => '2026-11-06', 'to' => '2026-11-06', 'menu_item_id' => $menu->id,
    ])), $report);
    $entries = $workbook->getSheetByName('entries');
    $parts = [];
    foreach (range(1, 3) as $part) {
        $parts[] = xlsxReportColumn($entries, 'new_value.description.part_'.$part)[0];
    }
    expect(implode('', $parts))->toBe($description);
});
