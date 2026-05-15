<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use App\Domain\Mobile\Exceptions\DeviceTakeoverAttemptException;
use App\Domain\Mobile\Models\DeviceReassignmentLog;
use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Service for managing mobile device registration and lifecycle.
 */
class MobileDeviceService
{
    /**
     * Maximum number of devices per user.
     */
    private const MAX_DEVICES_PER_USER = 5;

    /**
     * Register a new mobile device for a user.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function registerDevice(
        User $user,
        string $deviceId,
        string $platform,
        string $appVersion,
        ?string $pushToken = null,
        ?string $deviceName = null,
        ?string $deviceModel = null,
        ?string $osVersion = null,
        ?array $metadata = null
    ): MobileDevice {
        $existingDevice = MobileDevice::where('device_id', $deviceId)->first();

        if ($existingDevice !== null && $existingDevice->user_id === $user->id) {
            // Device exists for the same user - update it
            return $this->updateDevice($existingDevice, [
                'platform'       => $platform,
                'push_token'     => $pushToken,
                'device_name'    => $deviceName,
                'device_model'   => $deviceModel,
                'os_version'     => $osVersion,
                'app_version'    => $appVersion,
                'metadata'       => $metadata,
                'last_active_at' => now(),
            ]);
        }

        if ($existingDevice !== null) {
            // The device_id is registered to a DIFFERENT user.
            if ($this->hasBoundCredentials($existingDevice)) {
                // The device holds biometric/passkey material bound to the
                // previous owner — this is the genuine takeover threat the
                // guard was added for (#348). Reject it.
                Log::critical('Device takeover attempt blocked', [
                    'device_id'         => $deviceId,
                    'existing_user_id'  => $existingDevice->user_id,
                    'attempted_user_id' => $user->id,
                    'ip_address'        => request()->ip(),
                    'user_agent'        => request()->userAgent(),
                ]);

                throw new DeviceTakeoverAttemptException(
                    deviceId: $deviceId,
                    existingUserId: $existingDevice->user_id,
                    attemptedUserId: $user->id,
                );
            }

            // Push-only device: no biometric/passkey credentials bound. There
            // is no security material to take over — the previous owner only
            // loses an FCM/APNS routing key, which is inert without our
            // app-server credentials. Reassign it so re-Privy / reinstall /
            // shared-tablet flows don't permanently lock out the new owner.
            return DB::transaction(function () use (
                $existingDevice,
                $user,
                $deviceId,
                $platform,
                $appVersion,
                $pushToken,
                $deviceName,
                $deviceModel,
                $osVersion,
                $metadata
            ): MobileDevice {
                $this->logReassignment(
                    $existingDevice,
                    $user,
                    DeviceReassignmentLog::REASON_AUTO_PUSH_ONLY,
                    hadBoundCredentials: false,
                    operatorId: null,
                );
                $existingDevice->delete();

                return $this->createDevice(
                    $user,
                    $deviceId,
                    $platform,
                    $appVersion,
                    $pushToken,
                    $deviceName,
                    $deviceModel,
                    $osVersion,
                    $metadata,
                );
            });
        }

        return $this->createDevice(
            $user,
            $deviceId,
            $platform,
            $appVersion,
            $pushToken,
            $deviceName,
            $deviceModel,
            $osVersion,
            $metadata,
        );
    }

    /**
     * Force-reassign a device to a new user, even when it has bound credentials.
     *
     * Operator-only recovery path for cases the automatic guard still blocks
     * (a credential-bound device that legitimately changed hands). The fresh
     * row starts with NO push token and NO biometric/passkey bindings — the
     * previous owner's credentials must not carry over.
     */
    public function forceReassignDevice(string $deviceId, User $newUser, User $operator): MobileDevice
    {
        $existingDevice = MobileDevice::where('device_id', $deviceId)->first();

        if ($existingDevice === null) {
            throw new InvalidArgumentException("No device registered with device_id {$deviceId}.");
        }

        if ($existingDevice->user_id === $newUser->id) {
            throw new InvalidArgumentException("Device {$deviceId} is already registered to user {$newUser->id}.");
        }

        return DB::transaction(function () use ($existingDevice, $newUser, $operator, $deviceId): MobileDevice {
            $this->logReassignment(
                $existingDevice,
                $newUser,
                DeviceReassignmentLog::REASON_OPERATOR_FORCED,
                hadBoundCredentials: $this->hasBoundCredentials($existingDevice),
                operatorId: (int) $operator->id,
            );

            $platform = $existingDevice->platform;
            $appVersion = $existingDevice->app_version;
            $existingDevice->delete();

            return $this->createDevice($newUser, $deviceId, $platform, $appVersion);
        });
    }

