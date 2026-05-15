<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit record of a mobile device being reassigned from one user to another.
 *
 * Written whenever a `device_id` flips owners — either automatically for a
 * push-only device (no biometric/passkey bound) or via an operator forcing the
 * reassignment of a credential-bound device. Lets operators trace identity flips.
 *
 * @property int $id
 * @property string $device_id
 * @property int|null $previous_user_id
 * @property int $new_user_id
 * @property bool $had_bound_credentials
 * @property string $reason
 * @property int|null $operator_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 */
class DeviceReassignmentLog extends Model
{
    public const UPDATED_AT = null;

    public const REASON_AUTO_PUSH_ONLY = 'auto_push_only';

    public const REASON_OPERATOR_FORCED = 'operator_forced';

    protected $table = 'device_reassignment_log';

    protected $fillable = [
        'device_id',
        'previous_user_id',
        'new_user_id',
        'had_bound_credentials',
        'reason',
        'operator_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'previous_user_id'      => 'integer',
        'new_user_id'           => 'integer',
        'had_bound_credentials' => 'boolean',
        'operator_id'           => 'integer',
        'created_at'            => 'datetime',
    ];
}
