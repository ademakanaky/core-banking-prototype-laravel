<?php

/**
 * Tests for BridgeDeveloperFeeSync — the bridge_customers <-> Bridge.xyz
 * developer_fee_bps reconciliation per ADR-0006.
 *
 * Subscription tier resolution is swapped via the same `ramp.tier_resolver`
 * closure-binding pattern used by RampService tests, BUT here we override
 * SubscriptionProjection directly because BridgeDeveloperFeeSync injects
 * it directly (not via the tier_resolver closure).
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Domain\Compliance\Kyc\Services\BridgeDeveloperFeeSync;
use App\Infrastructure\Bridge\BridgeClient;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'kyc.providers.bridge.api_key'  => 'sk_test',
        'kyc.providers.bridge.base_url' => 'https://api.bridge.xyz',
    ]);
});

/**
 * Build the service with a stub tier resolver. Production wires this through
 * SubscriptionProjection via KycServiceProvider; tests pass a closure directly
 * because the projection class is final and not easily mockable.
 */
function makeFeeSync(string $tier): BridgeDeveloperFeeSync
{
    return new BridgeDeveloperFeeSync(
        BridgeClient::fromConfig(),
        static fn (User $user): string => $tier,
    );
}

it('returns null when the user has no bridge_customers row', function () {
    $user = User::factory()->create();
    Http::fake();

    expect(makeFeeSync('free')->syncForUser($user))->toBeNull();
    Http::assertSentCount(0);
});

it('no-ops when the current dev fee already matches the desired value', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_already_synced',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_FREE,
    ]);

    Http::fake();

    $result = makeFeeSync('free')->syncForUser($user);

    expect($result)->toBe(BridgeCustomer::DEV_FEE_BPS_FREE);
    Http::assertSentCount(0);
});

it('PATCHes Bridge + updates local row when upgrading Free → Pro', function () {
    $user = User::factory()->create();
    $customer = BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_upgrade',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_FREE,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_upgrade' => Http::response([
            'id'                => 'cust_upgrade',
            'developer_fee_bps' => 0,
        ], 200),
    ]);

    $result = makeFeeSync('pro')->syncForUser($user);

    expect($result)->toBe(BridgeCustomer::DEV_FEE_BPS_PRO);
    expect($customer->fresh()->developer_fee_bps)->toBe(BridgeCustomer::DEV_FEE_BPS_PRO);
    Http::assertSentCount(1);
});

it('PATCHes Bridge + updates local row when downgrading Pro → Free', function () {
    $user = User::factory()->create();
    $customer = BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_downgrade',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_PRO,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_downgrade' => Http::response([
            'id'                => 'cust_downgrade',
            'developer_fee_bps' => 75,
        ], 200),
    ]);

    $result = makeFeeSync('free')->syncForUser($user);

    expect($result)->toBe(BridgeCustomer::DEV_FEE_BPS_FREE);
    expect($customer->fresh()->developer_fee_bps)->toBe(BridgeCustomer::DEV_FEE_BPS_FREE);
});

it('keeps the local row unchanged when the Bridge PATCH fails', function () {
    $user = User::factory()->create();
    $customer = BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_fail_patch',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_FREE,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_fail_patch' => Http::response(
            ['error' => 'bridge_temporary_failure'],
            500,
        ),
    ]);

    $result = makeFeeSync('pro')->syncForUser($user);

    // Returns the *current* (unchanged) value, not the desired one
    expect($result)->toBe(BridgeCustomer::DEV_FEE_BPS_FREE);
    expect($customer->fresh()->developer_fee_bps)->toBe(BridgeCustomer::DEV_FEE_BPS_FREE);
});

it('bridge:sync-dev-fee --email shows the new dev fee on success', function () {
    $user = User::factory()->create(['email' => 'cli-test@example.com']);
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_cli_1',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_FREE,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_cli_1' => Http::response(['id' => 'cust_cli_1'], 200),
    ]);

    // Bind the service with a Pro-tier stub for the command run
    app()->instance(BridgeDeveloperFeeSync::class, makeFeeSync('pro'));

    $this->artisan('bridge:sync-dev-fee', ['--email' => 'cli-test@example.com'])
        ->expectsOutputToContain('developer_fee_bps now 0')
        ->assertSuccessful();
});

it('bridge:sync-dev-fee --email reports when no bridge_customers row exists', function () {
    $user = User::factory()->create(['email' => 'no-bridge@example.com']);

    $this->artisan('bridge:sync-dev-fee', ['--email' => 'no-bridge@example.com'])
        ->expectsOutputToContain('No bridge_customers row')
        ->assertSuccessful();
});

it('bridge:sync-dev-fee rejects mutually exclusive --email + --all', function () {
    $this->artisan('bridge:sync-dev-fee', ['--email' => 'x@y.com', '--all' => true])
        ->assertExitCode(1);
});

it('bridge:sync-dev-fee --dry-run shows the would-be change without PATCHing Bridge', function () {
    $user = User::factory()->create(['email' => 'dry-run@example.com']);
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_dry_1',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_FREE,
    ]);

    Http::fake();

    $this->artisan('bridge:sync-dev-fee', [
        '--email'   => 'dry-run@example.com',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();

    Http::assertSentCount(0);
});
