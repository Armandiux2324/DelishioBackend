<?php

use App\Models\Employee;
use App\Models\ExternalPlatformSale;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurants = collect(['A', 'B'])->map(fn (string $name) => Restaurant::create([
        'name' => $name, 'created_by_user_id' => $creator->id,
    ]));
    $this->admins = $this->restaurants->map(fn (Restaurant $restaurant) => app(RestaurantContext::class)
        ->run($restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user);
    $this->config = '/api/v1/admin/restaurant/configuration';
    $this->actingAs($this->admins[0], 'sanctum');
});

function payrollWeek(Employee $employee): WorkShift
{
    foreach (['02', '03', '04', '05'] as $day) {
        WorkShift::factory()->create(['employee_id' => $employee->user_id, 'clock_in' => "2026-11-$day 09:00:00",
            'clock_out' => "2026-11-$day 17:00:00"]);
    }
    WorkShift::factory()->create(['employee_id' => $employee->user_id, 'clock_in' => '2026-11-07 09:00:00',
        'clock_out' => '2026-11-07 13:30:00']);

    return WorkShift::factory()->create(['employee_id' => $employee->user_id, 'clock_in' => '2026-11-08 22:00:00',
        'clock_out' => '2026-11-09 02:00:00']);
}

test('overtime policy defaults and changes are validated and scoped by restaurant', function () {
    $this->getJson($this->config)->assertOk()->assertJsonPath('data.overtime_weekly_hours', '40.00')
        ->assertJsonPath('data.overtime_multiplier', '1.50');
    $this->patchJson($this->config, ['overtime_weekly_hours' => '37.50', 'overtime_multiplier' => '2.00'])
        ->assertOk()->assertJsonPath('data.overtime_weekly_hours', '37.50')->assertJsonPath('data.overtime_multiplier', '2.00');
    $this->patchJson($this->config, ['overtime_weekly_hours' => '168.01', 'overtime_multiplier' => '0.99'])
        ->assertUnprocessable()->assertJsonValidationErrors(['overtime_weekly_hours', 'overtime_multiplier']);
    $this->patchJson($this->config, ['overtime_weekly_hours' => null, 'overtime_multiplier' => '1.001'])
        ->assertUnprocessable()->assertJsonValidationErrors(['overtime_weekly_hours', 'overtime_multiplier']);
    $this->getJson($this->config)->assertOk()->assertJsonPath('data.overtime_weekly_hours', '37.50')
        ->assertJsonPath('data.overtime_multiplier', '2.00');

    $this->actingAs($this->admins[1], 'sanctum')->getJson($this->config)->assertOk()
        ->assertJsonPath('data.overtime_weekly_hours', '40.00')->assertJsonPath('data.overtime_multiplier', '1.50');
});

