<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when Bridge.xyz reports KYC approved for the user. Mirrors the
 * private-user.{userId} convention from existing mobile broadcasts (see
 * NotificationCountUpdated). Mobile listens on the same channel + handler.
 *
 * Payload includes bridge_customer_id so mobile can correlate with the
 * /bridge-setup-status response shape without an extra round-trip.
 *
 * @see docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §4.3
 */
class BridgeKycCompleted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public static string $queue = 'events';

    public function __construct(
        public readonly int $userId,
        public readonly string $bridgeCustomerId,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'bridge.kyc.completed';
    }

    /** @return array{bridge_customer_id: string} */
    public function broadcastWith(): array
    {
        return [
            'bridge_customer_id' => $this->bridgeCustomerId,
        ];
    }
}
