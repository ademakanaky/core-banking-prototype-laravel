<?php

/**
 * Smoke tests for bridge:inspect-user — the operator support command.
 * Asserts each section renders without error for the meaningful cases:
 * unknown user, user with no bridge_customers row, fully-set-up user.
 */

declare(strict_types=1);

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Models\RampSession;
use App\Models\User;

it('returns failure for an unknown email', function () {
    $this->artisan('bridge:inspect-user', ['email' => 'ghost@example.com'])
        ->expectsOutputToContain('No user with email')
        ->assertExitCode(1);
});

it('prints "no bridge_customers row" when the user has not started Bridge KYC', function () {
    $user = User::factory()->create(['email' => 'fresh@example.com']);

    $this->artisan('bridge:inspect-user', ['email' => 'fresh@example.com'])
        ->expectsOutputToContain('no bridge_customers row')
        ->assertSuccessful();
});

it('prints the bridge_customers + address + sessions sections for a fully set-up user', function () {
    $user = User::factory()->create(['email' => 'setup@example.com']);

    BridgeCustomer::create([
        'user_id'                 => $user->id,
        'bridge_customer_id'      => 'cust_inspect_1',
        'kyc_status'              => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id'      => 'va_inspect_1',
        'virtual_account_details' => ['iban' => 'GB29NWBK60161331926819', 'memo' => 'CUSTREF-IN1'],
        'supported_rails'         => ['ach', 'sepa'],
        'developer_fee_bps'       => 0,
    ]);

    BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'polygon',
        'address'    => '0xinspect',
        'public_key' => '0x' . str_repeat('a', 128),
        'is_active'  => true,
    ]);

    RampSession::create([
        'user_id'             => $user->id,
        'provider'            => 'bridge',
        'type'                => 'on',
        'fiat_currency'       => 'EUR',
        'fiat_amount'         => 100.00,
        'crypto_currency'     => 'USDC',
        'crypto_amount'       => 99.90,
        'status'              => 'completed',
        'source'              => 'user_initiated',
        'provider_session_id' => 'bridge_va_va_inspect_1',
    ]);

    $this->artisan('bridge:inspect-user', ['email' => 'setup@example.com'])
        ->expectsOutputToContain('cust_inspect_1')
        ->expectsOutputToContain('va_inspect_1')
        ->expectsOutputToContain('0xinspect')
        ->assertSuccessful();
});
