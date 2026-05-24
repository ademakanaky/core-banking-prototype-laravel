<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Registries;

use App\Domain\Compliance\Kyc\Contracts\KycProviderInterface;
use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use Closure;
use RuntimeException;

/**
 * Routes a KycPurpose to the configured provider per config('kyc.routing').
 *
 * Mirrors the lazy-factory pattern used by RampProviderRegistry — providers
 * are instantiated only on first resolve so a deployment that uses Ondato
 * for TRUSTCERT and Bridge for RAMP doesn't pay the cost of booting an
 * unused future provider, and missing env credentials for an unused
 * provider don't crash boot.
 *
 * Webhook routing reuses resolveByName() so an incoming Bridge webhook
 * lands on BridgeKycProvider regardless of which purpose is currently
 * configured to route to it.
 */
final class KycProviderRouter
{
    /**
     * @param  array<string, Closure(): KycProviderInterface>  $factories  Keyed by provider name (e.g. 'ondato', 'bridge').
     * @param  array<string, string>  $routing  Keyed by KycPurpose value (e.g. 'trustcert' => 'ondato').
     */
    public function __construct(
        private readonly array $factories,
        private readonly array $routing,
    ) {
    }

    /** @var array<string, KycProviderInterface> */
    private array $resolved = [];

    public function resolve(KycPurpose $purpose): KycProviderInterface
    {
        $name = $this->routing[$purpose->value] ?? null;
        if ($name === null) {
            throw new RuntimeException(sprintf(
                'No KYC provider routed for purpose "%s". Configure config/kyc.php routing.',
                $purpose->value,
            ));
        }

        return $this->resolveByName($name);
    }

    public function resolveByName(string $name): KycProviderInterface
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (! isset($this->factories[$name])) {
            throw new RuntimeException(sprintf(
                'Unknown KYC provider "%s". Registered: %s.',
                $name,
                implode(', ', array_keys($this->factories)),
            ));
        }

        return $this->resolved[$name] = ($this->factories[$name])();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->factories);
    }
}