    /**
     * Whether a device holds biometric or passkey credentials bound to its owner.
     */
    private function hasBoundCredentials(MobileDevice $device): bool
    {
        return $device->biometric_enabled || $device->passkey_enabled;
    }

    /**
     * Persist a fresh device row for a user, enforcing the per-user device cap.
     *
     * @param array<string, mixed>|null $metadata
     */
    private function createDevice(
        User $user,
        string $deviceId,
        string $platform,
        string $appVersion,
        ?string $pushToken = null,
        ?string $deviceName = null,
        ?string $deviceModel = null,
        ?string $osVersion = null,
        ?array $metadata = null
    ): MobileDevice {
        $deviceCount = MobileDevice::where('user_id', $user->id)->count();
        if ($deviceCount >= self::MAX_DEVICES_PER_USER) {
            $this->removeOldestInactiveDevice($user);
        }

        $device = MobileDevice::create([
            'user_id'        => $user->id,
            'device_id'      => $deviceId,
            'platform'       => $platform,
            'push_token'     => $pushToken,
            'device_name'    => $deviceName,
            'device_model'   => $deviceModel,
            'os_version'     => $osVersion,
            'app_version'    => $appVersion,
            'metadata'       => $metadata,
            'last_active_at' => now(),
        ]);

        Log::info('Mobile device registered', [
            'user_id'   => $user->id,
            'device_id' => $deviceId,
            'platform'  => $platform,
        ]);

        return $device;
    }

    /**
     * Write the audit trail for a device ownership flip.
     */
    private function logReassignment(
        MobileDevice $existingDevice,
        User $newUser,
        string $reason,
        bool $hadBoundCredentials,
        ?int $operatorId
    ): void {
        DeviceReassignmentLog::create([
            'device_id'             => $existingDevice->device_id,
            'previous_user_id'      => $existingDevice->user_id,
            'new_user_id'           => $newUser->id,
            'had_bound_credentials' => $hadBoundCredentials,
            'reason'                => $reason,
            'operator_id'           => $operatorId,
            'ip_address'            => request()->ip(),
            'user_agent'            => request()->userAgent(),
        ]);

        Log::warning('Mobile device reassigned to new user', [
            'device_id'             => $existingDevice->device_id,
            'previous_user_id'      => $existingDevice->user_id,
            'new_user_id'           => $newUser->id,
            'reason'                => $reason,
            'had_bound_credentials' => $hadBoundCredentials,
            'operator_id'           => $operatorId,
        ]);
    }

    /**
     * Update a mobile device.
     *
     * @param array<string, mixed> $data
     */
    public function updateDevice(MobileDevice $device, array $data): MobileDevice
    {
        $device->update($data);
        $device->refresh();

        return $device;
    }

    /**
     * Update the push token for a device.
     */
    public function updatePushToken(MobileDevice $device, string $pushToken): MobileDevice
    {
        // Check if token is already used by another device
        $existingDevice = MobileDevice::where('push_token', $pushToken)
            ->where('id', '!=', $device->id)
            ->first();

        if ($existingDevice) {
            // Clear the token from the other device
            $existingDevice->update(['push_token' => null]);
        }

        $device->update(['push_token' => $pushToken]);
        $device->refresh();

        return $device;
    }

    /**
     * Unregister a mobile device.
     */
    public function unregisterDevice(MobileDevice $device): void
    {
        Log::info('Mobile device unregistered', [
            'user_id'   => $device->user_id,
            'device_id' => $device->device_id,
        ]);

        $device->delete();
    }

    /**
     * Get all devices for a user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MobileDevice>
     */
    public function getUserDevices(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return MobileDevice::where('user_id', $user->id)
            ->orderBy('last_active_at', 'desc')
            ->get();
    }

