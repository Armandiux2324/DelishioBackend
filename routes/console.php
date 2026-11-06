<?php

use App\Models\AdvanceOrderPayment;
use App\Services\CustomerOrderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:retry-refunds', function () {
    $failed = 0;
    AdvanceOrderPayment::withoutGlobalScope('restaurant')->where('refund_pending', true)->orderBy('id')->chunkById(100, function ($payments) use (&$failed) {
        foreach ($payments as $payment) {
            try {
                app(CustomerOrderService::class)->retryRefund($payment);
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }
    });

    return $failed > 0 ? 1 : 0;
})->purpose('Retry incomplete refunds for rejected advance orders');

Schedule::command('payments:retry-refunds')->everyMinute()->withoutOverlapping();
