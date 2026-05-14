<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired after a quest auto-completes for a user, so mobile
 * can surface a celebration modal in real time instead of waiting for the
 * next pull-to-refresh on the rewards screen.
 *
 * Channel: private-user.{userId} (registered in routes/channels.php).
 * Broadcast name: `quest.completed`.
 */
class QuestCompleted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public static string $queue = 'events';

    public function __construct(
        public readonly int $userId,
        public readonly string $questId,
        public readonly string $questSlug,
        public readonly string $questTitle,
        public readonly int $xpEarned,
        public readonly int $pointsEarned,
        public readonly int $newLevel,
        public readonly bool $levelUp,
        public readonly string $completedAt,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'quest.completed';
    }

    /**
     * @return array{quest_id: string, quest_slug: string, quest_title: string, xp_earned: int, points_earned: int, new_level: int, level_up: bool, completed_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'quest_id'      => $this->questId,
            'quest_slug'    => $this->questSlug,
            'quest_title'   => $this->questTitle,
            'xp_earned'     => $this->xpEarned,
            'points_earned' => $this->pointsEarned,
            'new_level'     => $this->newLevel,
            'level_up'      => $this->levelUp,
            'completed_at'  => $this->completedAt,
        ];
    }
}
