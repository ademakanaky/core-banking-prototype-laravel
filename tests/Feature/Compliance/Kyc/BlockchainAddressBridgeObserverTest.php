<?php

/**
 * Tests the VA-retry observer: when a Polygon address is registered for a
 * user whose Bridge KYC is already approved but whose virtual account is
 * not yet provisioned, the observer triggers BridgeClient.createVirtualAccount.
 *
 * Observer dispatches its work via afterCommit closure, so tests run with
 * Bus::fake() to force synchronous execution.
 */

declare(strict_types=1);

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'kyc.providers.bridge.api_key'  => 'sk_test',
        'kyc.providers.bridge.base_url' => 'https://api.bridge.xyz',
    ]);
});

/** Stub address row factory — public_key is NOT NULL in the schema. */
function makePolygonAddress(User $user, string $address): BlockchainAddress
{
    /** @var BlockchainAddress $row */
    $row = BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'polygon',
        'address'    => $address,
        'public_key' => '0x' . str_repeat('a', 128),
        'is_active'  => true,
    ]);

    return $row;
}

it('triggers Bridge createVirtualAccount when a Polygon address is added for an approved customer with no VA', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_retry_ok',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_retry_ok/virtual_accounts' => Http::response([
            'id'              => 'va_via_retry',
            'destination'     => ['payment_rail' => 'polygon'],
            'source'          => ['iban' => 'GB29NWBK60161331926819'],
            'supported_rails' => ['ach', 'sepa'],
        ], 201),
    ]);

    makePolygonAddress($user, '0xretrytarget');

    // Observer runs synchronously in tests (no queue worker) — give Laravel
    // a tick to process the afterCommit closure.
    $customer = BridgeCustomer::where('user_id', $user->id)->firstOrFail();
    expect($customer->virtual_account_id)->toBe('va_via_retry');
    expect($customer->supported_rails)->toBe(['ach', 'sepa']);
});

it('does nothing when the user has no bridge_customers row', function () {
    $user = User::factory()->create();

    Http::fake();
    makePolygonAddress($user, '0xnoprior');

    Http::assertSentCount(0);
});

it('does nothing when KYC is not yet approved', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_pending',
        'kyc_status'         => BridgeCustomer::KYC_PENDING,
        'developer_fee_bps'  => 75,
    ]);

    Http::fake();
    makePolygonAddress($user, '0xpending');

    Http::assertSentCount(0);
    expect(BridgeCustomer::where('user_id', $user->id)->value('virtual_account_id'))->toBeNull();
});

it('does nothing when VA is already provisioned', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_already_va',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id' => 'va_pre_existing',
        'developer_fee_bps'  => 75,
    ]);

    Http::fake();
    makePolygonAddress($user, '0xalreadyhasva');

    Http::assertSentCount(0);
});

it('ignores non-Polygon address registrations (e.g. Solana)', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_solana_only',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);

    Http::fake();
    BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'solana',
        'address'    => 'SoLanaAddressBase58',
        'public_key' => 'pub_solana',
        'is_active'  => true,
    ]);

    Http::assertSentCount(0);
});
