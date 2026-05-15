<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Mobile\Services\MobileDeviceService;
use App\Domain\User\Values\UserRoles;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class MobileReassignDeviceCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:reassign-device
                            {--device-id= : The device_id (UUID) to reassign}
                            {--to-user= : Email of the user to reassign the device to}
                            {--operator-email= : Admin operator email authorising the reassignment}
                            {--confirm : Required — confirms the destructive reassignment}
                            {--allow-production : Required when APP_ENV=production}';

    /** @var string */
    protected $description = 'Force-reassign a mobile device to a new user (operator recovery for the device-takeover guard)';

    public function handle(MobileDeviceService $service): int
    {
        if (app()->environment('production') && ! $this->option('allow-production')) {
            $this->error('Production guard: --allow-production is required when APP_ENV=production.');

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error('--confirm is required: this deletes the existing device row and resets bound credentials.');

            return self::FAILURE;
        }

        $deviceId = (string) $this->option('device-id');
        $toUserEmail = (string) $this->option('to-user');
        $operatorEmail = (string) $this->option('operator-email');

        if ($deviceId === '' || $toUserEmail === '' || $operatorEmail === '') {
            $this->error('--device-id, --to-user and --operator-email are all required.');

            return self::FAILURE;
        }

        $operator = User::where('email', $operatorEmail)->first();
        if ($operator === null || ! $operator->hasRole(UserRoles::ADMIN->value)) {
            $this->error("Operator {$operatorEmail} not found or not an admin.");

            return self::FAILURE;
        }

        $targetUser = User::where('email', $toUserEmail)->first();
        if ($targetUser === null) {
            $this->error("Target user {$toUserEmail} not found.");

            return self::FAILURE;
        }

        try {
            $device = $service->forceReassignDevice($deviceId, $targetUser, $operator);
        } catch (Throwable $e) {
            $this->error("Reassignment failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Device {$deviceId} reassigned to {$targetUser->email} (user #{$targetUser->id}).");
        $this->line("New device row: {$device->id} — push token cleared, biometric/passkey reset.");
        $this->line('Audit row written to device_reassignment_log.');

        return self::SUCCESS;
    }
}
