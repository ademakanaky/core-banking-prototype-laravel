<?php

/**
 * Tests the dedicated /api/v1/webhooks/bridge endpoint — signature
 * verification, KYC vs ramp dispatch, event-level dedupe via
 * processed_webhook_events, unsolicited-deposit retroactive session
 * creation.
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Domain\Subscription\Models\ProcessedWebhookEvent;
use App\Models\RampSession;
use App\Models\User;

beforeEach(function () {
    config([
        'kyc.providers.bridge.webhook_secret' => 'whsec_test',
        'kyc.providers.bridge.api_key'        => 'sk_test',
    ]);
});

/** Helper: produce a valid Bridge-Signature header for the given body. */
function signBridge(string $body, string $secret = 'whsec_test', ?int $ts = null): string
{
    $ts ??= time();
    $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

    return "t={$ts},v1={$sig}";
}

it('rejects a missing signature header', function () {
    $this->postJson('/api/v1/webhooks/bridge', ['type' => 'customer.kyc_link_completed'])
        ->assertStatus(401);
});

it('rejects a tampered body', function () {
    $body = (string) json_encode(['id' => 'evt_1', 'type' => 'customer.kyc_link_completed', 'data' => []]);
    $sig = signBridge($body);

    // Tamper by appending whitespace (signature was computed on original body)
    $this->call(
        'POST',
        '/api/v1/webhooks/bridge',
        [],
        [],
        [],
        ['HTTP_BRIDGE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body . ' ',
    )->assertStatus(401);
});

it('approves a bridge_customers row on customer.kyc_link_completed', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_42',
        'kyc_status'         => BridgeCustomer::KYC_PENDING,
        'developer_fee_bps'  => 75,
    ]);

    $body = (string) json_encode([
        'id'   => 'evt_kyc_1',
        'type' => 'customer.kyc_link_completed',
        'data' => ['object' => ['customer_id' => 'cust_42']],
    ]);

    $this->call(
        'POST',
        '/api/v1/webhooks/bridge',
        [],
        [],
        [],
        ['HTTP_BRIDGE_SIGNATURE' => signBridge($body), 'CONTENT_TYPE' => 'application/json'],
        $body,
    )->assertOk();

    expect(BridgeCustomer::where('user_id', $user->id)->value('kyc_status'))
        ->toBe(BridgeCustomer::KYC_APPROVED);

    // Dedupe row written
    expect(ProcessedWebhookEvent::where(['provider' => 'bridge', 'event_id' => 'evt_kyc_1'])->exists())
        ->toBeTrue();
});

it('rejects a bridge_customers row on customer.kyc_link_rejected', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_rej',
        'kyc_status'         => BridgeCustomer::KYC_PENDING,
        'developer_fee_bps'  => 75,
    ]);

    $body = (string) json_encode([
        'id'   => 'evt_kyc_rej_1',
        'type' => 'customer.kyc_link_rejected',
        'data' => ['object' => ['customer_id' => 'cust_rej']],
    ]);

    $this->call(
        'POST',
        '/api/v1/webhooks/bridge',
        [],
        [],
        [],
        ['HTTP_BRIDGE_SIGNATURE' => signBridge($body), 'CONTENT_TYPE' => 'application/json'],
        $body,
    )->assertOk();

    expect(BridgeCustomer::where('user_id', $user->id)->value('kyc_status'))
        ->toBe(BridgeCustomer::KYC_REJECTED);
});

it('is idempotent on duplicate event_id (returns duplicate, applies no side effect)', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_idem',
        'kyc_status'         => BridgeCustomer::KYC_PENDING,
        'developer_fee_bps'  => 75,
    ]);

    ProcessedWebhookEvent::create([
        'provider'     => 'bridge',
        'event_id'     => 'evt_dup',
        'event_type'   => 'customer.kyc_link_completed',
        'processed_at' => now(),
    ]);

    $body = (string) json_encode([
        'id'   => 'evt_dup',
        'type' => 'customer.kyc_link_completed',
        'data' => ['object' => ['customer_id' => 'cust_idem']],
    ]);

    $response = $this->call(
        'POST',
        '/api/v1/webhooks/bridge',
        [],
        [],
        [],
        ['HTTP_BRIDGE_SIGNATURE' => signBridge($body), 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertOk()->assertJson(['status' => 'duplicate']);

    // KYC status NOT mutated
    expect(BridgeCustomer::where('user_id', $user->id)->value('kyc_status'))
        ->toBe(BridgeCustomer::KYC_PENDING);
});

it('auto-creates a retroactive ramp_session for unsolicited virtual_account.activity', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_va',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id' => 'va_unsolicited_1',
        'developer_fee_bps'  => 75,
    ]);

    $body = (string) json_encode([
        'id'   => 'evt_va_1',
        'type' => 'virtual_account.activity',
        'data' => ['object' => [
            'virtual_account_id' => 'va_unsolicited_1',
            'source_amount'      => '250.00',
            'source_currency'    => 'USD',
            'destination_amount' => '249.75',
        ]],
    ]);

    $this->call(
        'POST',
        '/api/v1/webhooks/bridge',
        [],
        [],
        [],
        ['HTTP_BRIDGE_SIGNATURE' => signBridge($body), 'CONTENT_TYPE' => 'application/json'],
        $body,
    )->assertOk();

    $session = RampSession::where('user_id', $user->id)->firstOrFail();
    expect($session->source)->toBe(RampSession::SOURCE_BRIDGE_INITIATED);
    expect($session->provider)->toBe('bridge');
    expect($session->status)->toBe('processing');
    expect((float) $session->fiat_amount)->toBe(250.0);
    expect((float) $session->crypto_amount)->toBe(249.75);
});
