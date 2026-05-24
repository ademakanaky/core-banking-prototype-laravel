<?php

/**
 * BridgeKycProvider lazy customer creation + hosted-link issuance flow.
 * Driven through POST /api/v1/user/bridge-kyc-link end-to-end; Http::fake
 * stands in for the Bridge.xyz REST API.
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'kyc.routing.ramp'                    => 'bridge',
        'kyc.providers.bridge.api_key'        => 'sk_test_fake',
        'kyc.providers.bridge.base_url'       => 'https://api.bridge.xyz',
        'kyc.providers.bridge.webhook_secret' => 'whsec_fake',
    ]);
});

it('lazily creates a Bridge customer + KYC link on first POST /bridge-kyc-link', function () {
    Http::fake([
        'api.bridge.xyz/v0/customers' => Http::response([
            'id'        => 'cust_test_999',
            'full_name' => 'Acme Testing',
        ], 201),
        'api.bridge.xyz/v0/customers/cust_test_999/kyc_links' => Http::response([
            'url'        => 'https://kyc.bridge.xyz/abc-token-xyz',
            'expires_at' => now()->addHours(2)->toIso8601String(),
        ], 201),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $response = $this->postJson('/api/v1/user/bridge-kyc-link')
        ->assertOk();

    expect($response->json('url'))->toBe('https://kyc.bridge.xyz/abc-token-xyz');
    expect($response->json('expiresAt'))->toBeString();

    // Local row created + status moved to pending
    $customer = BridgeCustomer::where('user_id', $user->id)->firstOrFail();
    expect($customer->bridge_customer_id)->toBe('cust_test_999');
    expect($customer->kyc_status)->toBe(BridgeCustomer::KYC_PENDING);
    expect($customer->kyc_link_url)->toBe('https://kyc.bridge.xyz/abc-token-xyz');

    // Verify outgoing calls had idempotency keys
    Http::assertSent(function ($req) {
        return $req->url() === 'https://api.bridge.xyz/v0/customers'
            && $req->hasHeader('Idempotency-Key');
    });
});

it('reuses an existing bridge_customer row and an unexpired KYC link without hitting Bridge', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'             => $user->id,
        'bridge_customer_id'  => 'cust_reuse',
        'kyc_status'          => BridgeCustomer::KYC_PENDING,
        'kyc_link_url'        => 'https://kyc.bridge.xyz/already-valid',
        'kyc_link_expires_at' => now()->addHour(),
        'developer_fee_bps'   => 75,
    ]);

    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    Http::fake();

    $response = $this->postJson('/api/v1/user/bridge-kyc-link')
        ->assertOk();

    expect($response->json('url'))->toBe('https://kyc.bridge.xyz/already-valid');
    Http::assertNothingSent();
});

it('requests a fresh KYC link when the existing one has expired', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'             => $user->id,
        'bridge_customer_id'  => 'cust_expired',
        'kyc_status'          => BridgeCustomer::KYC_PENDING,
        'kyc_link_url'        => 'https://kyc.bridge.xyz/stale',
        'kyc_link_expires_at' => now()->subHour(),
        'developer_fee_bps'   => 75,
    ]);

    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_expired/kyc_links' => Http::response([
            'url'        => 'https://kyc.bridge.xyz/fresh',
            'expires_at' => now()->addHours(2)->toIso8601String(),
        ], 201),
    ]);

    $response = $this->postJson('/api/v1/user/bridge-kyc-link')
        ->assertOk();

    expect($response->json('url'))->toBe('https://kyc.bridge.xyz/fresh');
});

it('still returns 409 when KYC is already approved (no Bridge call)', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_done',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);

    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    Http::fake();

    $this->postJson('/api/v1/user/bridge-kyc-link')
        ->assertStatus(409)
        ->assertJsonPath('error', 'KYC_ALREADY_APPROVED');

    Http::assertNothingSent();
});
