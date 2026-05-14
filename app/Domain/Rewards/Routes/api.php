<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Rewards\RewardsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/rewards')->name('api.rewards.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('/profile', [RewardsController::class, 'profile'])
            ->middleware('api.rate_limit:query')
            ->name('profile');

        Route::get('/quests', [RewardsController::class, 'quests'])
            ->middleware('api.rate_limit:query')
            ->name('quests');

        // Quest completion is auto-driven by domain events via
        // QuestTriggerService. The legacy POST /quests/{id}/complete endpoint
        // and its GraphQL `completeQuest` counterpart were removed because
        // they let any authenticated caller credit XP without proof the
        // underlying action actually happened. See PR removing both.

        Route::get('/shop', [RewardsController::class, 'shop'])
            ->middleware('api.rate_limit:query')
            ->name('shop');

        Route::post('/shop/{id}/redeem', [RewardsController::class, 'redeemItem'])
            ->middleware('api.rate_limit:mutation')
            ->whereUuid('id')
            ->name('shop.redeem');
    });
