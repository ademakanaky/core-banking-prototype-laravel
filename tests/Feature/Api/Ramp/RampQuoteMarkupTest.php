<?php

/**
 * Tests for the Zelta markup layer added in RampService per ADR-0006:
 * Free tier pays 0.75% on top of the provider's fee; Pro pays 0%. Markup
 * is a discrete line item under quotes[].fees, with zeltaMarkupWaived +
 * zeltaMarkupTier so mobile can render and re-quote correctly.
 *
 * Driven through GET /api/v1/ramp/quotes against the mock provider so the
 * arithmetic is deterministic. The 'ramp.tier_resolver' container binding
 * is a Closure (see RampServiceProvider), so tests swap it directly
 * without mocking the final SubscriptionProjection class.
 */

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'ramp.default_provider' => 'mock',
    ]);
});

function bindTier(string $tier): void
{
    app()->bind('ramp.tier_resolver', fn () => static fn (User $u): string => $tier);
    // Rebuild the service so it picks up the new resolver closure.
    app()->forgetInstance(App\Domain\Ramp\Services\RampService::class);
}

it('returns a Free-tier markup of 0.75% as a discrete line item for unsubscribed users', function () {
    bindTier('free');

    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $response = $this->getJson('/api/v1/ramp/quotes?type=on&fiat=USD&amount=500&crypto=USDC')
        ->assertOk();

    $fees = $response->json('data.quotes.0.fees');

    expect($fees)->not->toBeNull();
    expect($fees['zeltaMarkupTier'])->toBe('free');
    expect($fees['zeltaMarkupWaived'])->toBeFalse();
    // 0.75% of 500 = 3.75
    expect($fees['zeltaMarkup'])->toBe('3.75');
    expect($fees['feeCurrency'])->toBe('USD');
});

it('waives the markup for Pro-tier users', function () {
    bindTier('pro');

    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $response = $this->getJson('/api/v1/ramp/quotes?type=on&fiat=USD&amount=500&crypto=USDC')
        ->assertOk();

    $fees = $response->json('data.quotes.0.fees');

    expect($fees['zeltaMarkupTier'])->toBe('pro');
    expect($fees['zeltaMarkupWaived'])->toBeTrue();
    expect($fees['zeltaMarkup'])->toBe('0.00');
});

it('totals providerFee + networkFee + fxSpread + zeltaMarkup correctly', function () {
    bindTier('free');

    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $response = $this->getJson('/api/v1/ramp/quotes?type=on&fiat=USD&amount=1000&crypto=USDC')
        ->assertOk();

    $fees = $response->json('data.quotes.0.fees');

    $expectedTotal = bcadd(
        bcadd(
            bcadd($fees['providerFee'], $fees['networkFee'], 2),
            $fees['fxSpread'],
            2,
        ),
        $fees['zeltaMarkup'],
        2,
    );

    expect($fees['total'])->toBe($expectedTotal);
    // 0.75% of 1000 = 7.50
    expect($fees['zeltaMarkup'])->toBe('7.50');
});

it('preserves the existing top-level provider + valid_until fields', function () {
    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $response = $this->getJson('/api/v1/ramp/quotes?type=on&fiat=USD&amount=100&crypto=USDC')
        ->assertOk();

    expect($response->json('data.provider'))->toBe('mock');
    expect($response->json('data.valid_until'))->toBeString();
});
