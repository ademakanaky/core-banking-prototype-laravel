<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when the user's Bridge virtual account is provisioned (post-KYC).
 * Mobile uses this to surface the "ready to receive deposits" state on
 * the Buy screen without polling. Deposit instructions themselves are
 * fetched via GET /bridge-setup-status — keeping IBAN/account_number out
 * of the WS payload (they're PII at rest, encrypted via the model cast).
 *
 * No push notification on this event per the grilling-locked decision —
 * VA provisioning is a quiet step; pushing for it creates notification
 * fatigue without changing what the user does next.
 *
 * @see docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §4.3
 */
class BridgeVirtualAccountReady implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public static string $queue = 'events';

    public function __construct(
        public readonly int $userId,
        public readonly string $bridgeCustomerId,
        /** @var list<string> */
        public readonly array $supportedRails,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'bridge.virtual_account.ready';
    }

    /** @return array{bridge_customer_id: string, supported_rails: list<string>} */
    public function broadcastWith(): array
    {
        return [
            'bridge_customer_id' => $this->bridgeCustomerId,
            'supported_rails'    => $this->supportedRails,
        ];
    }
}
