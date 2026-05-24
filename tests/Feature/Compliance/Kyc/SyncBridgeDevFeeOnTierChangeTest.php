<?php

/**
 * Tests the auto-trigger that closes the loop on ADR-0006: when the
 * SubscriptionWebhookController dispatches SubscriptionTierChanged after
 * a Stripe lifecycle event, the listener calls BridgeDeveloperFeeSync,
 * which PATCHes Bridge if the desired dev_fee_bps differs from the cached
 * value.
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Listeners\SyncBridgeDevFeeOnTierChange;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Domain\Compliance\Kyc\Services\BridgeDeveloperFeeSync;
use App\Domain\Subscription\Events\SubscriptionTierChanged;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'kyc.providers.bridge.api_key'  => 'sk_test',
        'kyc.providers.bridge.base_url' => 'https://api.bridge.xyz',
    ]);
});

/** Bind a stub sync that captures the call rather than hitting Bridge. */
function fakeFeeSync(string $tier): BridgeDeveloperFeeSync
{
    return new BridgeDeveloperFeeSync(
        App\Infrastructure\Bridge\BridgeClient::fromConfig(),
        static fn (User $user): string => $tier,
    );
}

it('PATCHes Bridge with Pro fee (0 bps) when listener fires for a Free → Pro user', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_tier_change_1',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_FREE,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_tier_change_1' => Http::response(
            ['id' => 'cust_tier_change_1', 'developer_fee_bps' => 0],
            200,
        ),
    ]);

    app()->instance(BridgeDeveloperFeeSync::class, fakeFeeSync('pro'));

    // Invoke the listener synchronously (queueing is bypassed by Pest)
    $listener = app(SyncBridgeDevFeeOnTierChange::class);
    $listener->handle(new SubscriptionTierChanged($user->id, 'stripe.subscription.created'));

    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v0/customers/cust_tier_change_1'));
    expect(BridgeCustomer::where('user_id', $user->id)->value('developer_fee_bps'))
        ->toBe(BridgeCustomer::DEV_FEE_BPS_PRO);
});

it('PATCHes Bridge back to Free (75 bps) when listener fires for a Pro → Free downgrade', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_downgrade_listener',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_PRO,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_downgrade_listener' => Http::response(
            ['id' => 'cust_downgrade_listener', 'developer_fee_bps' => 75],
            200,
        ),
    ]);

    app()->instance(BridgeDeveloperFeeSync::class, fakeFeeSync('free'));

    $listener = app(SyncBridgeDevFeeOnTierChange::class);
    $listener->handle(new SubscriptionTierChanged($user->id, 'stripe.subscription.deleted'));

    expect(BridgeCustomer::where('user_id', $user->id)->value('developer_fee_bps'))
        ->toBe(BridgeCustomer::DEV_FEE_BPS_FREE);
});

it('no-ops when the user has no bridge_customers row (e.g. tier change before KYC)', function () {
    $user = User::factory()->create();

    Http::fake();
    app()->instance(BridgeDeveloperFeeSync::class, fakeFeeSync('pro'));

    $listener = app(SyncBridgeDevFeeOnTierChange::class);
    $listener->handle(new SubscriptionTierChanged($user->id, 'stripe.subscription.created'));

    Http::assertSentCount(0);
});

it('no-ops when the desired fee already matches the cached value (idempotent)', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_same_tier',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_FREE,
    ]);

    Http::fake();
    app()->instance(BridgeDeveloperFeeSync::class, fakeFeeSync('free'));

    $listener = app(SyncBridgeDevFeeOnTierChange::class);
    $listener->handle(new SubscriptionTierChanged($user->id, 'stripe.subscription.updated'));

    Http::assertSentCount(0);
});

it('swallows handler exceptions and logs (Bridge being down does not break the webhook)', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_bridge_down',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'developer_fee_bps'  => BridgeCustomer::DEV_FEE_BPS_FREE,
    ]);

    Http::fake([
        'api.bridge.xyz/v0/customers/cust_bridge_down' => Http::response(
            ['error' => 'bridge_down'],
            503,
        ),
    ]);

    app()->instance(BridgeDeveloperFeeSync::class, fakeFeeSync('pro'));

    $listener = app(SyncBridgeDevFeeOnTierChange::class);
    // Should not throw — BridgeDeveloperFeeSync swallows + the listener
    // wraps everything in a try/catch
    $listener->handle(new SubscriptionTierChanged($user->id, 'stripe.subscription.updated'));

    // Local row unchanged because the PATCH failed
    expect(BridgeCustomer::where('user_id', $user->id)->value('developer_fee_bps'))
        ->toBe(BridgeCustomer::DEV_FEE_BPS_FREE);
});

it('listener is wired to SubscriptionTierChanged via KycServiceProvider', function () {
    Event::fake([SubscriptionTierChanged::class]);

    // No-op — just verifies the listener registration doesn't blow up on boot
    expect(Event::hasListeners(SubscriptionTierChanged::class))->toBeTrue();
});
