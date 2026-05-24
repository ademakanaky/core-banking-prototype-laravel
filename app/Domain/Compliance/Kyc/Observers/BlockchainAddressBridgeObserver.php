<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Observers;

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Domain\Compliance\Kyc\Services\BridgePostKycHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When a Polygon address is registered for a user whose Bridge KYC is
 * already approved but whose virtual account hasn't been provisioned
 * yet, trigger the VA creation.
 *
 * Why this exists: KYC can complete before mobile finishes wallet setup.
 * In that race, the post-KYC handler logs "deferring VA provisioning"
 * and stops. Without this observer the VA would never get created —
 * mobile would have to add an explicit retry endpoint. With it, the
 * provisioning happens transparently the moment the address shows up.
 *
 * Observer is registered separately from the existing
 * Wallet/BlockchainAddressObserver (which handles Solana → Helius sync)
 * so the two cross-domain concerns stay independent and either can be
 * disabled without affecting the other.
 *
 * @see docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §3.1
 */
final class BlockchainAddressBridgeObserver
{
    public function __construct(
        private readonly BridgePostKycHandler $postKyc,
    ) {
    }

    public function created(BlockchainAddress $address): void
    {
        if ($address->chain !== 'polygon') {
            return;
        }

        // Defer to afterCommit so the address row is definitely visible to
        // the provisioning call (which reads it back to populate Bridge's
        // destination block).
        dispatch(function () use ($address): void {
            try {
                $customer = BridgeCustomer::query()
                    ->whereHas('user', fn ($q) => $q->where('uuid', $address->user_uuid))
                    ->first();

                if ($customer === null) {
                    return;
                }

                if (! $customer->isKycApproved()) {
                    return;
                }

                if ($customer->hasVirtualAccount()) {
                    return;
                }

                $this->postKyc->tryProvisionVirtualAccount($customer);
            } catch (Throwable $e) {
                Log::error('BlockchainAddressBridgeObserver: VA retry failed', [
                    'address' => $address->address,
                    'chain'   => $address->chain,
                    'error'   => $e->getMessage(),
                ]);
            }
        })->afterCommit();
    }
}
