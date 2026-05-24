<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ramp\Clients\OnramperClient;
use App\Domain\Ramp\Contracts\RampProviderInterface;
use App\Domain\Ramp\Providers\MockRampProvider;
use App\Domain\Ramp\Providers\OnramperProvider;
use App\Domain\Ramp\Providers\StripeCryptoOnrampProvider;
use App\Domain\Ramp\Registries\RampProviderRegistry;
use App\Domain\Ramp\Services\RampService;
use App\Domain\Ramp\Services\StripeCryptoOnrampService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class RampServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/ramp.php',
            'ramp'
        );

        $this->app->singleton(OnramperClient::class, function () {
            return new OnramperClient();
        });

        $this->app->singleton(StripeCryptoOnrampService::class, function () {
            return new StripeCryptoOnrampService();
        });

        $this->app->bind(RampProviderInterface::class, function ($app) {
            $provider = (string) config('ramp.default_provider', 'mock');

            // Deprecated: accept legacy 'stripe_bridge' env value, log on resolution.
            if ($provider === StripeCryptoOnrampProvider::LEGACY_PROVIDER_NAME) {
                Log::warning('RAMP_PROVIDER=stripe_bridge is deprecated — rename to "stripe_crypto_onramp". Remove in v1.1.');
                $provider = StripeCryptoOnrampProvider::PROVIDER_NAME;
            }

            return match ($provider) {
                StripeCryptoOnrampProvider::PROVIDER_NAME => new StripeCryptoOnrampProvider($app->make(StripeCryptoOnrampService::class)),
                'onramper'                                => new OnramperProvider($app->make(OnramperClient::class)),
                default                                   => new MockRampProvider(),
            };
        });

        $this->app->bind(RampService::class, function ($app) {
            return new RampService(
                $app->make(RampProviderInterface::class)
            );
        });

        $this->app->singleton(RampProviderRegistry::class, function ($app) {
            $stripeFactory = static fn () => new StripeCryptoOnrampProvider($app->make(StripeCryptoOnrampService::class));

            return new RampProviderRegistry([
                'onramper'                                => static fn () => new OnramperProvider($app->make(OnramperClient::class)),
                StripeCryptoOnrampProvider::PROVIDER_NAME => $stripeFactory,
                // Deprecated alias — keeps the webhook URL /api/v1/ramp/webhook/stripe_bridge
                // resolving while deployed env files still carry the legacy name.
                StripeCryptoOnrampProvider::LEGACY_PROVIDER_NAME => $stripeFactory,
                'mock'                                           => static fn () => new MockRampProvider(),
            ]);
        });
    }
}
