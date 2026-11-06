<?php

use App\Models\AdvanceOrderPayment;
use App\Services\CustomerOrderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:reconcile', function () {
    $failed = 0;
    AdvanceOrderPayment::withoutGlobalScope('restaurant')->where(function ($work) {
        $work->where(fn ($stripe) => $stripe->whereNotNull('payment_intent_id')->where(fn ($states) => $states
            ->whereIn('status', ['pending', 'authorized', 'refund_pending', 'refund_failed'])
            ->orWhere(fn ($captured) => $captured->where('status', 'captured')->whereIn('order_id',
                DB::table('orders')->whereNull('closed_at')->select('id')))))
            ->orWhere(fn ($mail) => $mail->whereNotNull('cancellation_requested_at')->whereNull('cancellation_notified_at'));
    })->orderBy('id')->chunkById(100, function ($payments) use (&$failed) {
        foreach ($payments as $payment) {
            try {
                app(CustomerOrderService::class)->reconcile($payment);
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }
    });

    return $failed > 0 ? 1 : 0;
})->purpose('Recover Stripe authorization, capture, release, refunds and cancellation notices');

Artisan::command('payments:retry-refunds', fn () => $this->call('payments:reconcile'))
    ->purpose('Compatibility alias for payments:reconcile');

Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
