<?php

use App\Http\Controllers\Api\Admin\CashMenuInventoryReportsController;
use App\Http\Controllers\Api\Admin\CashMovementController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\InventoryItemController;
use App\Http\Controllers\Api\Admin\InventoryLossController;
use App\Http\Controllers\Api\Admin\MenuItemController;
use App\Http\Controllers\Api\Admin\OrdersReportController;
use App\Http\Controllers\Api\Admin\PaymentTransactionsReportController;
use App\Http\Controllers\Api\Admin\ProductsReportController;
use App\Http\Controllers\Api\Admin\PromotionController;
use App\Http\Controllers\Api\Admin\RemainingReportsController;
use App\Http\Controllers\Api\Admin\RestaurantConfigurationController;
use App\Http\Controllers\Api\Admin\SalesSummaryReportController;
use App\Http\Controllers\Api\Admin\SuperCategoryController;
use App\Http\Controllers\Api\Admin\TaxAssociationsReportController;
use App\Http\Controllers\Api\Admin\TaxClassController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\Chef\QueueController;
use App\Http\Controllers\Api\Customer\CustomerApiController;
use App\Http\Controllers\Api\ExternalPlatformSaleController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\Waiter\MenuItemController as WaiterMenuItemController;
use App\Http\Controllers\Api\Waiter\OrderAdjustmentController;
use App\Http\Controllers\Api\Waiter\OrderCheckoutController;
use App\Http\Controllers\Api\Waiter\OrderController;
use App\Http\Controllers\Api\Waiter\OrderItemController;
use App\Http\Controllers\Api\WorkShiftController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('stripe/webhook', StripeWebhookController::class);
    Route::middleware(['auth:sanctum', 'account.active', 'role:customer'])->prefix('customer')->group(function () {
        Route::get('restaurants', [CustomerApiController::class, 'restaurants']);
        Route::get('restaurants/{restaurant}', [CustomerApiController::class, 'restaurant']);
        Route::get('restaurants/{restaurant}/menu', [CustomerApiController::class, 'menu']);
        Route::get('restaurants/{restaurant}/promotions', [CustomerApiController::class, 'promotions']);
        Route::post('restaurants/{restaurant}/orders', [CustomerApiController::class, 'store'])->middleware('throttle:10,1');
        Route::get('orders', [CustomerApiController::class, 'orders']);
        Route::get('orders/{order}', [CustomerApiController::class, 'show'])->whereNumber('order');
        Route::post('orders/{order}/payment', [CustomerApiController::class, 'payment'])->whereNumber('order')->middleware('throttle:10,1');
        Route::get('loyalty-balances', [CustomerApiController::class, 'points']);
    });
    Route::middleware(['auth:sanctum', 'account.active', 'role:chef'])->prefix('chef')->name('chef.')->group(function () {
        Route::get('queue', [QueueController::class, 'index']);
        Route::patch('queue/{item}', [QueueController::class, 'update']);
    });
    Route::middleware(['auth:sanctum', 'account.active', 'role:waiter'])->prefix('waiter')->name('waiter.')->scopeBindings()->group(function () {
        Route::get('admin-authorizers', [OrderAdjustmentController::class, 'authorizers']);
        Route::apiResource('menu-items', WaiterMenuItemController::class)->only(['index', 'show']);
        Route::apiResource('orders', OrderController::class)->only(['index', 'show', 'store']);
        Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
        Route::post('orders/{order}/customer', [OrderCheckoutController::class, 'linkCustomer']);
        Route::post('orders/{order}/checkout-preview', [OrderCheckoutController::class, 'preview']);
        Route::post('orders/{order}/close', [OrderCheckoutController::class, 'close']);
        Route::post('orders/{order}/discounts', [OrderAdjustmentController::class, 'discount']);
        Route::post('orders/{order}/courtesies', [OrderAdjustmentController::class, 'courtesy']);
        Route::post('orders/{order}/tax-exemptions', [OrderAdjustmentController::class, 'exempt']);
        Route::post('orders/{order}/cancel', [OrderAdjustmentController::class, 'cancel']);
        Route::post('orders/{order}/items/{item}/cancel', [OrderAdjustmentController::class, 'cancelItem']);
        Route::post('orders/{order}/items', [OrderItemController::class, 'store']);
        Route::patch('orders/{order}/items/{item}', [OrderItemController::class, 'update']);
        Route::delete('orders/{order}/items/{item}', [OrderItemController::class, 'destroy']);
    });
    Route::middleware(['auth:sanctum', 'account.active', 'role:admin,waiter,chef'])->group(function () {
        Route::get('work-shifts', [WorkShiftController::class, 'index']);
        Route::get('work-shifts/{work_shift}', [WorkShiftController::class, 'show']);
        Route::post('work-shifts', [WorkShiftController::class, 'store']);
        Route::post('work-shifts/{work_shift}/clock-out', [WorkShiftController::class, 'clockOut']);
    });
    Route::middleware(['auth:sanctum', 'account.active', 'role:admin,waiter'])->group(function () {
        Route::apiResource('external-platform-sales', ExternalPlatformSaleController::class)->only(['index', 'show', 'store']);
        Route::apiResource('cash-registers', CashRegisterController::class)->only(['index', 'show', 'store']);
    });
    Route::post('cash-registers/{cash_register}/close', [CashRegisterController::class, 'close'])
        ->middleware(['auth:sanctum', 'account.active', 'role:waiter']);
    Route::middleware(['auth:sanctum', 'account.active', 'role:admin'])->prefix('admin')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('admin.dashboard');
        Route::get('reports/payment-transactions', PaymentTransactionsReportController::class)->name('admin.reports.payment-transactions');
        Route::get('reports/orders', OrdersReportController::class)->name('admin.reports.orders');
        Route::get('reports/tax-associations', TaxAssociationsReportController::class)->name('admin.reports.tax-associations');
        Route::get('reports/products', ProductsReportController::class)->name('admin.reports.products');
        Route::get('reports/cash-closures', [CashMenuInventoryReportsController::class, 'cashClosures'])->name('admin.reports.cash-closures');
        Route::get('reports/menu-audit', [CashMenuInventoryReportsController::class, 'menuAudit'])->name('admin.reports.menu-audit');
        Route::get('reports/inventory-value', [CashMenuInventoryReportsController::class, 'inventoryValue'])->name('admin.reports.inventory-value');
        Route::get('reports/inventory-losses', [CashMenuInventoryReportsController::class, 'inventoryLosses'])->name('admin.reports.inventory-losses');
        Route::get('reports/sales-summary', SalesSummaryReportController::class)->name('admin.reports.sales-summary');
        foreach (['courtesies', 'discounts', 'profit-margins', 'taxes-and-exemptions', 'tips', 'cancellations', 'external-platform-sales'] as $report) {
            Route::get("reports/{$report}", RemainingReportsController::class)->defaults('report', $report)->name("admin.reports.{$report}");
        }
        foreach (['employee-product-sales', 'work-shifts', 'payroll', 'sales-vs-labor'] as $report) {
            Route::get("reports/{$report}", [RemainingReportsController::class, 'employees'])->defaults('report', $report)->name("admin.reports.{$report}");
        }
        Route::apiResource('cash-movements', CashMovementController::class)->only(['index', 'show', 'store']);
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer');
        Route::apiResource('promotions', PromotionController::class)->except(['update']);
        Route::put('promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
        Route::apiResource('employees', EmployeeController::class)->except(['update']);
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::apiResource('menu-items', MenuItemController::class)->except(['update']);
        Route::put('menu-items/{menu_item}', [MenuItemController::class, 'update'])->name('menu-items.update');
        Route::apiResource('inventory-items', InventoryItemController::class)->except(['update']);
        Route::put('inventory-items/{inventory_item}', [InventoryItemController::class, 'update'])->name('inventory-items.update');
        Route::apiResource('inventory-losses', InventoryLossController::class)->only(['index', 'show', 'store']);
        Route::apiResource('tax-classes', TaxClassController::class)->except(['update']);
        Route::put('tax-classes/{tax_class}', [TaxClassController::class, 'update'])->name('tax-classes.update');
        Route::apiResource('super-categories', SuperCategoryController::class)->except(['update']);
        Route::put('super-categories/{super_category}', [SuperCategoryController::class, 'update'])->name('super-categories.update');
        Route::apiResource('categories', CategoryController::class)->except(['update']);
        Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::get('/restaurant/configuration', [RestaurantConfigurationController::class, 'show']);
        Route::patch('/restaurant/configuration', [RestaurantConfigurationController::class, 'update']);
    });

    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware(['auth:sanctum', 'account.active']);

    Route::prefix('auth')->group(function () {
        Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1');
        Route::post('/verify-email', [VerifyEmailController::class, 'verify'])->middleware('throttle:6,1');
        Route::post('/verify-email/resend', [VerifyEmailController::class, 'resend'])->middleware('throttle:3,1');

        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1');
        Route::post('/login/verify-2fa', [AuthenticatedSessionController::class, 'verifyTwoFactor'])->middleware('throttle:6,1');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:sanctum');

        Route::post('/password/forgot', [PasswordResetLinkController::class, 'store'])->middleware('throttle:3,1');
        Route::post('/password/reset', [PasswordResetController::class, 'store'])->middleware('throttle:6,1');

        Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
            Route::post('/2fa/enable', [TwoFactorController::class, 'enable']);
            Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);
        });

        Route::middleware(['auth:sanctum', 'account.active', 'role:super_admin'])->prefix('super-admin')->group(function () {
            Route::apiResource('restaurants', RestaurantController::class)->except(['update', 'destroy'])->middleware('throttle:30,1');
            Route::put('restaurants/{restaurant}', [RestaurantController::class, 'update'])->name('restaurants.update')->middleware('throttle:30,1');
            Route::delete('/restaurants/{restaurant}', [RestaurantController::class, 'destroy'])->middleware('throttle:10,1');
            Route::post('/restaurants/{restaurant}/activate', [RestaurantController::class, 'activate']);
            Route::post('/restaurants/{restaurant}/suspend', [RestaurantController::class, 'suspend']);
        });
    });
});
