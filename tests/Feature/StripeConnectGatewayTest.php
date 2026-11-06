<?php

use App\Models\AdvanceOrderPayment;
use App\Services\StripeConnectGateway;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

class CustomerApiStripeTransport implements ClientInterface
{
    public array $calls = [];

    public array $responses = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->calls[] = compact('method', 'absUrl', 'headers', 'params');
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new RuntimeException('Unexpected Stripe HTTP request');
        }

        return [json_encode($response['body'], JSON_THROW_ON_ERROR), $response['code'] ?? 200, ['request-id' => 'req_local']];
    }
}

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_local', 'services.stripe.livemode' => false]);
    $this->transport = new CustomerApiStripeTransport;
    ApiRequestor::setHttpClient($this->transport);
    $this->payment = new AdvanceOrderPayment(['order_id' => 42, 'stripe_account_id' => 'acct_restaurant',
        'currency' => 'USD', 'amount' => '113.30', 'request_key' => '11111111-1111-4111-8111-111111111111']);
    $this->payment->forceFill(['id' => 7]);
    $this->gateway = new StripeConnectGateway;
});

afterEach(function () {
    ApiRequestor::setHttpClient(new CurlClient);
});

test('the actual SDK sends server amount currency direct account and stable idempotency on intent creation', function () {
    $this->transport->responses = [['body' => ['object' => 'payment_intent', 'id' => 'pi_created', 'amount' => 11330]]];
    expect($this->gateway->createIntent($this->payment)->id)->toBe('pi_created');
    $call = $this->transport->calls[0];
    expect($call['method'])->toBe('post')->and($call['absUrl'])->toBe('https://api.stripe.com/v1/payment_intents')
        ->and($call['params']['amount'])->toBe(11330)->and($call['params']['currency'])->toBe('usd')
        ->and($call['params']['payment_method_types'])->toBe(['card'])
        ->and($call['params']['capture_method'])->toBe('manual')
        ->and($call['params']['metadata']['delishio_order_id'])->toBe('42')
        ->and(implode("\n", $call['headers']))->toContain('Stripe-Account: acct_restaurant')
        ->toContain('Idempotency-Key: delishio-intent-11111111-1111-4111-8111-111111111111');
});

test('capture and void use the original connected account and stable operation keys', function (string $operation, string $status, string $endpoint) {
    $this->payment->payment_intent_id = 'pi_existing';
    $this->transport->responses = [
        ['body' => ['object' => 'payment_intent', 'id' => 'pi_existing', 'status' => 'requires_capture']],
        ['body' => ['object' => 'payment_intent', 'id' => 'pi_existing', 'status' => $status]],
    ];
    expect($this->gateway->{$operation.'Intent'}($this->payment)->status)->toBe($status);
    $call = $this->transport->calls[1];
    expect($call['method'])->toBe('post')->and($call['absUrl'])->toBe('https://api.stripe.com/v1/payment_intents/pi_existing/'.$endpoint)
        ->and(implode("\n", $call['headers']))->toContain('Stripe-Account: acct_restaurant')
        ->toContain('Idempotency-Key: delishio-'.$operation.'-'.$this->payment->request_key);
})->with([['capture', 'succeeded', 'capture'], ['void', 'canceled', 'cancel']]);

test('captured intents are recovered without another capture or cancellation', function (string $operation) {
    $this->payment->payment_intent_id = 'pi_existing';
    $this->transport->responses = [['body' => ['object' => 'payment_intent', 'id' => 'pi_existing', 'status' => 'succeeded']]];
    expect($this->gateway->{$operation.'Intent'}($this->payment)->status)->toBe('succeeded')->and($this->transport->calls)->toHaveCount(1);
})->with(['capture', 'void']);

test('a definitively failed refund gets a new idempotency key after checking for recovery', function () {
    $this->payment->payment_intent_id = 'pi_existing';
    $this->payment->refund_reference = 're_failed';
    $this->transport->responses = [
        ['body' => ['object' => 'refund', 'id' => 're_failed', 'status' => 'failed']],
        ['body' => ['object' => 'list', 'has_more' => false, 'data' => [], 'url' => '/v1/refunds']],
        ['body' => ['object' => 'refund', 'id' => 're_retry', 'status' => 'pending']],
    ];
    expect($this->gateway->refund($this->payment)->id)->toBe('re_retry');
    expect(implode("\n", $this->transport->calls[2]['headers']))
        ->toContain('Idempotency-Key: delishio-refund-'.$this->payment->request_key.'-re_failed');
});

