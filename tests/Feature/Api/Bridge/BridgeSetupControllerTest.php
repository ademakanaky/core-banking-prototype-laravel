<?php

/**
 * Integration tests for the /api/v1/user/bridge-* endpoints (handover §3.3).
 *
 * Status endpoint reads from bridge_customers via the KYC router. KYC-link
 * endpoint goes through BridgeKycProvider → BridgeClient (Http::fake stands
 * in for Bridge.xyz in tests).
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'kyc.routing.trustcert'         => 'ondato',
        'kyc.routing.ramp'              => 'bridge',
        'kyc.routing.cards'             => 'bridge',
        'kyc.providers.bridge.api_key'  => 'sk_test',
        'kyc.providers.bridge.base_url' => 'https://api.bridge.xyz',
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// GET /api/v1/user/bridge-setup-status
// ──────────────────────────────────────────────────────────────────────────────

it('rejects unauthenticated GET /bridge-setup-status', function () {
    $this->getJson('/api/v1/user/bridge-setup-status')
        ->assertStatus(401);
});

it('returns not_started state when the user has no bridge_customers row', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $this->getJson('/api/v1/user/bridge-setup-status')
        ->assertOk()
        ->assertExactJson([
            'kycStatus'           => 'not_started',
            'virtualAccountReady' => false,
            'supportedRails'      => [],
        ]);
});

it('returns the bridge_customers state when one exists', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_setup_1',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id' => 'va_setup_1',
        'supported_rails'    => ['ach', 'sepa_instant'],
        'developer_fee_bps'  => 75,
    ]);

    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $this->getJson('/api/v1/user/bridge-setup-status')
        ->assertOk()
        ->assertExactJson([
            'kycStatus'           => 'approved',
            'virtualAccountReady' => true,
            'supportedRails'      => ['ach', 'sepa_instant'],
        ]);
});

it('reports virtualAccountReady false when KYC approved but no virtual account yet', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_setup_2',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);

    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $this->getJson('/api/v1/user/bridge-setup-status')
        ->assertOk()
        ->assertJsonPath('virtualAccountReady', false);
});

// ──────────────────────────────────────────────────────────────────────────────
// POST /api/v1/user/bridge-kyc-link
// ──────────────────────────────────────────────────────────────────────────────

it('rejects unauthenticated POST /bridge-kyc-link', function () {
    $this->postJson('/api/v1/user/bridge-kyc-link')
        ->assertStatus(401);
});

it('returns the hosted KYC link URL when BridgeKycProvider is wired (post-§3.1)', function () {
    // After PR §3.1 landed, the deferred 501 path no longer applies; the
    // controller now returns a real Bridge-hosted KYC link URL on success.
    Http::fake([
        'api.bridge.xyz/v0/customers' => Http::response([
            'id'        => 'cust_setup_first',
            'full_name' => 'Setup User',
        ], 201),
        'api.bridge.xyz/v0/customers/cust_setup_first/kyc_links' => Http::response([
            'url'        => 'https://kyc.bridge.xyz/setup-token',
            'expires_at' => now()->addHours(2)->toIso8601String(),
        ], 201),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $response = $this->postJson('/api/v1/user/bridge-kyc-link')
        ->assertOk();

    expect($response->json('url'))->toBe('https://kyc.bridge.xyz/setup-token');
    expect($response->json('expiresAt'))->toBeString();
});

it('returns 409 when bridge_customers row exists and KYC is approved', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_already',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);

    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $this->postJson('/api/v1/user/bridge-kyc-link')
        ->assertStatus(409)
        ->assertJsonPath('error', 'KYC_ALREADY_APPROVED');
});