    /**
     * Get active devices for a user (not blocked).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MobileDevice>
     */
    public function getActiveDevices(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return MobileDevice::where('user_id', $user->id)
            ->active()
            ->orderBy('last_active_at', 'desc')
            ->get();
    }

    /**
     * Get devices with push notification capability.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MobileDevice>
     */
    public function getPushEnabledDevices(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return MobileDevice::where('user_id', $user->id)
            ->active()
            ->withPushToken()
            ->get();
    }

    /**
     * Find device by device ID.
     */
    public function findByDeviceId(string $deviceId): ?MobileDevice
    {
        return MobileDevice::where('device_id', $deviceId)->first();
    }

    /**
     * Find device by ID and user.
     */
    public function findByIdForUser(string $id, User $user): ?MobileDevice
    {
        return MobileDevice::where('id', $id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Find all passkey-enabled devices for a user identified by email.
     *
     * @return \Illuminate\Support\Collection<int, MobileDevice>
     */
    public function findPasskeyDevicesByEmail(string $email): \Illuminate\Support\Collection
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return collect();
        }

        return MobileDevice::where('user_id', $user->id)
            ->where('passkey_enabled', true)
            ->whereNotNull('passkey_credential_id')
            ->get();
    }

    /**
     * Block a device.
     */
    public function blockDevice(MobileDevice $device, string $reason): void
    {
        $device->block($reason);

        Log::warning('Mobile device blocked', [
            'user_id'   => $device->user_id,
            'device_id' => $device->device_id,
            'reason'    => $reason,
        ]);
    }

    /**
     * Unblock a device.
     */
    public function unblockDevice(MobileDevice $device): void
    {
        $device->unblock();

        Log::info('Mobile device unblocked', [
            'user_id'   => $device->user_id,
            'device_id' => $device->device_id,
        ]);
    }

    /**
     * Trust a device (bypass some security checks).
     */
    public function trustDevice(MobileDevice $device, ?string $trustedBy = null): void
    {
        $device->trust($trustedBy);

        Log::info('Mobile device trusted', [
            'user_id'    => $device->user_id,
            'device_id'  => $device->device_id,
            'trusted_by' => $trustedBy,
        ]);
    }

    /**
     * Record device activity.
     */
    public function recordActivity(MobileDevice $device): void
    {
        $device->recordActivity();
    }

    /**
     * Remove the oldest inactive device for a user.
     */
    private function removeOldestInactiveDevice(User $user): void
    {
        $oldestDevice = MobileDevice::where('user_id', $user->id)
            ->orderBy('last_active_at', 'asc')
            ->first();

        if ($oldestDevice) {
            Log::info('Removing oldest inactive device to make room', [
                'user_id'   => $user->id,
                'device_id' => $oldestDevice->device_id,
            ]);
            $oldestDevice->delete();
        }
    }

    /**
     * Remove all devices for a user.
     */
    public function removeAllDevices(User $user): int
    {
        $count = MobileDevice::where('user_id', $user->id)->count();

        MobileDevice::where('user_id', $user->id)->delete();

        Log::info('All mobile devices removed for user', [
            'user_id' => $user->id,
            'count'   => $count,
        ]);

        return $count;
    }

    /**
     * Clean up stale devices (not active for X days).
     */
    public function cleanupStaleDevices(int $daysInactive = 90): int
    {
        $threshold = now()->subDays($daysInactive);

        $count = MobileDevice::where('last_active_at', '<', $threshold)
            ->orWhereNull('last_active_at')
            ->delete();

        Log::info('Cleaned up stale mobile devices', [
            'count'          => $count,
            'threshold_days' => $daysInactive,
        ]);

        return $count;
    }

    /**
     * Get device statistics for monitoring.
     *
     * @return array<string, int>
     */
    public function getStatistics(): array
    {
        return [
            'total_devices'     => MobileDevice::count(),
            'active_devices'    => MobileDevice::active()->count(),
            'blocked_devices'   => MobileDevice::where('is_blocked', true)->count(),
            'ios_devices'       => MobileDevice::forPlatform('ios')->count(),
            'android_devices'   => MobileDevice::forPlatform('android')->count(),
            'biometric_enabled' => MobileDevice::biometricEnabled()->count(),
            'with_push_token'   => MobileDevice::withPushToken()->count(),
        ];
    }
}
