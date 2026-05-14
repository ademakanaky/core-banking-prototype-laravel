<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Rewards;

use App\Domain\Rewards\Models\RewardProfile;
use App\Domain\Rewards\Models\RewardQuest;
use App\Domain\Rewards\Models\RewardShopItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RewardsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);
    }

    public function test_get_profile_creates_default_profile(): void
    {
        $response = $this->getJson('/api/v1/rewards/profile');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'xp',
                    'level',
                    'xp_for_next',
                    'xp_progress',
                    'current_streak',
                    'longest_streak',
                    'points_balance',
                    'quests_completed',
                ],
            ])
            ->assertJsonPath('data.xp', 0)
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.points_balance', 0);
    }

    public function test_get_profile_returns_existing_profile(): void
    {
        RewardProfile::create([
            'user_id'        => $this->user->id,
            'xp'             => 150,
            'level'          => 2,
            'current_streak' => 3,
            'longest_streak' => 7,
            'points_balance' => 500,
        ]);

        $response = $this->getJson('/api/v1/rewards/profile');

        $response->assertOk()
            ->assertJsonPath('data.xp', 150)
            ->assertJsonPath('data.level', 2)
            ->assertJsonPath('data.points_balance', 500);
    }

    public function test_get_quests_returns_active_quests(): void
    {
        RewardQuest::create([
            'slug'          => 'first-shield',
            'title'         => 'First Shield',
            'description'   => 'Shield tokens for the first time',
            'xp_reward'     => 50,
            'points_reward' => 100,
            'category'      => 'onboarding',
            'is_active'     => true,
            'sort_order'    => 1,
        ]);

        RewardQuest::create([
            'slug'          => 'inactive-quest',
            'title'         => 'Inactive',
            'description'   => 'Should not appear',
            'xp_reward'     => 10,
            'points_reward' => 10,
            'is_active'     => false,
            'sort_order'    => 2,
        ]);

        $response = $this->getJson('/api/v1/rewards/quests');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'first-shield')
            ->assertJsonPath('data.0.completed', false);
    }

    public function test_complete_quest_endpoint_is_removed(): void
    {
        // Quest completion was deliberately moved off the public API because the
        // legacy endpoint let any authenticated caller credit themselves XP
        // without proof the underlying action happened. Completion is now only
        // reachable via domain-event-driven listeners → QuestTriggerService.
        $quest = RewardQuest::create([
            'slug'          => 'first-payment',
            'title'         => 'First Payment',
            'description'   => 'Make your first payment',
            'xp_reward'     => 50,
            'points_reward' => 100,
            'category'      => 'onboarding',
            'is_active'     => true,
        ]);

        $this->postJson("/api/v1/rewards/quests/{$quest->id}/complete")
            ->assertNotFound();

        $this->assertDatabaseMissing('reward_quest_completions', [
            'quest_id' => $quest->id,
        ]);
    }

    public function test_get_shop_items(): void
    {
        RewardShopItem::create([
            'slug'        => 'fee-waiver',
            'title'       => 'Fee Waiver',
            'description' => 'Waive one transaction fee',
            'points_cost' => 500,
            'category'    => 'perks',
            'is_active'   => true,
            'sort_order'  => 1,
        ]);

        $response = $this->getJson('/api/v1/rewards/shop');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'fee-waiver')
            ->assertJsonPath('data.0.points_cost', 500);
    }

    public function test_redeem_shop_item(): void
    {
        RewardProfile::create([
            'user_id'        => $this->user->id,
            'xp'             => 0,
            'level'          => 1,
            'points_balance' => 1000,
        ]);

        $item = RewardShopItem::create([
            'slug'        => 'badge',
            'title'       => 'Gold Badge',
            'description' => 'A shiny gold badge',
            'points_cost' => 300,
            'is_active'   => true,
        ]);

        $response = $this->postJson("/api/v1/rewards/shop/{$item->id}/redeem");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.points_spent', 300)
            ->assertJsonPath('data.points_balance', 700);

        $this->assertDatabaseHas('reward_profiles', [
            'user_id'        => $this->user->id,
            'points_balance' => 700,
        ]);
    }

    public function test_redeem_insufficient_points(): void
    {
        RewardProfile::create([
            'user_id'        => $this->user->id,
            'points_balance' => 100,
        ]);

        $item = RewardShopItem::create([
            'slug'        => 'expensive',
            'title'       => 'Expensive Item',
            'description' => 'Costs a lot',
            'points_cost' => 5000,
            'is_active'   => true,
        ]);

        $response = $this->postJson("/api/v1/rewards/shop/{$item->id}/redeem");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_POINTS');
    }

    public function test_redeem_out_of_stock_item(): void
    {
        RewardProfile::create([
            'user_id'        => $this->user->id,
            'points_balance' => 1000,
        ]);

        $item = RewardShopItem::create([
            'slug'        => 'limited',
            'title'       => 'Limited Item',
            'description' => 'Very limited',
            'points_cost' => 100,
            'stock'       => 0,
            'is_active'   => true,
        ]);

        $response = $this->postJson("/api/v1/rewards/shop/{$item->id}/redeem");

        $response->assertStatus(422)
            ->assertJsonPath('error.message', 'Shop item is out of stock.');
    }

    public function test_profile_requires_auth(): void
    {
        // Use a fresh HTTP client without authentication
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/v1/rewards/profile');
        $response->assertUnauthorized();
    }

    public function test_redeem_inactive_item_returns_422(): void
    {
        RewardProfile::create([
            'user_id'        => $this->user->id,
            'points_balance' => 1000,
        ]);

        $item = RewardShopItem::create([
            'slug'        => 'inactive-item',
            'title'       => 'Inactive Item',
            'description' => 'Should not be redeemable',
            'points_cost' => 100,
            'is_active'   => false,
        ]);

        $response = $this->postJson("/api/v1/rewards/shop/{$item->id}/redeem");
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_NOT_FOUND');
    }

    public function test_redeem_nonexistent_item_returns_422(): void
    {
        RewardProfile::create([
            'user_id'        => $this->user->id,
            'points_balance' => 1000,
        ]);

        $fakeUuid = '00000000-0000-0000-0000-000000000099';
        $response = $this->postJson("/api/v1/rewards/shop/{$fakeUuid}/redeem");
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_NOT_FOUND');
    }
}
