<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Services;

use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Infrastructure\Bridge\BridgeClient;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps each Bridge customer's per-customer `developer_fee_bps` in sync
 * with the user's current subscription tier per ADR-0006.
 *
 * Why per-customer (not per-transfer): unsolicited virtual-account
 * deposits are initiated by Bridge, not us, so we can't set the
 * per-transfer dev fee at that moment. The customer-level default is the
 * source of truth for that path.
 *
 * Currently invoked via the `bridge:sync-dev-fee` Artisan command for
 * one-off corrections / batch backfill. The eventual auto-trigger lives
 * downstream of a `SubscriptionTierChanged` event the Subscription domain
 * has not yet introduced; once it does, a listener under this domain will
 * call this service.
 *
 * @see docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md
 */
final class BridgeDeveloperFeeSync
{
    /**
     * @param  Closure(User): string  $tierResolver  Returns 'pro' or 'free'. Bound via
     *                                               KycServiceProvider against SubscriptionProjection
     *                                               in production; tests inject a stub closure
     *                                               directly to sidestep the final class.
     */
    public function __construct(
        private readonly BridgeClient $client,
        private readonly Closure $tierResolver,
    ) {
    }

    /**
     * Resolve the desired developer_fee_bps for the user, PATCH Bridge if
     * different from the local cache, and update the local row. Returns
     * the new effective value (or null if no Bridge customer exists yet).
     */
    public function syncForUser(User $user): ?int
    {
        $customer = BridgeCustomer::where('user_id', $user->id)->first();
        if ($customer === null) {
            return null;
        }

        $desired = $this->desiredFeeBpsFor($user);

        if ($customer->developer_fee_bps === $desired) {
            return $desired;
        }

        try {
            $this->client->patchCustomer(
                $customer->bridge_customer_id,
                ['developer_fee_bps' => $desired],
            );
        } catch (Throwable $e) {
            Log::error('BridgeDeveloperFeeSync: PATCH failed', [
                'user_id'   => $user->id,
                'desired'   => $desired,
                'previous'  => $customer->developer_fee_bps,
                'exception' => $e->getMessage(),
            ]);

            return $customer->developer_fee_bps;
        }

        $customer->update(['developer_fee_bps' => $desired]);

        Log::info('BridgeDeveloperFeeSync: dev fee updated', [
            'user_id'  => $user->id,
            'previous' => $customer->getOriginal('developer_fee_bps'),
            'current'  => $desired,
        ]);

        return $desired;
    }

    private function desiredFeeBpsFor(User $user): int
    {
        $tier = ($this->tierResolver)($user);

        return $tier === 'pro' ? BridgeCustomer::DEV_FEE_BPS_PRO : BridgeCustomer::DEV_FEE_BPS_FREE;
    }
}
