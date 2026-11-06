<?php

namespace App\Services;

use App\Models\AdvanceOrderPayment;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

class StripeConnectGateway
{
    public function createIntent(AdvanceOrderPayment $payment): PaymentIntent
    {
        return $this->request(fn (StripeClient $stripe) => $stripe->paymentIntents->create([
            'amount' => $this->minorUnits($payment->amount), 'currency' => strtolower($payment->currency),
            'payment_method_types' => ['card'], 'capture_method' => 'manual', 'metadata' => ['delishio_order_id' => (string) $payment->order_id],
        ], ['stripe_account' => $payment->stripe_account_id, 'idempotency_key' => 'delishio-intent-'.$payment->request_key]));
    }

    public function retrieveIntent(AdvanceOrderPayment $payment): PaymentIntent
    {
        return $this->request(fn (StripeClient $stripe) => $stripe->paymentIntents->retrieve($payment->payment_intent_id, [],
            ['stripe_account' => $payment->stripe_account_id]));
    }

    public function captureIntent(AdvanceOrderPayment $payment): PaymentIntent
    {
        $intent = $this->retrieveIntent($payment);
        if ($intent->status === 'succeeded') {
            return $intent;
        }
        abort_unless($intent->status === 'requires_capture', 409, __('La autorización de pago ya no está disponible.'));

        return $this->request(fn (StripeClient $stripe) => $stripe->paymentIntents->capture($payment->payment_intent_id, [],
            ['stripe_account' => $payment->stripe_account_id, 'idempotency_key' => 'delishio-capture-'.$payment->request_key]));
    }

    public function voidIntent(AdvanceOrderPayment $payment): PaymentIntent
    {
        $intent = $this->retrieveIntent($payment);
        if (in_array($intent->status, ['canceled', 'succeeded'], true)) {
            return $intent;
        }

        return $this->request(fn (StripeClient $stripe) => $stripe->paymentIntents->cancel($payment->payment_intent_id, [],
            ['stripe_account' => $payment->stripe_account_id, 'idempotency_key' => 'delishio-void-'.$payment->request_key]));
    }

    public function refund(AdvanceOrderPayment $payment): Refund
    {
        return $this->request(function (StripeClient $stripe) use ($payment) {
            $options = ['stripe_account' => $payment->stripe_account_id];
            $failedReference = null;
            if ($payment->refund_reference !== null) {
                $prior = $stripe->refunds->retrieve($payment->refund_reference, [], $options);
                if (! in_array($prior->status, ['failed', 'canceled'], true)) {
                    return $prior;
                }
                $failedReference = $prior->id;
            }
            // Recover a refund whose HTTP response or database commit was lost, even after Stripe's idempotency window.
            $refunded = 0;
            $last = null;
            foreach ($stripe->refunds->all(['payment_intent' => $payment->payment_intent_id, 'limit' => 100], $options)->autoPagingIterator() as $refund) {
                if ($refund->status === 'succeeded') {
                    $refunded += $refund->amount;
                    $last = $refund;
                }
                $ours = ($refund->metadata->delishio_payment_id ?? null) === (string) $payment->id;
                if ($ours && in_array($refund->status, ['failed', 'canceled'], true)) {
                    $failedReference ??= $refund->id;
                }
                if (! in_array($refund->status, ['failed', 'canceled'], true)
                    && ($ours || $refund->amount === $this->minorUnits($payment->amount))) {
                    return $refund;
                }
            }
            if ($refunded >= $this->minorUnits($payment->amount) && $last !== null) {
                return $last;
            }

            return $stripe->refunds->create(['payment_intent' => $payment->payment_intent_id,
                'metadata' => ['delishio_payment_id' => (string) $payment->id]],
                $options + ['idempotency_key' => 'delishio-refund-'.$payment->request_key.'-'.($failedReference ?? 'initial')]);
        });
    }

    public function minorUnits(string $amount): int
    {
        return BigDecimal::of($amount)->multipliedBy(100)->toInt();
    }

    private function request(Closure $callback): mixed
    {
        $secret = config('services.stripe.secret');
        abort_unless(is_string($secret) && $secret !== '', 503, __('Stripe no está configurado.'));
        $live = preg_match('/\A(?:sk|rk)_live_/', $secret) === 1;
        $test = preg_match('/\A(?:sk|rk)_test_/', $secret) === 1;
        abort_unless(($live || $test) && $live === config('services.stripe.livemode'), 503, __('El modo de Stripe no coincide con la clave configurada.'));
        CurlClient::instance()->setConnectTimeout(5)->setTimeout(15);
        try {
            return $callback(new StripeClient(['api_key' => $secret, 'max_network_retries' => 2]));
        } catch (ApiErrorException $exception) {
            Log::warning('stripe_request_failed', ['stripe_code' => $exception->getStripeCode(), 'request_id' => $exception->getRequestId()]);
            abort(503, __('No se pudo completar la operación con Stripe. Intenta nuevamente.'));
        }
    }
}
