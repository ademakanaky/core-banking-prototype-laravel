<?php

/**
 * Integration tests for the /api/v1/user/bridge-* endpoints (handover §3.3).
 *
 * Status endpoint is fully wired against bridge_customers + the KYC router.
 * KYC-link endpoint surfaces the BridgeKycProvider's "deferred" exception
 * as a structured 501 so mobile gets a deterministic signal pending the
 * BridgeProvider PR (§3.1).
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'kyc.routing.trustcert' => 'ondato',
        'kyc.routing.ramp'      => 'bridge',
        'kyc.routing.cards'     => 'bridge',
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

it('surfaces BridgeKycProvider deferred state as 501 PROVIDER_NOT_IMPLEMENTED', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $response = $this->postJson('/api/v1/user/bridge-kyc-link')
        ->assertStatus(501);

    expect($response->json('error'))->toBe('PROVIDER_NOT_IMPLEMENTED');
    expect($response->json('message'))->toContain('BridgeProvider PR');
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
