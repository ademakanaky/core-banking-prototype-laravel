<?php

declare(strict_types=1);

namespace App\Domain\MobilePayment\Services;

use App\Domain\MobilePayment\Enums\ActivityItemType;
use App\Domain\MobilePayment\Models\ActivityFeedItem;
use App\Domain\MobilePayment\Models\PaymentIntent;
use App\Domain\MobilePayment\Models\PaymentReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Service for generating and retrieving payment receipts.
 *
 * Receipts are generated on-demand for confirmed transactions and cached in
 * Redis for the configured TTL (default 24h). The lookup is keyed on the
 * `ActivityFeedItem` because that is the read model mobile sees in the
 * list/detail endpoints — `{txId}` on the wire is always
 * `ActivityFeedItem.id`. When the activity row references a
 * `PaymentIntent` (merchant payments) we resolve merchant + fee details
 * from the intent; otherwise (e.g. Solana inbound transfers written by
 * `HeliusTransactionProcessor`) we build the receipt directly from the
 * activity row + Helius metadata.
 */
class ReceiptService
{
    /**
     * Generate a receipt for a confirmed transaction.
     *
     * Returns an existing receipt if one was already generated for this tx.
     */
    public function generateReceipt(string $txId, int $userId): ?PaymentReceipt
    {
        $item = ActivityFeedItem::where('id', $txId)
            ->where('user_id', $userId)
            ->first();

        if (! $item) {
            return null;
        }

        if ($item->status !== 'confirmed') {
            return null;
        }

        $intent = $this->resolveIntent($item);

        if ($intent !== null) {
            return $this->buildReceiptFromIntent($intent, $item, $userId);
        }

        return $this->buildReceiptFromActivity($item, $userId);
    }

    /**
     * Get a receipt by its public ID.
     */
    public function getReceipt(string $receiptId, int $userId): ?PaymentReceipt
    {
        return PaymentReceipt::where('public_id', $receiptId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Get a receipt by its share token (public, no auth required).
     */
    public function getReceiptByShareToken(string $shareToken): ?PaymentReceipt
    {
        return PaymentReceipt::where('share_token', $shareToken)->first();
    }

    /**
     * Resolve the originating PaymentIntent from an activity feed row, if any.
     */
    private function resolveIntent(ActivityFeedItem $item): ?PaymentIntent
    {
        if ($item->reference_type !== PaymentIntent::class || $item->reference_id === null) {
            return null;
        }

        return PaymentIntent::find($item->reference_id);
    }

    /**
     * Build (or return existing) receipt for a PaymentIntent-backed activity.
     *
     * Preserves the legacy merchant + fee resolution path for merchant payments.
     */
    private function buildReceiptFromIntent(
        PaymentIntent $intent,
        ActivityFeedItem $item,
        int $userId,
    ): PaymentReceipt {
        // Use firstOrCreate to prevent TOCTOU race condition on duplicate receipts.
        $receipt = PaymentReceipt::firstOrCreate(
            [
                'payment_intent_id' => $intent->id,
                'user_id'           => $userId,
            ],
            [
                'public_id'      => 'rcpt_' . Str::random(24),
                'merchant_name'  => $item->merchant_name ?? ($intent->merchant->display_name ?? 'Unknown Merchant'),
                'amount'         => $intent->amount,
                'asset'          => $intent->asset,
                'network'        => $intent->network,
                'tx_hash'        => $intent->tx_hash,
                'network_fee'    => $this->formatNetworkFee($intent),
                'share_token'    => Str::random(64),
                'transaction_at' => $intent->confirmed_at ?? $intent->created_at,
            ],
        );

        $this->cacheIfNew($receipt);

        return $receipt;
    }

    /**
     * Build (or return existing) receipt for an activity row with no PaymentIntent.
     *
     * Covers Solana inbound transfers persisted by `HeliusTransactionProcessor`
     * and any other non-intent flows. Uniqueness is keyed on
     * (user_id, tx_hash) when tx_hash is available, since `payment_intent_id`
     * is null and is therefore not a safe firstOrCreate key.
     */
    private function buildReceiptFromActivity(ActivityFeedItem $item, int $userId): PaymentReceipt
    {
        $metadata = $item->metadata ?? [];
        $txHash = $metadata['tx_hash'] ?? $metadata['signature'] ?? null;

        if (is_string($txHash) && $txHash !== '') {
            $existing = PaymentReceipt::where('user_id', $userId)
                ->where('tx_hash', $txHash)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $receipt = PaymentReceipt::create([
            'public_id'      => 'rcpt_' . Str::random(24),
            'user_id'        => $userId,
            'merchant_name'  => $this->resolveMerchantName($item),
            'amount'         => $item->amount,
            'asset'          => $item->asset,
            'network'        => $item->network ?? '',
            'tx_hash'        => is_string($txHash) && $txHash !== '' ? $txHash : null,
            'network_fee'    => $this->formatNetworkFeeFromMetadata($metadata),
            'share_token'    => Str::random(64),
            'transaction_at' => $item->occurred_at,
        ]);

        $this->cacheIfNew($receipt);

        return $receipt;
    }

    /**
     * Synthesize a display label when the activity row has no merchant_name.
     */
    private function resolveMerchantName(ActivityFeedItem $item): string
    {
        if (is_string($item->merchant_name) && $item->merchant_name !== '') {
            return $item->merchant_name;
        }

        return match ($item->activity_type) {
            ActivityItemType::TRANSFER_IN, ActivityItemType::UNSHIELD => 'Received',
            ActivityItemType::TRANSFER_OUT, ActivityItemType::SHIELD  => 'Sent',
            ActivityItemType::MERCHANT_PAYMENT                        => 'Payment',
        };
    }

    /**
     * Cache the receipt's API representation if it was just persisted.
     */
    private function cacheIfNew(PaymentReceipt $receipt): void
    {
        if ($receipt->wasRecentlyCreated) {
            $cacheHours = (int) config('mobile_payment.receipt_cache_hours', 24);
            Cache::put(
                "receipt:{$receipt->public_id}",
                $receipt->toApiResponse(),
                now()->addHours($cacheHours)
            );
        }
    }

    /**
     * Format the network fee from the payment intent's fee estimate.
     */
    private function formatNetworkFee(PaymentIntent $intent): string
    {
        $fees = $intent->fees_estimate;

        if (! $fees || ! isset($fees['usdApprox'])) {
            return '0.01 USD';
        }

        return $fees['usdApprox'] . ' USD';
    }

    /**
     * Format the network fee from raw activity metadata (Helius writes `fee_usd`).
     *
     * @param array<string, mixed> $metadata
     */
    private function formatNetworkFeeFromMetadata(array $metadata): string
    {
        $feeUsd = $metadata['fee_usd'] ?? null;

        if (is_string($feeUsd) || is_int($feeUsd) || is_float($feeUsd)) {
            $value = (string) $feeUsd;
            if ($value !== '') {
                return $value . ' USD';
            }
        }

        return '0.00 USD';
    }
}
