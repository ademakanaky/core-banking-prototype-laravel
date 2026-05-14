<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests that the device-takeover security guard surfaces as HTTP 409
 * (DeviceTakeoverAttemptException::render).
 *
 * Before the render() method was added, this case fell through to Laravel's
 * default exception handler and emitted a generic 500 — masking the security
 * signal from mobile and triggering opaque "Server Error" reports.
 *
 * @see app/Domain/Mobile/Exceptions/DeviceTakeoverAttemptException.php
 * @see app/Domain/Mobile/Services/MobileDeviceService.php (registerDevice guard)
 */
class RegisterDeviceTakeoverHttpStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_takeover_attempt_returns_409_with_structured_error_code(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        MobileDevice::create([
            'user_id'     => $owner->id,
            'device_id'   => 'shared-device-uuid',
            'platform'    => 'android',
            'app_version' => '1.0.0',
            'push_token'  => 'fcm-original-token',
        ]);

        Sanctum::actingAs($attacker, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/v1/notifications/register-device', [
            'device_id'   => 'shared-device-uuid',
            'platform'    => 'android',
            'app_version' => '1.0.0',
            'push_token'  => 'fcm-attacker-token',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'DEVICE_REGISTERED_TO_DIFFERENT_USER');

        // Body must not leak the owner's identity. Render() returns only
        // message + error; the standardize-error-responses callback adds
        // request_id. None of the takeover context fields belong on the wire.
        $response->assertJsonMissingPath('existing_user_id')
            ->assertJsonMissingPath('attempted_user_id')
            ->assertJsonMissingPath('user_id');

        // Existing device row is untouched — no push_token rotation, no user_id swap.
        $this->assertDatabaseHas('mobile_devices', [
            'device_id'  => 'shared-device-uuid',
            'user_id'    => $owner->id,
            'push_token' => 'fcm-original-token',
        ]);
    }

    public function test_same_user_re_registering_existing_device_returns_201_and_updates_push_token(): void
    {
        $user = User::factory()->create();

        MobileDevice::create([
            'user_id'     => $user->id,
            'device_id'   => 'self-owned-device',
            'platform'    => 'ios',
            'app_version' => '1.0.0',
            'push_token'  => 'apns-old-token',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->postJson('/api/v1/notifications/register-device', [
            'device_id'   => 'self-owned-device',
            'platform'    => 'ios',
            'app_version' => '1.1.0',
            'push_token'  => 'apns-new-token',
        ])->assertStatus(201);

        $this->assertDatabaseHas('mobile_devices', [
            'device_id'  => 'self-owned-device',
            'user_id'    => $user->id,
            'push_token' => 'apns-new-token',
        ]);
    }
}
