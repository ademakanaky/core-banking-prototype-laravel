<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Compliance\Kyc\Providers\BridgeKycProvider;
use App\Domain\Compliance\Kyc\Providers\OndatoKycProvider;
use App\Domain\Compliance\Kyc\Registries\KycProviderRouter;
use App\Domain\Compliance\Services\OndatoService;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the KYC abstraction:
 *  - merges config/kyc.php under the 'kyc' key
 *  - builds the KycProviderRouter with lazy factories for each provider
 *  - per-purpose routing comes from config('kyc.routing'); the router
 *    resolves a KycPurpose to a provider name and instantiates lazily so a
 *    deployment missing Bridge credentials can still boot if Bridge is not
 *    the routed provider for any in-use purpose.
 *
 * @see config/kyc.php
 * @see app/Domain/Compliance/Kyc/Registries/KycProviderRouter.php
 */
class KycServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/kyc.php',
            'kyc'
        );

        $this->app->singleton(KycProviderRouter::class, function ($app) {
            return new KycProviderRouter(
                factories: [
                    'ondato' => static fn () => new OndatoKycProvider($app->make(OndatoService::class)),
                    'bridge' => static fn () => new BridgeKycProvider(),
                ],
                routing: (array) config('kyc.routing', []),
            );
        });
    }
}
