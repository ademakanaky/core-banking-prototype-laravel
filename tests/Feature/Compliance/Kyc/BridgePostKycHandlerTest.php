<?php

/**
 * Post-KYC side-effect chain: WS broadcast events + push notification
 * fallback + virtual-account auto-provisioning. Wired through
 * BridgeWebhookController in production; tested here directly against
 * BridgePostKycHandler so the assertions are simple.
 */

declare(strict_types=1);

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Compliance\Kyc\Events\Broadcast\BridgeKycCompleted;
use App\Domain\Compliance\Kyc\Events\Broadcast\BridgeKycRejected;
use App\Domain\Compliance\Kyc\Events\Broadcast\BridgeVirtualAccountReady;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Domain\Compliance\Kyc\Services\BridgePostKycHandler;
use App\Domain\Mobile\Services\PushNotificationService;
use App\Infrastructure\Bridge\BridgeClient;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'kyc.providers.bridge.api_key'  => 'sk_test',
        'kyc.providers.bridge.base_url' => 'https://api.bridge.xyz',
    ]);
});

function makePostKycHandler(): BridgePostKycHandler
{
    return new BridgePostKycHandler(
        BridgeClient::fromConfig(),
        app(PushNotificationService::class),
    );
}

it('dispatches BridgeKycCompleted + provisions VA + dispatches BridgeVirtualAccountReady on approval', function () {
    Event::fake([
        BridgeKycCompleted::class,
        BridgeVirtualAccountReady::class,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_va_ok/virtual_accounts' => Http::response([
            'id'          => 'va_provisioned_1',
            'destination' => [
                'currency'     => 'usdc',
                'payment_rail' => 'polygon',
                'to_address'   => '0xabc',
            ],
            'source' => [
                'iban'           => 'GB29NWBK60161331926819',
                'account_holder' => 'Acme Test',
                'memo'           => 'CUSTREF-VA1',
            ],
            'supported_rails' => ['ach', 'sepa', 'sepa_instant'],
        ], 201),
    ]);

    $user = User::factory()->create();
    BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'polygon',
        'address'    => '0xabc',
        'public_key' => '0x' . str_repeat('a', 128),
    ]);

    $customer = BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_va_ok',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);

    makePostKycHandler()->handleApproved($customer);

    Event::assertDispatched(
        BridgeKycCompleted::class,
        fn (BridgeKycCompleted $e) => $e->userId === $user->id
            && $e->bridgeCustomerId === 'cust_va_ok',
    );

    Event::assertDispatched(
        BridgeVirtualAccountReady::class,
        fn (BridgeVirtualAccountReady $e) => $e->userId === $user->id
            && $e->supportedRails === ['ach', 'sepa', 'sepa_instant'],
    );

    $customer->refresh();
    expect($customer->virtual_account_id)->toBe('va_provisioned_1');
    expect($customer->supported_rails)->toBe(['ach', 'sepa', 'sepa_instant']);
    expect($customer->virtual_account_details['iban'] ?? null)->toBe('GB29NWBK60161331926819');
});

it('dispatches BridgeKycRejected + push but NOT BridgeVirtualAccountReady on rejection', function () {
    Event::fake([
        BridgeKycRejected::class,
        BridgeVirtualAccountReady::class,
    ]);

    $user = User::factory()->create();
    $customer = BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_rej_ok',
        'kyc_status'         => BridgeCustomer::KYC_REJECTED,
        'developer_fee_bps'  => 75,
    ]);

    makePostKycHandler()->handleRejected($customer, 'Document expired');

    Event::assertDispatched(
        BridgeKycRejected::class,
        fn (BridgeKycRejected $e) => $e->userId === $user->id
            && $e->reason === 'Document expired',
    );
    Event::assertNotDispatched(BridgeVirtualAccountReady::class);
});

it('defers VA provisioning when the user has no Polygon address (logs + no event)', function () {
    Event::fake([BridgeVirtualAccountReady::class]);

    $user = User::factory()->create();
    // Intentionally no blockchain_addresses row
    $customer = BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_no_addr',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);

    Http::fake();

    makePostKycHandler()->handleApproved($customer);

    Event::assertNotDispatched(BridgeVirtualAccountReady::class);
    Http::assertSentCount(0);
    expect($customer->fresh()->virtual_account_id)->toBeNull();
});

it('does not re-provision a VA if one is already present', function () {
    Event::fake([BridgeVirtualAccountReady::class, BridgeKycCompleted::class]);

    $user = User::factory()->create();
    BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'polygon',
        'address'    => '0xexisting',
        'public_key' => '0x' . str_repeat('b', 128),
    ]);
    $customer = BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_has_va',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id' => 'va_already_there',
        'developer_fee_bps'  => 75,
    ]);

    Http::fake();

    makePostKycHandler()->handleApproved($customer);

    // KYC completed event still fires; VA-ready event does not (it would
    // have already fired the first time provisioning happened).
    Event::assertDispatched(BridgeKycCompleted::class);
    Event::assertNotDispatched(BridgeVirtualAccountReady::class);
    Http::assertSentCount(0);
});

it('still dispatches BridgeKycCompleted when VA provisioning fails (logs + skips VA event)', function () {
    Event::fake([BridgeKycCompleted::class, BridgeVirtualAccountReady::class]);

    Http::fake([
        'api.bridge.xyz/v0/customers/*/virtual_accounts' => Http::response(
            ['error' => 'bridge_temporary_failure'],
            500,
        ),
    ]);

    $user = User::factory()->create();
    BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'polygon',
        'address'    => '0xfailva',
        'public_key' => '0x' . str_repeat('c', 128),
    ]);
    $customer = BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_va_fail',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => 75,
    ]);

    makePostKycHandler()->handleApproved($customer);

    Event::assertDispatched(BridgeKycCompleted::class);
    Event::assertNotDispatched(BridgeVirtualAccountReady::class);
    expect($customer->fresh()->virtual_account_id)->toBeNull();
});
