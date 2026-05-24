<?php

/**
 * Pins down the soft-rename contract from ADR-0005 §3.6:
 * `RAMP_PROVIDER=stripe_bridge` is a deprecated alias that still resolves
 * to StripeCryptoOnrampProvider, emitting a log warning. Webhook URL path
 * /api/v1/ramp/webhook/stripe_bridge stays routable via the registry
 * alias so deployed env files don't break.
 *
 * Remove this test in v1.1 when the alias is dropped.
 */

declare(strict_types=1);

use App\Domain\Ramp\Contracts\RampProviderInterface;
use App\Domain\Ramp\Providers\StripeCryptoOnrampProvider;
use App\Domain\Ramp\Registries\RampProviderRegistry;
use Illuminate\Support\Facades\Log;

it('resolves legacy stripe_bridge config value to StripeCryptoOnrampProvider with a deprecation warning', function () {
    config(['ramp.default_provider' => 'stripe_bridge']);
    app()->forgetInstance(RampProviderInterface::class);

    Log::spy();

    $provider = app(RampProviderInterface::class);

    expect($provider)->toBeInstanceOf(StripeCryptoOnrampProvider::class);
    expect($provider->getName())->toBe('stripe_crypto_onramp');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'stripe_bridge') && str_contains($message, 'deprecated'));
});

it('registers the legacy stripe_bridge provider name in RampProviderRegistry', function () {
    $registry = app(RampProviderRegistry::class);

    expect($registry->names())->toContain('stripe_bridge');
    expect($registry->resolve('stripe_bridge'))->toBeInstanceOf(StripeCryptoOnrampProvider::class);
});

it('keeps the canonical stripe_crypto_onramp name registered alongside the legacy alias', function () {
    $registry = app(RampProviderRegistry::class);

    expect($registry->names())->toContain('stripe_crypto_onramp');
    expect($registry->resolve('stripe_crypto_onramp'))->toBeInstanceOf(StripeCryptoOnrampProvider::class);
});
