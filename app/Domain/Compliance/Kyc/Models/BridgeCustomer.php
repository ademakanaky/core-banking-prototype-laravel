<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bridge.xyz customer record. 1:1 with users.
 *
 * Partitioned from users.kyc_status (Ondato/TrustCert) per the §7.5
 * invariant in docs/BACKEND_HANDOVER_BRIDGE_RAMP.md.
 *
 * @property int $id
 * @property int $user_id
 * @property string $bridge_customer_id
 * @property string $kyc_status
 * @property string|null $kyc_link_url
 * @property Carbon|null $kyc_link_expires_at
 * @property string|null $virtual_account_id
 * @property array<string, mixed>|null $virtual_account_details
 * @property array<int, string>|null $supported_rails
 * @property int $developer_fee_bps
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BridgeCustomer extends Model
{
    public const KYC_NOT_STARTED = 'not_started';

    public const KYC_PENDING = 'pending';

    public const KYC_APPROVED = 'approved';

    public const KYC_REJECTED = 'rejected';

    public const DEV_FEE_BPS_FREE = 75;

    public const DEV_FEE_BPS_PRO = 0;

    protected $table = 'bridge_customers';

    protected $fillable = [
        'user_id',
        'bridge_customer_id',
        'kyc_status',
        'kyc_link_url',
        'kyc_link_expires_at',
        'virtual_account_id',
        'virtual_account_details',
        'supported_rails',
        'developer_fee_bps',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kyc_link_expires_at'     => 'datetime',
            'virtual_account_details' => 'encrypted:array',
            'supported_rails'         => 'array',
            'developer_fee_bps'       => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isKycApproved(): bool
    {
        return $this->kyc_status === self::KYC_APPROVED;
    }

    public function hasVirtualAccount(): bool
    {
        return $this->virtual_account_id !== null;
    }
}
