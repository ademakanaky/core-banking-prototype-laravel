<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Observers;

use App\Domain\MobilePayment\Enums\ActivityItemType;
use App\Domain\MobilePayment\Models\ActivityFeedItem;
use App\Domain\Wallet\Models\WalletSendRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects the WalletSendRecord lifecycle into the activity_feed_items read
 * model so outbound sends are visible in GET /api/v1/wallet/transactions while
 * they are still pending — and after they fail — not only once confirmed
 * on-chain.
 *
 * Before this observer, an outbound send only reached the feed when the Helius
 * confirmation webhook fired (always as `confirmed`); pending and failed sends
 * were invisible to the user. Inbound transfers are projected separately by
 * {@see \App\Domain\Wallet\Services\HeliusTransactionProcessor}.
 *
 * The activity feed is a non-critical read model: a projection failure must
 * never roll back or break an actual wallet send, so the write is best-effort
 * and logged on failure. Correctness is covered by WalletSendRecordObserverTest.
 */
class WalletSendRecordObserver
{
    public function created(WalletSendRecord $record): void
    {
        $this->project($record);
    }

    public function updated(WalletSendRecord $record): void
    {
        // Only re-project on the fields the feed actually reflects — avoids a
        // redundant write when an unrelated column changes.
        if (! $record->wasChanged(['status', 'tx_hash'])) {
            return;
        }

        $this->project($record);
    }

    private function project(WalletSendRecord $record): void
    {
        try {
            ActivityFeedItem::updateOrCreate(
                [
                    'reference_type' => WalletSendRecord::class,
                    'reference_id'   => $record->id,
                ],
                [
                    'user_id'       => $record->user_id,
                    'activity_type' => ActivityItemType::TRANSFER_OUT,
                    'amount'        => '-' . $record->amount,
                    'asset'         => $record->asset,
                    'network'       => $record->network,
                    'status'        => $record->status,
                    'protected'     => false,
                    'from_address'  => $record->sender_address,
                    'to_address'    => $record->recipient_address,
                    // Pinned to creation time so a status update never reorders
                    // the item in the cursor-paginated feed.
                    'occurred_at' => $record->created_at ?? now(),
                    'metadata'    => [
                        'intent_id' => $record->public_id,
                        'tx_hash'   => $record->tx_hash,
                    ],
                ],
            );
        } catch (Throwable $e) {
            Log::warning('WalletSendRecordObserver: activity feed projection failed', [
                'record_id' => $record->id,
                'public_id' => $record->public_id,
                'status'    => $record->status,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
