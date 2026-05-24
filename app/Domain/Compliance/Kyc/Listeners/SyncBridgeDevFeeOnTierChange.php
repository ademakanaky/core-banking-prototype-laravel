<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Listeners;

use App\Domain\Compliance\Kyc\Services\BridgeDeveloperFeeSync;
use App\Domain\Subscription\Events\SubscriptionTierChanged;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Listens for SubscriptionTierChanged and PATCHes the user's Bridge
 * customer developer_fee_bps per ADR-0006.
 *
 * Queued so a slow Bridge API call doesn't block the synchronous webhook
 * response. BridgeDeveloperFeeSync no-ops when desired == current, so
 * spurious dispatches (e.g. subscription.updated with no tier-affecting
 * change) cost a single DB read, not a Bridge round-trip.
 *
 * @see docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md
 */
final class SyncBridgeDevFeeOnTierChange implements ShouldQueue
{
    public string $queue = 'events';

    public function __construct(
        private readonly BridgeDeveloperFeeSync $sync,
    ) {
    }

    public function handle(SubscriptionTierChanged $event): void
    {
        try {
            $user = User::find($event->userId);
            if ($user === null) {
                return;
            }

            $this->sync->syncForUser($user);
        } catch (Throwable $e) {
            Log::error('SyncBridgeDevFeeOnTierChange: handler failed', [
                'user_id'   => $event->userId,
                'source'    => $event->source,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