test('the SDK retrieves an existing intent on its captured account', function () {
    $this->payment->payment_intent_id = 'pi_existing';
    $this->transport->responses = [['body' => ['object' => 'payment_intent', 'id' => 'pi_existing']]];
    expect($this->gateway->retrieveIntent($this->payment)->id)->toBe('pi_existing');
    $call = $this->transport->calls[0];
    expect($call['method'])->toBe('get')->and($call['absUrl'])->toBe('https://api.stripe.com/v1/payment_intents/pi_existing')
        ->and(implode("\n", $call['headers']))->toContain('Stripe-Account: acct_restaurant');
});

test('refund uses the same account searches for recovery first and requests the full remaining amount', function () {
    $this->payment->payment_intent_id = 'pi_paid';
    $this->transport->responses = [
        ['body' => ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/refunds']],
        ['body' => ['object' => 'refund', 'id' => 're_created', 'status' => 'succeeded']],
    ];
    expect($this->gateway->refund($this->payment)->id)->toBe('re_created');
    expect($this->transport->calls)->toHaveCount(2);
    $list = $this->transport->calls[0];
    $create = $this->transport->calls[1];
    expect($list['params']['payment_intent'])->toBe('pi_paid')->and($create['method'])->toBe('post')
        ->and($create['params'])->not->toHaveKey('amount')->and($create['params']['payment_intent'])->toBe('pi_paid')
        ->and($create['params']['metadata']['delishio_payment_id'])->toBe('7')
        ->and(implode("\n", $create['headers']))->toContain('Stripe-Account: acct_restaurant')
        ->toContain('Idempotency-Key: delishio-refund-11111111-1111-4111-8111-111111111111-initial');
});

test('a refund recovered from Stripe does not create another refund after a lost local response', function () {
    $this->payment->payment_intent_id = 'pi_paid';
    $this->transport->responses = [['body' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/refunds',
        'data' => [['object' => 'refund', 'id' => 're_found', 'amount' => 11330, 'status' => 'pending', 'metadata' => []]]]]];
    expect($this->gateway->refund($this->payment)->id)->toBe('re_found')->and($this->transport->calls)->toHaveCount(1);
});

test('saved refund references are polled instead of being created again', function () {
    $this->payment->refund_reference = 're_saved';
    $this->transport->responses = [['body' => ['object' => 'refund', 'id' => 're_saved', 'status' => 'succeeded']]];
    expect($this->gateway->refund($this->payment)->status)->toBe('succeeded')->and($this->transport->calls)->toHaveCount(1);
    expect($this->transport->calls[0]['absUrl'])->toBe('https://api.stripe.com/v1/refunds/re_saved');
});

test('already fully refunded external partial refunds do not cause another API refund', function () {
    $this->payment->payment_intent_id = 'pi_paid';
    $this->transport->responses = [['body' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/refunds',
        'data' => [
            ['object' => 'refund', 'id' => 're_part_1', 'amount' => 4000, 'status' => 'succeeded', 'metadata' => []],
            ['object' => 'refund', 'id' => 're_part_2', 'amount' => 7330, 'status' => 'succeeded', 'metadata' => []],
        ]]]];
    expect($this->gateway->refund($this->payment)->status)->toBe('succeeded')->and($this->transport->calls)->toHaveCount(1);
});

test('gateway failures do not expose provider diagnostics', function () {
    $this->transport->responses = [['code' => 400, 'body' => ['error' => ['type' => 'invalid_request_error',
        'code' => 'account_invalid', 'message' => 'Private provider diagnostic']]]];
    try {
        $this->gateway->createIntent($this->payment);
        $this->fail('The SDK error should not be returned');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503)->and($exception->getMessage())->not->toContain('Private provider diagnostic');
    }
});

test('missing credentials stop before any SDK HTTP request', function () {
    config(['services.stripe.secret' => null]);
    expect(fn () => $this->gateway->createIntent($this->payment))->toThrow(HttpException::class);
    expect($this->transport->calls)->toBe([]);
});

test('test environments cannot accidentally submit requests with a live secret', function () {
    config(['services.stripe.secret' => 'sk_live_placeholder']);
    expect(fn () => $this->gateway->createIntent($this->payment))->toThrow(HttpException::class);
    expect($this->transport->calls)->toBe([]);
});
