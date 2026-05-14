<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Rewards;

use App\Domain\Rewards\Events\Broadcast\QuestCompleted;
use App\Domain\Rewards\Models\RewardQuest;
use App\Domain\Rewards\Services\RewardsService;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QuestCompletedBroadcastTest extends TestCase
{
    public function test_quest_completed_broadcast_fires_after_successful_completion(): void
    {
        Event::fake([QuestCompleted::class]);

        $user = User::factory()->create();
        $quest = RewardQuest::create([
            'slug'          => 'broadcast-quest',
            'title'         => 'Broadcast Quest',
            'description'   => 'Triggers the WebSocket payload',
            'xp_reward'     => 25,
            'points_reward' => 50,
            'category'      => 'test',
            'is_repeatable' => false,
            'is_active'     => true,
        ]);

        app(RewardsService::class)->completeQuest($user, $quest->id);

        Event::assertDispatched(
            QuestCompleted::class,
            function (QuestCompleted $event) use ($user, $quest): bool {
                return $event->userId === $user->id
                    && $event->questId === $quest->id
                    && $event->questSlug === 'broadcast-quest'
                    && $event->xpEarned === 25
                    && $event->pointsEarned === 50;
            },
        );
    }

    public function test_quest_completed_payload_targets_the_user_private_channel(): void
    {
        $user = User::factory()->create();

        $event = new QuestCompleted(
            userId: $user->id,
            questId: 'q-id',
            questSlug: 'first-payment',
            questTitle: 'First Payment',
            xpEarned: 50,
            pointsEarned: 100,
            newLevel: 1,
            levelUp: false,
            completedAt: '2026-05-14T22:00:00+00:00',
        );

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame("private-user.{$user->id}", $channels[0]->name);
        $this->assertSame('quest.completed', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame('q-id', $payload['quest_id']);
        $this->assertSame('first-payment', $payload['quest_slug']);
        $this->assertSame(50, $payload['xp_earned']);
        $this->assertSame(100, $payload['points_earned']);
        $this->assertFalse($payload['level_up']);
    }
}