test('payroll uses earlier hours in the week, splits an overnight shift at Monday and reconciles the XLSX and labor cost', function () {
    $this->patchJson($this->config, ['overtime_weekly_hours' => '37.50', 'overtime_multiplier' => '2.00'])->assertOk();
    $employee = Employee::factory()->create(['hourly_rate' => '19.99']);
    $shift = payrollWeek($employee);
    WorkShift::factory()->create(['employee_id' => $employee->user_id, 'clock_in' => '2026-11-09 10:00:00']);
    $this->actingAs($this->admins[1], 'sanctum');
    $foreign = Employee::factory()->create(['hourly_rate' => '500.00']);
    payrollWeek($foreign);
    $this->actingAs($this->admins[0], 'sanctum');

    $payroll = '/api/v1/admin/reports/payroll?from=2026-11-08&to=2026-11-09&role=waiter';
    $this->getJson($payroll)->assertOk()->assertJsonCount(1, 'data.employees')
        ->assertJsonPath('data.overtime_weekly_hours', '37.50')->assertJsonPath('data.overtime_multiplier', '2.00')
        ->assertJsonPath('data.overtime_week_starts_on', 'monday')
        ->assertJsonPath('data.employees.0.employee_id', $employee->user_id)
        ->assertJsonPath('data.employees.0.shifts.0.id', $shift->id)
        ->assertJsonPath('data.employees.0.shifts.0.clock_in', '2026-11-08T22:00:00.000000Z')
        ->assertJsonPath('data.employees.0.shifts.0.clock_out', '2026-11-09T02:00:00.000000Z')
        ->assertJsonPath('data.employees.0.regular_hours', '3.0000')
        ->assertJsonPath('data.employees.0.overtime_hours', '1.0000')
        ->assertJsonPath('data.totals.open_shift_count', 1)
        ->assertJsonPath('data.totals.worked_hours', '4.0000')
        ->assertJsonPath('data.totals.regular_hours', '3.0000')
        ->assertJsonPath('data.totals.overtime_hours', '1.0000')
        ->assertJsonPath('data.totals.estimated_pay', '99.95');
    $this->getJson(str_replace('to=2026-11-09', 'to=2026-11-08', $payroll))->assertOk()
        ->assertJsonPath('data.totals.regular_hours', '1.0000')->assertJsonPath('data.totals.overtime_hours', '1.0000')
        ->assertJsonPath('data.totals.estimated_pay', '59.97');
    $this->getJson(str_replace('from=2026-11-08', 'from=2026-11-09', $payroll))->assertOk()
        ->assertJsonPath('data.totals.regular_hours', '2.0000')->assertJsonPath('data.totals.overtime_hours', '0.0000')
        ->assertJsonPath('data.totals.estimated_pay', '39.98');
    $this->getJson($payroll.'&employee_id='.$foreign->user_id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    $this->getJson(str_replace('/payroll?', '/sales-vs-labor?', $payroll))->assertOk()
        ->assertJsonPath('data.labor_basis', 'current_hourly_rate_with_overtime')
        ->assertJsonPath('data.totals.estimated_labor_cost', '99.95');

    $response = $this->getJson($payroll.'&format=xlsx')->assertOk()->assertDownload('payroll.xlsx');
    $path = $response->baseResponse->getFile()->getPathname();
    try {
        $workbook = IOFactory::createReader('Xlsx')->load($path);
        $summary = array_column(array_slice($workbook->getSheetByName('Resumen')->toArray(null, false, false), 1), 1, 0);
        expect($summary['overtime_weekly_hours'])->toBe(37.5)
            ->and($summary['totals.regular_hours'])->toBe(3.0)->and($summary['totals.overtime_hours'])->toBe(1.0)
            ->and($summary['totals.estimated_pay'])->toBe(99.95);
    } finally {
        unlink($path);
    }
});

test('the default multiplier pays fractional overtime using seconds and rounds per employee', function () {
    $employee = Employee::factory()->create(['hourly_rate' => '17.33']);
    payrollWeek($employee);
    WorkShift::factory()->create(['employee_id' => $employee->user_id, 'clock_in' => '2026-11-08 18:00:00',
        'clock_out' => '2026-11-08 20:00:20']);
    $this->getJson('/api/v1/admin/reports/payroll?from=2026-11-08&to=2026-11-08&employee_id='.$employee->user_id)
        ->assertOk()->assertJsonPath('data.totals.regular_hours', '3.5000')
        ->assertJsonPath('data.totals.overtime_hours', '0.5056')
        ->assertJsonPath('data.totals.overtime_seconds', 1820)
        ->assertJsonPath('data.totals.estimated_pay', '73.80');
});

test('restaurant time zone is validated and scoped to the administrator restaurant', function () {
    $this->getJson($this->config)->assertOk()->assertJsonPath('data.timezone', 'UTC');
    $this->patchJson($this->config, ['timezone' => 'America/Mexico_City'])->assertOk()
        ->assertJsonPath('data.timezone', 'America/Mexico_City');
    foreach (['MexicoCity', '+06:00', null] as $invalid) {
        $this->patchJson($this->config, ['timezone' => $invalid])
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
    }
    $this->getJson($this->config)->assertOk()->assertJsonPath('data.timezone', 'America/Mexico_City');
    $this->actingAs($this->admins[1], 'sanctum')->getJson($this->config)->assertOk()->assertJsonPath('data.timezone', 'UTC');
});

test('local Monday splits overtime and sales and labor use the same local dates', function () {
    $this->patchJson($this->config, ['timezone' => 'America/Mexico_City'])->assertOk();
    $employee = Employee::factory()->create(['hourly_rate' => '20.00']);
    foreach (['02', '03', '04', '05', '07'] as $day) {
        WorkShift::factory()->create(['employee_id' => $employee->user_id, 'clock_in' => "2026-11-$day 15:00:00",
            'clock_out' => $day === '07' ? "2026-11-$day 22:00:00" : "2026-11-$day 23:00:00"]);
    }
    $shift = WorkShift::factory()->create(['employee_id' => $employee->user_id, 'clock_in' => '2026-11-09 04:00:00',
        'clock_out' => '2026-11-09 08:00:00']);

    foreach (['2026-11-08 05:59:59', '2026-11-09 05:59:59', '2026-11-09 06:00:00'] as $at) {
        Order::factory()->create(['waiter_employee_id' => $employee->user_id, 'cash_register_id' => null,
            'status' => 'closed', 'closed_at' => $at, 'subtotal' => '100.00', 'total' => '100.00']);
        ExternalPlatformSale::factory()->create(['registered_by_employee_id' => $employee->user_id, 'sale_date' => $at,
            'total_amount' => '100.00', 'commission_amount' => '25.00']);
    }

    $sunday = '?from=2026-11-08&to=2026-11-08&employee_id='.$employee->user_id;
    $this->getJson('/api/v1/admin/reports/payroll'.$sunday)->assertOk()
        ->assertJsonPath('data.timezone', 'America/Mexico_City')
        ->assertJsonPath('data.employees.0.shifts.0.id', $shift->id)
        ->assertJsonPath('data.employees.0.shifts.0.clock_in', '2026-11-09T04:00:00.000000Z')
        ->assertJsonPath('data.totals.regular_hours', '1.0000')
        ->assertJsonPath('data.totals.overtime_hours', '1.0000')
        ->assertJsonPath('data.totals.estimated_pay', '50.00');
    $this->getJson('/api/v1/admin/reports/payroll?from=2026-11-09&to=2026-11-09&employee_id='.$employee->user_id)->assertOk()
        ->assertJsonPath('data.totals.regular_hours', '2.0000')->assertJsonPath('data.totals.overtime_hours', '0.0000')
        ->assertJsonPath('data.totals.estimated_pay', '40.00');
    $this->getJson('/api/v1/admin/reports/sales-vs-labor'.$sunday)->assertOk()
        ->assertJsonPath('data.timezone', 'America/Mexico_City')
        ->assertJsonPath('data.totals.net_consumption', '100.00')
        ->assertJsonPath('data.totals.external_platform_net_sales', '75.00')
        ->assertJsonPath('data.totals.estimated_labor_cost', '50.00')
        ->assertJsonPath('data.totals.labor_cost_percentage', '28.57');
    $this->getJson('/api/v1/admin/reports/work-shifts'.$sunday)->assertOk()
        ->assertJsonPath('data.timezone', 'UTC')->assertJsonPath('data.totals.worked_seconds', 0);

    $response = $this->getJson('/api/v1/admin/reports/payroll'.$sunday.'&format=xlsx')->assertOk()->assertDownload('payroll.xlsx');
    $path = $response->baseResponse->getFile()->getPathname();
    try {
        $workbook = IOFactory::createReader('Xlsx')->load($path);
        $summary = array_column(array_slice($workbook->getSheetByName('Resumen')->toArray(null, false, false), 1), 1, 0);
        expect($summary['timezone'])->toBe('America/Mexico_City')
            ->and($summary['totals.overtime_hours'])->toBe(1.0);
    } finally {
        unlink($path);
    }
});

test('daylight saving uses the actual elapsed hours in a local day', function (string $date, string $clockIn, string $clockOut, string $hours, string $pay) {
    $this->patchJson($this->config, ['timezone' => 'America/New_York'])->assertOk();
    $employee = Employee::factory()->create(['hourly_rate' => '20.00']);
    WorkShift::factory()->create(['employee_id' => $employee->user_id, 'clock_in' => $clockIn, 'clock_out' => $clockOut]);
    $this->getJson("/api/v1/admin/reports/payroll?from=$date&to=$date")->assertOk()
        ->assertJsonPath('data.timezone', 'America/New_York')
        ->assertJsonPath('data.totals.regular_hours', $hours)
        ->assertJsonPath('data.totals.overtime_hours', '0.0000')
        ->assertJsonPath('data.totals.estimated_pay', $pay);
})->with([
    'spring forward' => ['2026-03-08', '2026-03-08 05:00:00', '2026-03-09 05:00:00', '23.0000', '460.00'],
    'fall back' => ['2026-11-01', '2026-11-01 04:00:00', '2026-11-02 06:00:00', '25.0000', '500.00'],
]);
