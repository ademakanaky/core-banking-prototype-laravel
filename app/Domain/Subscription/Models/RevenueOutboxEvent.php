<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Outbox row queued by webhook handlers; projected into revenue_events
 * by ProjectRevenueOutbox per ADR-0002.
 *
 * @property int                            $id
 * @property string                         $source_event_id
 * @property string                         $source_type
 * @property string                         $event_kind
 * @property array<string, mixed>           $payload
 * @property string                         $status   pending|delivered|failed
 * @property int                            $attempts
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property string|null                    $failed_reason
 */
final class RevenueOutboxEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_STRIPE = 'stripe';

    public const SOURCE_APPLE_IAP = 'apple_iap';

    public const SOURCE_GOOGLE_PLAY = 'google_play';

    /**
     * Stripe Crypto Onramp source type. Previously misnamed (see ADR-0005).
     * Historical rows still carry the legacy string under SOURCE_STRIPE_BRIDGE_LEGACY.
     */
    public const SOURCE_STRIPE_CRYPTO_ONRAMP = 'stripe_crypto_onramp';

    /**
     * Legacy value previously written by the misnamed StripeBridgeProvider.
     * Use SOURCE_STRIPE_CRYPTO_ONRAMP for new writes; this constant exists
     * for matching against historical rows.
     *
     * @deprecated Use SOURCE_STRIPE_CRYPTO_ONRAMP.
     */
    public const SOURCE_STRIPE_BRIDGE_LEGACY = 'stripe_bridge';

    /**
     * @deprecated Misnamed; alias of SOURCE_STRIPE_BRIDGE_LEGACY for backwards compatibility.
     */
    public const SOURCE_STRIPE_BRIDGE = self::SOURCE_STRIPE_BRIDGE_LEGACY;

    public const SOURCE_ONDATO = 'ondato';

    protected $table = 'revenue_outbox_events';

    protected $fillable = [
        'source_event_id',
        'source_type',
        'event_kind',
        'payload',
        'status',
        'attempts',
        'delivered_at',
        'failed_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'attempts'     => 'integer',
            'delivered_at' => 'datetime',
        ];
    }
}
