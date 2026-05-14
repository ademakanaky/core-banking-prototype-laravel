<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Rewards;

use App\Domain\Rewards\Listeners\TriggerQuestOnProfileUpdated;
use App\Domain\Rewards\Models\RewardProfile;
use App\Domain\Rewards\Models\RewardQuest;
use App\Domain\Rewards\Models\RewardQuestCompletion;
use App\Domain\Rewards\Services\QuestTriggerService;
use App\Domain\User\Events\UserProfileUpdated;
use App\Domain\User\Models\UserProfile;
use App\Models\User;
use DateTimeImmutable;
use Tests\TestCase;

class TriggerQuestOnProfileUpdatedTest extends TestCase
{
    private function seedQuest(): RewardQuest
    {
        return RewardQuest::firstOrCreate(
            ['slug' => 'complete-profile'],
            [
                'slug'          => 'complete-profile',
                'title'         => 'Complete Your Profile',
                'description'   => 'Fill in all profile fields',
                'xp_reward'     => 100,
                'points_reward' => 200,
                'category'      => 'onboarding',
                'is_repeatable' => false,
                'is_active'     => true,
                'sort_order'    => 3,
            ],
        );
    }

    public function test_complete_profile_quest_fires_when_all_required_fields_are_filled(): void
    {
        $user = User::factory()->create();
        $quest = $this->seedQuest();

        UserProfile::create([
            'user_id'       => $user->id,
            'email'         => $user->email,
            'first_name'    => 'Jane',
            'last_name'     => 'Doe',
            'date_of_birth' => '1990-05-12',
            'country'       => 'LT',
            'phone_number'  => '+37060000000',
            'status'        => 'active',
        ]);

        $listener = new TriggerQuestOnProfileUpdated(app(QuestTriggerService::class));
        $listener->handle(new UserProfileUpdated(
            userId: (string) $user->id,
            updates: ['first_name' => 'Jane'],
            updatedBy: 'self',
            updatedAt: new DateTimeImmutable(),
        ));

        $profile = RewardProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        // The quest awards 100 XP which exactly meets level 1's threshold, so
        // RewardsService::completeQuest's level-up loop consumes the XP and
        // bumps the level. Assert on points + the completion ledger, which
        // aren't subject to the level-up math.
        $this->assertSame($quest->points_reward, $profile->points_balance);
        $this->assertGreaterThanOrEqual(2, $profile->level);
        $this->assertDatabaseHas('reward_quest_completions', [
            'reward_profile_id' => $profile->id,
            'quest_id'          => $quest->id,
        ]);
    }

    public function test_complete_profile_quest_does_not_fire_when_any_required_field_is_missing(): void
    {
        $user = User::factory()->create();
        $this->seedQuest();

        // phone_number deliberately null
        UserProfile::create([
            'user_id'       => $user->id,
            'email'         => $user->email,
            'first_name'    => 'Jane',
            'last_name'     => 'Doe',
            'date_of_birth' => '1990-05-12',
            'country'       => 'LT',
            'phone_number'  => null,
            'status'        => 'active',
        ]);

        $listener = new TriggerQuestOnProfileUpdated(app(QuestTriggerService::class));
        $listener->handle(new UserProfileUpdated(
            userId: (string) $user->id,
            updates: ['first_name' => 'Jane'],
            updatedBy: 'self',
            updatedAt: new DateTimeImmutable(),
        ));

        $this->assertDatabaseMissing('reward_quest_completions', [
            'reward_profile_id' => RewardProfile::where('user_id', $user->id)->value('id'),
        ]);
    }

    public function test_complete_profile_quest_is_idempotent_across_multiple_updates(): void
    {
        $user = User::factory()->create();
        $this->seedQuest();

        UserProfile::create([
            'user_id'       => $user->id,
            'email'         => $user->email,
            'first_name'    => 'Jane',
            'last_name'     => 'Doe',
            'date_of_birth' => '1990-05-12',
            'country'       => 'LT',
            'phone_number'  => '+37060000000',
            'status'        => 'active',
        ]);

        $listener = new TriggerQuestOnProfileUpdated(app(QuestTriggerService::class));
        $event = new UserProfileUpdated(
            userId: (string) $user->id,
            updates: ['first_name' => 'Jane'],
            updatedBy: 'self',
            updatedAt: new DateTimeImmutable(),
        );

        $listener->handle($event);
        $listener->handle($event); // Second invocation must not double-award.

        // Check points + completion-row count, not xp — xp can be consumed by
        // level-ups inside completeQuest, but points and the completion ledger
        // are stable proof-of-uniqueness.
        $profile = RewardProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(200, $profile->points_balance);
        $this->assertSame(
            1,
            RewardQuestCompletion::where('reward_profile_id', $profile->id)
                ->where('quest_id', RewardQuest::where('slug', 'complete-profile')->value('id'))
                ->count(),
        );
    }

    public function test_handler_no_ops_when_user_profile_row_is_absent(): void
    {
        $this->seedQuest();

        $listener = new TriggerQuestOnProfileUpdated(app(QuestTriggerService::class));
        $listener->handle(new UserProfileUpdated(
            userId: '99999999',
            updates: ['first_name' => 'Ghost'],
            updatedBy: 'self',
            updatedAt: new DateTimeImmutable(),
        ));

        $this->assertSame(0, RewardQuestCompletion::count());
    }
}
