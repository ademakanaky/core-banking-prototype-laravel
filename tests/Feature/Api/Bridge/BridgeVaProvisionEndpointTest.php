<?php

/**
 * Integration tests for POST /api/v1/user/bridge-va-provision — the explicit
 * retry endpoint mobile calls on ramp-screen mount when
 * bridge-setup-status reports kycStatus=approved && !virtualAccountReady.
 */

declare(strict_types=1);

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'kyc.providers.bridge.api_key'  => 'sk_test',
        'kyc.providers.bridge.base_url' => 'https://api.bridge.xyz',
    ]);
});

function provisionAddress(User $user): BlockchainAddress
{
    /** @var BlockchainAddress $row */
    $row = BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'polygon',
        'address'    => '0xprovision',
        'public_key' => '0x' . str_repeat('a', 128),
        'is_active'  => true,
    ]);

    return $row;
}

it('rejects unauthenticated requests', function () {
    $this->postJson('/api/v1/user/bridge-va-provision')->assertStatus(401);
});

it('returns 409 KYC_NOT_APPROVED when bridge_customer is missing', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $this->postJson('/api/v1/user/bridge-va-provision')
        ->assertStatus(409)
        ->assertJsonPath('error', 'KYC_NOT_APPROVED');
});

it('returns 409 KYC_NOT_APPROVED when bridge_customer KYC is pending', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_va_pending',
        'kyc_status'         => BridgeCustomer::KYC_PENDING,
        'developer_fee_bps'  => 75,
    ]);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $this->postJson('/api/v1/user/bridge-va-provision')
        ->assertStatus(409)
        ->assertJsonPath('error', 'KYC_NOT_APPROVED');
});

it('returns 409 VA_ALREADY_PROVISIONED when a virtual_account_id already exists', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_va_done',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id' => 'va_already',
        'developer_fee_bps'  => 75,
    ]);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    Http::fake();

    $this->postJson('/api/v1/user/bridge-va-provision')
        ->assertStatus(409)
        ->assertJsonPath('error', 'VA_ALREADY_PROVISIONED');

    Http::assertSentCount(0);
});

it('returns 409 NO_POLYGON_ADDRESS when KYC is approved but no Polygon address exists', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_va_noaddr',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    Http::fake();

    $this->postJson('/api/v1/user/bridge-va-provision')
        ->assertStatus(409)
        ->assertJsonPath('error', 'NO_POLYGON_ADDRESS');

    Http::assertSentCount(0);
});

it('happy path: provisions VA and returns ready state + rails', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_va_retry_ok',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);
    provisionAddress($user);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_va_retry_ok/virtual_accounts' => Http::response([
            'id'              => 'va_from_retry',
            'destination'     => ['payment_rail' => 'polygon'],
            'source'          => ['iban' => 'GB29NWBK60161331926819'],
            'supported_rails' => ['ach', 'sepa_instant'],
        ], 201),
    ]);

    $this->postJson('/api/v1/user/bridge-va-provision')
        ->assertOk()
        ->assertExactJson([
            'virtualAccountReady' => true,
            'supportedRails'      => ['ach', 'sepa_instant'],
        ]);
});

it('reports virtualAccountReady=false when Bridge call fails (handler swallows)', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_va_bridge_down',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);
    provisionAddress($user);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_va_bridge_down/virtual_accounts' => Http::response(
            ['error' => 'bridge_down'],
            503,
        ),
    ]);

    $this->postJson('/api/v1/user/bridge-va-provision')
        ->assertOk()
        ->assertJsonPath('virtualAccountReady', false);
});
