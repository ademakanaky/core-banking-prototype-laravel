<?php

declare(strict_types=1);

use App\Domain\Rewards\Models\RewardProfile;
use App\Domain\Rewards\Models\RewardQuest;
use App\Domain\Rewards\Models\RewardShopItem;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('GraphQL Rewards API', function () {
    it('returns unauthorized without authentication for rewardProfile', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ rewardProfile { xp level } }',
        ]);

        $response->assertOk();
        $data = $response->json();
        expect($data)->toHaveKey('errors');
    });

    it('queries reward profile with authentication', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '{
                    rewardProfile {
                        xp
                        level
                        xp_for_next
                        xp_progress
                        current_streak
                        longest_streak
                        points_balance
                        quests_completed
                    }
                }',
            ]);

        $response->assertOk();
        $data = $response->json('data.rewardProfile');
        expect($data)->not->toBeNull();
        expect($data['xp'])->toBe(0);
        expect($data['level'])->toBe(1);
        expect($data['xp_for_next'])->toBe(100);
        expect($data['xp_progress'])->toEqual(0.0);
        expect($data['points_balance'])->toBe(0);
        expect($data['quests_completed'])->toBe(0);
    });

    it('queries reward quests', function () {
        $user = User::factory()->create();

        RewardQuest::create([
            'slug'          => 'first-login',
            'title'         => 'First Login',
            'description'   => 'Log in for the first time',
            'xp_reward'     => 50,
            'points_reward' => 10,
            'category'      => 'onboarding',
            'icon'          => 'star',
            'is_repeatable' => false,
            'is_active'     => true,
            'sort_order'    => 1,
        ]);

        RewardQuest::create([
            'slug'          => 'daily-checkin',
            'title'         => 'Daily Check-in',
            'description'   => 'Check in daily',
            'xp_reward'     => 10,
            'points_reward' => 5,
            'category'      => 'daily',
            'icon'          => 'calendar',
            'is_repeatable' => true,
            'is_active'     => true,
            'sort_order'    => 2,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '{
                    rewardQuests {
                        id
                        slug
                        title
                        xp_reward
                        points_reward
                        category
                        is_repeatable
                        completed
                    }
                }',
            ]);

        $response->assertOk();
        $data = $response->json('data.rewardQuests');
        expect($data)->toHaveCount(2);
        expect($data[0]['slug'])->toBe('first-login');
        expect($data[0]['completed'])->toBeFalse();
    });

    it('filters reward quests by category', function () {
        $user = User::factory()->create();

        RewardQuest::create([
            'slug'          => 'first-login',
            'title'         => 'First Login',
            'description'   => 'Log in for the first time',
            'xp_reward'     => 50,
            'points_reward' => 10,
            'category'      => 'onboarding',
            'is_repeatable' => false,
            'is_active'     => true,
            'sort_order'    => 1,
        ]);

        RewardQuest::create([
            'slug'          => 'daily-checkin',
            'title'         => 'Daily Check-in',
            'description'   => 'Check in daily',
            'xp_reward'     => 10,
            'points_reward' => 5,
            'category'      => 'daily',
            'is_repeatable' => true,
            'is_active'     => true,
            'sort_order'    => 2,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($category: String) {
                        rewardQuests(category: $category) {
                            slug
                            category
                        }
                    }
                ',
                'variables' => ['category' => 'daily'],
            ]);

        $response->assertOk();
        $data = $response->json('data.rewardQuests');
        expect($data)->toHaveCount(1);
        expect($data[0]['slug'])->toBe('daily-checkin');
    });

    it('queries reward shop items', function () {
        $user = User::factory()->create();

        RewardShopItem::create([
            'slug'        => 'badge-pioneer',
            'title'       => 'Pioneer Badge',
            'description' => 'A shiny badge',
            'points_cost' => 100,
            'category'    => 'badges',
            'icon'        => 'badge',
            'stock'       => null,
            'is_active'   => true,
            'sort_order'  => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '{
                    rewardShopItems {
                        id
                        slug
                        title
                        points_cost
                        category
                        available
                        stock
                    }
                }',
            ]);

        $response->assertOk();
        $data = $response->json('data.rewardShopItems');
        expect($data)->toHaveCount(1);
        expect($data[0]['slug'])->toBe('badge-pioneer');
        expect($data[0]['available'])->toBeTrue();
    });

    it('filters reward shop items by category', function () {
        $user = User::factory()->create();

        RewardShopItem::create([
            'slug'        => 'badge-pioneer',
            'title'       => 'Pioneer Badge',
            'description' => 'A shiny badge',
            'points_cost' => 100,
            'category'    => 'badges',
            'is_active'   => true,
            'sort_order'  => 1,
        ]);

        RewardShopItem::create([
            'slug'        => 'fee-discount',
            'title'       => 'Fee Discount',
            'description' => 'Discount on fees',
            'points_cost' => 200,
            'category'    => 'perks',
            'is_active'   => true,
            'sort_order'  => 2,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($category: String) {
                        rewardShopItems(category: $category) {
                            slug
                            category
                        }
                    }
                ',
                'variables' => ['category' => 'perks'],
            ]);

        $response->assertOk();
        $data = $response->json('data.rewardShopItems');
        expect($data)->toHaveCount(1);
        expect($data[0]['slug'])->toBe('fee-discount');
    });

    it('rejects the legacy completeQuest mutation (removed from schema)', function () {
        // The mutation was removed in the rewards-lockdown PR because it let
        // any authenticated caller credit XP without proof. Completion is now
        // reachable only via domain-event listeners → QuestTriggerService.
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($quest_id: ID!) {
                        completeQuest(quest_id: $quest_id) {
                            quest_id
                        }
                    }
                ',
                'variables' => ['quest_id' => '00000000-0000-0000-0000-000000000000'],
            ]);

        $response->assertOk();
        $data = $response->json();
        expect($data)->toHaveKey('errors');
        // The mutation field no longer exists; Lighthouse may mask the
        // detailed schema error as "Internal server error" in production
        // mode, so assert on the *absence* of a successful data path rather
        // than the error text.
        expect($data['data'] ?? null)->toBeNull();
    });

    it('redeems a shop item via mutation', function () {
        $user = User::factory()->create();

        // Give user some points
        RewardProfile::create([
            'user_id'        => $user->id,
            'xp'             => 0,
            'level'          => 1,
            'current_streak' => 0,
            'longest_streak' => 0,
            'points_balance' => 500,
        ]);

        $item = RewardShopItem::create([
            'slug'        => 'badge-pioneer',
            'title'       => 'Pioneer Badge',
            'description' => 'A shiny badge',
            'points_cost' => 100,
            'category'    => 'badges',
            'stock'       => 5,
            'is_active'   => true,
            'sort_order'  => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($item_id: ID!) {
                        redeemShopItem(item_id: $item_id) {
                            redemption_id
                            item_id
                            item_title
                            points_spent
                            points_balance
                            status
                            redeemed_at
                        }
                    }
                ',
                'variables' => ['item_id' => $item->id],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.redeemShopItem');
        expect($data['item_title'])->toBe('Pioneer Badge');
        expect($data['points_spent'])->toBe(100);
        expect($data['points_balance'])->toBe(400);
        expect($data['status'])->toBe('completed');
    });

    it('returns error when redeeming with insufficient points', function () {
        $user = User::factory()->create();

        // Profile with 0 points (created automatically)

        $item = RewardShopItem::create([
            'slug'        => 'badge-pioneer',
            'title'       => 'Pioneer Badge',
            'description' => 'A shiny badge',
            'points_cost' => 100,
            'category'    => 'badges',
            'is_active'   => true,
            'sort_order'  => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query'     => 'mutation ($item_id: ID!) { redeemShopItem(item_id: $item_id) { redemption_id } }',
                'variables' => ['item_id' => $item->id],
            ]);

        $response->assertOk();
        $data = $response->json();
        expect($data)->toHaveKey('errors');
        expect($data['errors'][0]['message'])->toBe('Insufficient points balance.');
    });
});
