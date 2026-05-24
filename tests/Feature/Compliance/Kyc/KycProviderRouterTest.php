<?php

/**
 * Smoke test for the per-purpose KYC routing wired up in KycServiceProvider.
 * Asserts the abstraction resolves to the configured provider per purpose
 * and that the providers report the names downstream code expects.
 *
 * Adapter-specific behavior is exercised by OndatoKycProviderTest and
 * (in a future PR) the BridgeKycProvider integration tests.
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Contracts\KycProviderInterface;
use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use App\Domain\Compliance\Kyc\Providers\BridgeKycProvider;
use App\Domain\Compliance\Kyc\Providers\OndatoKycProvider;
use App\Domain\Compliance\Kyc\Registries\KycProviderRouter;

beforeEach(function () {
    config([
        'kyc.routing.trustcert' => 'ondato',
        'kyc.routing.ramp'      => 'bridge',
        'kyc.routing.cards'     => 'bridge',
    ]);
});

it('resolves TRUSTCERT to OndatoKycProvider', function () {
    $router = app(KycProviderRouter::class);
    $provider = $router->resolve(KycPurpose::TRUSTCERT);

    expect($provider)->toBeInstanceOf(OndatoKycProvider::class);
    expect($provider->getName())->toBe('ondato');
});

it('resolves RAMP to BridgeKycProvider', function () {
    $router = app(KycProviderRouter::class);
    $provider = $router->resolve(KycPurpose::RAMP);

    expect($provider)->toBeInstanceOf(BridgeKycProvider::class);
    expect($provider->getName())->toBe('bridge');
});

it('resolves CARDS to BridgeKycProvider', function () {
    $router = app(KycProviderRouter::class);
    $provider = $router->resolve(KycPurpose::CARDS);

    expect($provider)->toBeInstanceOf(BridgeKycProvider::class);
});

it('caches resolved providers (returns same instance)', function () {
    $router = app(KycProviderRouter::class);
    $first = $router->resolve(KycPurpose::RAMP);
    $second = $router->resolve(KycPurpose::RAMP);

    expect($first)->toBe($second);
});

it('throws when a purpose has no routing configured', function () {
    config(['kyc.routing.trustcert' => null]);
    // Re-bind to pick up new config
    app()->forgetInstance(KycProviderRouter::class);

    expect(fn () => app(KycProviderRouter::class)->resolve(KycPurpose::TRUSTCERT))
        ->toThrow(RuntimeException::class, 'No KYC provider routed for purpose "trustcert"');
});

it('every provider returns a stable name and signature header', function () {
    $router = app(KycProviderRouter::class);

    foreach ($router->names() as $name) {
        $provider = $router->resolveByName($name);
        expect($provider)->toBeInstanceOf(KycProviderInterface::class);
        expect($provider->getName())->toBe($name);
        $header = $provider->getWebhookSignatureHeader();
        expect($header)->toBeString();
        expect($header)->not->toBe('');
    }
});
