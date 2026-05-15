<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Mobile\Models\MobileDevice;
use App\Domain\User\Values\UserRoles;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate([
        'name'       => UserRoles::ADMIN->value,
        'guard_name' => 'web',
    ]);
    $this->operator = User::factory()->create(['email' => 'op@finaegis.com']);
    $this->operator->assignRole(UserRoles::ADMIN->value);
});

it('force-reassigns a credential-bound device to the target user', function () {
    $previousOwner = User::factory()->create(['email' => 'prev@finaegis.com']);
    $newOwner = User::factory()->create(['email' => 'new@finaegis.com']);

    MobileDevice::create([
        'user_id'           => $previousOwner->id,
        'device_id'         => 'op-device-uuid',
        'platform'          => 'android',
        'app_version'       => '1.0.0',
        'biometric_enabled' => true,
    ]);

    $this->artisan('mobile:reassign-device', [
        '--device-id'      => 'op-device-uuid',
        '--to-user'        => 'new@finaegis.com',
        '--operator-email' => 'op@finaegis.com',
        '--confirm'        => true,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('mobile_devices', [
        'device_id' => 'op-device-uuid',
        'user_id'   => $newOwner->id,
    ]);
    $this->assertDatabaseHas('device_reassignment_log', [
        'device_id'             => 'op-device-uuid',
        'previous_user_id'      => $previousOwner->id,
        'new_user_id'           => $newOwner->id,
        'operator_id'           => $this->operator->id,
        'reason'                => 'operator_forced',
        'had_bound_credentials' => true,
    ]);
});

it('refuses to run without --confirm', function () {
    $this->artisan('mobile:reassign-device', [
        '--device-id'      => 'op-device-uuid',
        '--to-user'        => 'new@finaegis.com',
        '--operator-email' => 'op@finaegis.com',
    ])->assertExitCode(1);
});

it('rejects a non-admin operator', function () {
    $previousOwner = User::factory()->create();
    User::factory()->create(['email' => 'new@finaegis.com']);
    User::factory()->create(['email' => 'notadmin@finaegis.com']);

    MobileDevice::create([
        'user_id'     => $previousOwner->id,
        'device_id'   => 'op-device-uuid',
        'platform'    => 'android',
        'app_version' => '1.0.0',
    ]);

    $this->artisan('mobile:reassign-device', [
        '--device-id'      => 'op-device-uuid',
        '--to-user'        => 'new@finaegis.com',
        '--operator-email' => 'notadmin@finaegis.com',
        '--confirm'        => true,
    ])->assertExitCode(1);
});
