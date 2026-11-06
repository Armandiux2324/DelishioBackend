<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvanceOrderPayment;
use App\Services\CustomerOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CustomerOrderService $service): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503, __('Stripe no está configurado.'));
        try {
            $event = Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), $secret);
        } catch (UnexpectedValueException|SignatureVerificationException $exception) {
            abort(400, __('Firma de Stripe inválida.'));
        }
        abort_unless(is_bool($event->livemode) && $event->livemode === config('services.stripe.livemode'), 400);
        if (in_array($event->type, ['payment_intent.succeeded', 'payment_intent.canceled'], true)) {
            $intent = $event->data->object;
            abort_unless($intent instanceof PaymentIntent && is_string($intent->id) && is_string($event->account), 400);
            $payment = AdvanceOrderPayment::withoutGlobalScope('restaurant')->where('payment_reference', $intent->id)
                ->where('stripe_account_id', $event->account)->first();
            if ($payment !== null) {
                if ($event->type === 'payment_intent.succeeded') {
                    $service->acceptPayment($payment, $intent);
                } else {
                    $service->cancelPayment($payment, $intent);
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
