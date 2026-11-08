<?php

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryLoss;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurants = collect(['A', 'B'])->map(fn (string $name) => Restaurant::create([
        'name' => $name, 'created_by_user_id' => $creator->id,
    ]));
    $this->admins = $this->restaurants->map(fn (Restaurant $restaurant) => app(RestaurantContext::class)
        ->run($restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user);
    $this->actingAs($this->admins[0], 'sanctum');
});

function cashMenuInventoryReportUrl(string $report, array $filters = []): string
{
    $filters += $report === 'inventory-value' ? [] : ['from' => '2026-11-06', 'to' => '2026-11-06'];

    return '/api/v1/admin/reports/'.$report.'?'.http_build_query($filters);
}

test('cash closures reconcile mixed payments and filter by close date register and restaurant', function () {
    $waiter = Employee::factory()->create()->user;
    $register = CashRegister::factory()->create(['opened_at' => '2026-11-05 22:00:00']);
    $order = Order::factory()->create(['cash_register_id' => $register->id, 'waiter_employee_id' => $waiter->id,
        'status' => 'closed', 'closed_at' => '2026-11-05 23:59:59', 'tip_amount' => '10.00', 'total' => '60.00']);
    $order->payments()->create(['method' => 'cash', 'amount' => '34.20']);
    $order->payments()->create(['method' => 'card', 'amount' => '25.80']);
    CashMovement::factory()->create(['cash_register_id' => $register->id, 'type' => 'cash_in', 'amount' => '10.25', 'reason' => null]);
    CashMovement::factory()->create(['cash_register_id' => $register->id, 'amount' => '4.10']);
    $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
    $this->actingAs($waiter, 'sanctum')->postJson('/api/v1/cash-registers/'.$register->id.'/close', ['counted_cash' => '140.30'])
        ->assertOk()->assertJsonPath('data.expected_cash', '140.35')->assertJsonPath('data.difference', '-0.05');

    $second = CashRegister::factory()->create(['opening_amount' => '20.00', 'opened_at' => '2026-11-06 10:00:00',
        'closed_at' => '2026-11-06 23:59:59', 'expected_cash' => '20.00', 'counted_cash' => '21.00', 'difference' => '1.00']);
    CashRegister::factory()->create(['closed_at' => '2026-11-07 00:00:00']);
    CashRegister::factory()->create(['opened_at' => '2026-11-06 12:00:00']);
    $this->actingAs($this->admins[1], 'sanctum');
    $foreign = CashRegister::factory()->create(['closed_at' => '2026-11-06 17:00:00', 'expected_cash' => '100.00', 'counted_cash' => '100.00', 'difference' => '0.00']);

    $this->actingAs($this->admins[0], 'sanctum')->getJson(cashMenuInventoryReportUrl('cash-closures'))
        ->assertOk()->assertJsonCount(2, 'data.closures')
        ->assertJsonPath('data.closures.0.id', $register->id)
        ->assertJsonPath('data.closures.1.id', $second->id)
        ->assertJsonPath('data.totals', [
            'closure_count' => 2, 'opening_amount' => '120.00', 'cash_sales' => '34.20',
            'cash_in' => '10.25', 'cash_out' => '4.10', 'tip_amount' => '10.00',
            'expected_cash' => '160.35', 'counted_cash' => '161.30', 'difference' => '0.95',
        ]);
    $this->getJson(cashMenuInventoryReportUrl('cash-closures', ['cash_register_id' => $register->id]))
        ->assertOk()->assertJsonCount(1, 'data.closures')->assertJsonPath('data.totals.difference', '-0.05');
    $this->getJson(cashMenuInventoryReportUrl('cash-closures', ['cash_register_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
});

test('menu audit keeps deleted dishes and actors and isolates snapshots by date employee and dish', function () {
    $this->travelTo(now()->setDate(2026, 11, 5)->startOfDay());
    $menu = MenuItem::factory()->create(['name' => 'Sopa', 'sale_price' => '10.00']);
    $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
    $menu->update(['sale_price' => '12.50']);
    $otherMenu = MenuItem::factory()->create();
    $secondAdmin = Employee::factory()->create(['role' => 'admin'])->user;
    $this->actingAs($secondAdmin, 'sanctum');
    $menu->delete();
    $secondAdmin->delete();
    $this->actingAs($this->admins[1], 'sanctum');
    $foreignMenu = MenuItem::factory()->create();

    $this->actingAs($this->admins[0], 'sanctum')->getJson(cashMenuInventoryReportUrl('menu-audit'))
        ->assertOk()->assertJsonCount(3, 'data.entries')
        ->assertJsonPath('data.entries.0.action', 'delete')
        ->assertJsonPath('data.entries.0.employee_name', $secondAdmin->name)
        ->assertJsonPath('data.entries.0.menu_item_name', 'Sopa')
        ->assertJsonPath('data.entries.0.new_value', null)
        ->assertJsonPath('data.entries.1.menu_item_id', $otherMenu->id)
        ->assertJsonPath('data.totals.by_action', ['delete' => 1, 'create' => 1, 'price_change' => 1]);
    $this->getJson(cashMenuInventoryReportUrl('menu-audit', ['employee_id' => $this->admins[0]->id, 'menu_item_id' => $menu->id]))
        ->assertOk()->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.username', $this->admins[0]->username)
        ->assertJsonPath('data.entries.0.created_at', '2026-11-06T00:00:00.000000Z')
        ->assertJsonPath('data.entries.0.old_value.sale_price', '10.00')
        ->assertJsonPath('data.entries.0.new_value.sale_price', '12.50')
        ->assertJsonMissingPath('data.entries.0.pin');
    $this->getJson(cashMenuInventoryReportUrl('menu-audit', ['employee_id' => $this->admins[1]->id, 'menu_item_id' => $foreignMenu->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['employee_id', 'menu_item_id']);
});

test('inventory valuation totals current stock of active local items with decimal precision', function () {
    $item = InventoryItem::factory()->create(['unit' => 'kg', 'unit_cost' => '1.2345', 'current_stock' => '2.500']);
    InventoryItem::factory()->create(['unit' => 'pza', 'unit_cost' => '2.0000', 'current_stock' => '4.000']);
    InventoryItem::factory()->create(['unit_cost' => '100.0000'])->delete();
    $this->actingAs($this->admins[1], 'sanctum');
    $foreign = InventoryItem::factory()->create();

    $this->actingAs($this->admins[0], 'sanctum')->getJson(cashMenuInventoryReportUrl('inventory-value'))
        ->assertOk()->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.id', $item->id)
        ->assertJsonPath('data.items.0.unit', 'kg')
        ->assertJsonPath('data.items.0.current_stock', '2.500')
        ->assertJsonPath('data.items.0.unit_cost', '1.2345')
        ->assertJsonPath('data.items.0.value', '3.09')
        ->assertJsonPath('data.totals.value', '11.09');
    $this->getJson(cashMenuInventoryReportUrl('inventory-value', ['inventory_item_id' => $item->id]))
        ->assertOk()->assertJsonPath('data.totals', ['item_count' => 1, 'value' => '3.09']);
    $this->getJson(cashMenuInventoryReportUrl('inventory-value', ['inventory_item_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
    $this->getJson(cashMenuInventoryReportUrl('inventory-value', ['from' => '2026-11-06']))
        ->assertUnprocessable()->assertJsonValidationErrors('from');
});

test('losses retain deleted inventory and total quantities separately per ingredient', function () {
    $item = InventoryItem::factory()->create(['unit' => 'kg']);
    $other = InventoryItem::factory()->create(['unit' => 'pza']);
    foreach ([[$item, '0.125', '2026-11-06 00:00:00'], [$item, '1.010', '2026-11-06 23:59:59'],
        [$other, '2.000', '2026-11-06 12:00:00'], [$item, '8.000', '2026-11-07 00:00:00']] as [$ingredient, $quantity, $date]) {
        InventoryLoss::factory()->for($ingredient)->create(['quantity' => $quantity, 'created_at' => $date]);
    }
    $item->delete();
    $this->actingAs($this->admins[1], 'sanctum');
    $foreign = InventoryItem::factory()->create();
    InventoryLoss::factory()->for($foreign)->create(['created_at' => '2026-11-06 12:00:00']);

    $this->actingAs($this->admins[0], 'sanctum')->getJson(cashMenuInventoryReportUrl('inventory-losses'))
        ->assertOk()->assertJsonCount(3, 'data.losses')
        ->assertJsonPath('data.losses.0.inventory_item_id', $item->id)
        ->assertJsonPath('data.losses.0.registered_by_employee_id', $this->admins[0]->id)
        ->assertJsonPath('data.totals.loss_count', 3)
        ->assertJsonPath('data.totals.by_item.0', ['inventory_item_id' => $item->id, 'name' => $item->name, 'unit' => 'kg',
            'loss_count' => 2, 'quantity' => '1.135'])
        ->assertJsonPath('data.totals.by_item.1.quantity', '2.000');
    $this->getJson(cashMenuInventoryReportUrl('inventory-losses', ['inventory_item_id' => $item->id]))
        ->assertOk()->assertJsonCount(2, 'data.losses')->assertJsonCount(1, 'data.totals.by_item');
    $this->getJson(cashMenuInventoryReportUrl('inventory-losses', ['inventory_item_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
});

test('reports require administrator access and validate the date range', function () {
    $dated = ['cash-closures', 'menu-audit', 'inventory-losses'];
    foreach ($dated as $report) {
        $this->getJson(cashMenuInventoryReportUrl($report, ['to' => '2026-11-05']))
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }
    $this->actingAs(Employee::factory()->create()->user, 'sanctum');
    foreach ([...$dated, 'inventory-value'] as $report) {
        $this->getJson(cashMenuInventoryReportUrl($report))->assertForbidden();
    }
});
