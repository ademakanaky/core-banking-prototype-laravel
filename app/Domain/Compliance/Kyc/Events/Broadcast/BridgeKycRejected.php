<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when Bridge.xyz reports KYC rejected. Mobile handles via the same
 * notification listener as BridgeKycCompleted, branching on event name.
 *
 * @see docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §4.3
 */
class BridgeKycRejected implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public static string $queue = 'events';

    public function __construct(
        public readonly int $userId,
        public readonly string $bridgeCustomerId,
        public readonly ?string $reason = null,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'bridge.kyc.rejected';
    }

    /** @return array{bridge_customer_id: string, reason: string|null} */
    public function broadcastWith(): array
    {
        return [
            'bridge_customer_id' => $this->bridgeCustomerId,
            'reason'             => $this->reason,
        ];
    }
}
