<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Listeners;

use App\Domain\Rewards\Services\QuestTriggerService;
use App\Domain\User\Events\UserProfileUpdated;
use App\Domain\User\Models\UserProfile;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Completes the `complete-profile` quest the first time a user's profile
 * has all required fields filled in. Fires on UserProfileUpdated; reads
 * the projected UserProfile row (UsesTenantConnection) outside any
 * RewardsService transaction to avoid the multi-connection-deadlock trap
 * documented in CLAUDE.md.
 */
class TriggerQuestOnProfileUpdated implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly QuestTriggerService $triggers,
    ) {
    }

    public function handle(UserProfileUpdated $event): void
    {
        $profile = UserProfile::where('user_id', $event->userId)->first();

        if ($profile === null) {
            return;
        }

        if (! $this->isComplete($profile)) {
            return;
        }

        // Look up the User explicitly rather than through the dynamic
        // `$profile->user` accessor so PHPStan sees `User|null` instead of
        // the generic Model return type from BelongsTo.
        $user = User::find($profile->user_id);

        if ($user === null) {
            return;
        }

        $this->triggers->trigger($user, 'complete-profile');
    }

    private function isComplete(UserProfile $profile): bool
    {
        return filled($profile->first_name)
            && filled($profile->last_name)
            && $profile->date_of_birth !== null
            && filled($profile->country)
            && filled($profile->phone_number);
    }
}
