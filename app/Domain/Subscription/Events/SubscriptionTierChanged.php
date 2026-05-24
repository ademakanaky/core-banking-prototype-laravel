<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a user's effective subscription tier may have changed —
 * subscription created, updated, or deleted on Stripe.
 *
 * Listeners that act on tier change should call SubscriptionProjection::for
 * to discover the *current* tier rather than trusting any payload here.
 * That keeps the event a simple "re-check me" signal and avoids the need
 * to encode every tier-transition rule (trial vs paid vs grace vs canceled)
 * into the dispatch site.
 *
 * Consumers:
 *   - Compliance/Kyc: BridgeDeveloperFeeSync (per ADR-0006, the
 *     per-customer developer_fee_bps on the Bridge customer record needs
 *     to track Pro/Free).
 *   - Future: anything else that mirrors tier into an external system.
 *
 * Not a broadcast event — internal cross-domain signal only.
 *
 * @see docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md
 */
final class SubscriptionTierChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $source,
    ) {
    }
}
