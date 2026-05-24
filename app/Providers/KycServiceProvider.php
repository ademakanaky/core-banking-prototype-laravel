<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Compliance\Kyc\Listeners\SyncBridgeDevFeeOnTierChange;
use App\Domain\Compliance\Kyc\Observers\BlockchainAddressBridgeObserver;
use App\Domain\Compliance\Kyc\Providers\BridgeKycProvider;
use App\Domain\Compliance\Kyc\Providers\OndatoKycProvider;
use App\Domain\Compliance\Kyc\Registries\KycProviderRouter;
use App\Domain\Compliance\Kyc\Services\BridgeDeveloperFeeSync;
use App\Domain\Compliance\Services\OndatoService;
use App\Domain\Subscription\Events\SubscriptionTierChanged;
use App\Domain\Subscription\Projections\SubscriptionProjection;
use App\Infrastructure\Bridge\BridgeClient;
use App\Infrastructure\Bridge\BridgeWebhookVerifier;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Event;
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

        $this->app->singleton(BridgeClient::class, static fn () => BridgeClient::fromConfig());
        $this->app->singleton(BridgeWebhookVerifier::class, static fn () => BridgeWebhookVerifier::fromConfig());

        // Bridge dev-fee resolver — same Closure-binding pattern as
        // RampService's tier resolver. Sidesteps the final SubscriptionProjection
        // class for testability.
        $this->app->bind('bridge.tier_resolver', function ($app): Closure {
            $projection = $app->make(SubscriptionProjection::class);

            return static fn (User $user): string => ($projection->for($user)['tier'] ?? 'free') === 'pro' ? 'pro' : 'free';
        });

        $this->app->bind(BridgeDeveloperFeeSync::class, function ($app): BridgeDeveloperFeeSync {
            return new BridgeDeveloperFeeSync(
                $app->make(BridgeClient::class),
                $app->make('bridge.tier_resolver'),
            );
        });

        $this->app->singleton(KycProviderRouter::class, function ($app) {
            return new KycProviderRouter(
                factories: [
                    'ondato' => static fn () => new OndatoKycProvider($app->make(OndatoService::class)),
                    'bridge' => static fn () => new BridgeKycProvider(
                        $app->make(BridgeClient::class),
                        $app->make(BridgeWebhookVerifier::class),
                    ),
                ],
                routing: (array) config('kyc.routing', []),
            );
        });
    }

    public function boot(): void
    {
        // Attaches BlockchainAddressBridgeObserver to the BlockchainAddress
        // model. Separate from Wallet/BlockchainAddressObserver (Helius/Solana
        // sync) so the two cross-domain concerns can be disabled independently.
        BlockchainAddress::observe(BlockchainAddressBridgeObserver::class);

        // Per ADR-0006, keep the Bridge customer's per-customer
        // developer_fee_bps in sync with the user's subscription tier
        // automatically. The Subscription webhook controller dispatches the
        // event on every tier-affecting transition; the listener no-ops
        // when desired == current.
        Event::listen(SubscriptionTierChanged::class, SyncBridgeDevFeeOnTierChange::class);
    }
}
