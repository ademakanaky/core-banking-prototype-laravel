<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Enums;

/**
 * The user-facing reason a KYC ceremony is being run. The KycProviderRouter
 * maps each purpose to a concrete provider via config('kyc.routing.<purpose>').
 *
 * Per docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §7.5, purposes are partitioned:
 * a user's KYC approval for one purpose does not automatically extend to any
 * other. TRUSTCERT (Ondato) and RAMP (Bridge) carry separate state, separate
 * tables, and separate lifecycle events.
 */
enum KycPurpose: string
{
    case TRUSTCERT = 'trustcert';
    case RAMP = 'ramp';
    case CARDS = 'cards';
}
