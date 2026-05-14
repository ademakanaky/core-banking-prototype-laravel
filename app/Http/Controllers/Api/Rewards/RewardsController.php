<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Rewards;

use App\Domain\Rewards\Services\RewardsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use RuntimeException;

class RewardsController extends Controller
{
    public function __construct(
        private readonly RewardsService $rewardsService,
    ) {
    }

    /**
     * Get the user's rewards profile.
     */
    #[OA\Get(
        path: '/api/v1/rewards/profile',
        operationId: 'getRewardsProfile',
        summary: 'Get rewards profile',
        description: 'Returns the user\'s XP, level, streak, and points balance.',
        security: [['sanctum' => []]],
        tags: ['Rewards'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Rewards profile',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'xp', type: 'integer', example: 250),
                        new OA\Property(property: 'level', type: 'integer', example: 3),
                        new OA\Property(property: 'xp_for_next', type: 'integer', example: 300),
                        new OA\Property(property: 'xp_progress', type: 'number', example: 0.83),
                        new OA\Property(property: 'current_streak', type: 'integer', example: 5),
                        new OA\Property(property: 'longest_streak', type: 'integer', example: 12),
                        new OA\Property(property: 'points_balance', type: 'integer', example: 1500),
                        new OA\Property(property: 'quests_completed', type: 'integer', example: 8),
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function profile(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => $this->rewardsService->getProfileData($user),
        ]);
    }

    /**
     * Get available quests.
     */
    #[OA\Get(
        path: '/api/v1/rewards/quests',
        operationId: 'getRewardsQuests',
        summary: 'Get available quests',
        description: 'Returns active quests with the user\'s completion status.',
        security: [['sanctum' => []]],
        tags: ['Rewards'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Quests list',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'slug', type: 'string', example: 'first-shield'),
                            new OA\Property(property: 'title', type: 'string', example: 'First Shield'),
                            new OA\Property(property: 'description', type: 'string'),
                            new OA\Property(property: 'xp_reward', type: 'integer', example: 50),
                            new OA\Property(property: 'points_reward', type: 'integer', example: 100),
                            new OA\Property(property: 'category', type: 'string', example: 'onboarding'),
                            new OA\Property(property: 'completed', type: 'boolean', example: false),
                        ],
                    ),
                ),
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function quests(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => $this->rewardsService->getQuests($user),
        ]);
    }

    /**
     * Get shop items.
     */
    #[OA\Get(
        path: '/api/v1/rewards/shop',
        operationId: 'getRewardsShop',
        summary: 'Get rewards shop items',
        description: 'Returns active shop items available for point redemption.',
        security: [['sanctum' => []]],
        tags: ['Rewards'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Shop items list',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'points_cost', type: 'integer'),
                            new OA\Property(property: 'available', type: 'boolean'),
                        ],
                    ),
                ),
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function shop(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->rewardsService->getShopItems(),
        ]);
    }

    /**
     * Redeem a shop item.
     */
    #[OA\Post(
        path: '/api/v1/rewards/shop/{id}/redeem',
        operationId: 'redeemRewardsShopItem',
        summary: 'Redeem a shop item',
        description: 'Spends points to redeem a shop item.',
        security: [['sanctum' => []]],
        tags: ['Rewards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
    )]
    #[OA\Response(
        response: 200,
        description: 'Item redeemed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'redemption_id', type: 'string'),
                        new OA\Property(property: 'points_spent', type: 'integer'),
                        new OA\Property(property: 'points_balance', type: 'integer'),
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 422, description: 'Insufficient points or item unavailable')]
    public function redeemItem(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        try {
            $result = $this->rewardsService->redeemItem($user, $id);

            return response()->json([
                'success' => true,
                'data'    => $result,
            ]);
        } catch (RuntimeException $e) {
            $code = match (true) {
                str_contains($e->getMessage(), 'not found')    => 'ITEM_NOT_FOUND',
                str_contains($e->getMessage(), 'out of stock') => 'ITEM_OUT_OF_STOCK',
                str_contains($e->getMessage(), 'Insufficient') => 'INSUFFICIENT_POINTS',
                default                                        => 'REDEMPTION_ERROR',
            };

            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => $code,
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
