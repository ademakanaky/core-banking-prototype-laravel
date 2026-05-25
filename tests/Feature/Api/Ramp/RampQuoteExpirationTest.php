<?php

/**
 * Tests the stateless quote-freshness validation added in RampService.
 * Quote ids of the form `qt_<unix_timestamp>_<random>` (emitted by
 * BridgeProvider::getQuotes) are validated at createSession time —
 * past-TTL quotes return HTTP 422 with ERR_RAMP_QUOTE_EXPIRED so mobile
 * can render a "Quote expired" toast instead of a generic SESSION_ERROR.
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ramp.default_provider' => 'mock']);
});

it('returns 422 ERR_RAMP_QUOTE_EXPIRED when quote_id is older than the 60s TTL', function () {
    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    // Build a stale quote_id 5 minutes in the past
    $staleTimestamp = time() - 300;
    $staleQuoteId = sprintf('qt_%d_%s', $staleTimestamp, bin2hex(random_bytes(4)));

    $this->postJson('/api/v1/ramp/session', [
        'type'            => 'on',
        'fiat_currency'   => 'USD',
        'fiat_amount'     => 100,
        'crypto_currency' => 'USDC',
        'wallet_address'  => '0xtest',
        'quote_id'        => $staleQuoteId,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ERR_RAMP_QUOTE_EXPIRED');
});

it('accepts a fresh qt_-format quote_id (within TTL)', function () {
    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $freshQuoteId = sprintf('qt_%d_%s', time(), bin2hex(random_bytes(4)));

    $this->postJson('/api/v1/ramp/session', [
        'type'            => 'on',
        'fiat_currency'   => 'USD',
        'fiat_amount'     => 100,
        'crypto_currency' => 'USDC',
        'wallet_address'  => '0xtest',
        'quote_id'        => $freshQuoteId,
    ])
        ->assertStatus(201);
});

it('passes through quote_ids that do not match the qt_ format (other providers)', function () {
    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    // Onramper / Stripe-style quote_id — RampService doesn't validate
    // these because freshness is the originating provider's concern.
    $this->postJson('/api/v1/ramp/session', [
        'type'            => 'on',
        'fiat_currency'   => 'USD',
        'fiat_amount'     => 100,
        'crypto_currency' => 'USDC',
        'wallet_address'  => '0xtest',
        'quote_id'        => 'q_some_onramper_id_format',
    ])
        ->assertStatus(201);
});

it('accepts a request with no quote_id at all', function () {
    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $this->postJson('/api/v1/ramp/session', [
        'type'            => 'on',
        'fiat_currency'   => 'USD',
        'fiat_amount'     => 100,
        'crypto_currency' => 'USDC',
        'wallet_address'  => '0xtest',
    ])
        ->assertStatus(201);
});

it('BridgeProvider::getQuotes returns a quote_id in the qt_ format that RampService accepts immediately', function () {
    config([
        'ramp.default_provider'         => 'bridge',
        'kyc.providers.bridge.api_key'  => 'sk_test',
        'kyc.providers.bridge.base_url' => 'https://api.bridge.xyz',
    ]);

    $user = User::factory()->create(['kyc_status' => 'approved']);
    Sanctum::actingAs($user, ['read', 'write', 'delete']);

    $response = $this->getJson('/api/v1/ramp/quotes?type=on&fiat=USD&amount=100&crypto=USDC')
        ->assertOk();

    $quoteId = $response->json('data.quotes.0.quote_id');
    expect($quoteId)->toBeString()->toStartWith('qt_');

    // Round-trip: feed the just-issued quote_id back into createSession.
    // Bridge createSession requires a customer + VA — provide them:
    BridgeCustomer::create([
        'user_id'                 => $user->id,
        'bridge_customer_id'      => 'cust_qt_roundtrip',
        'kyc_status'              => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id'      => 'va_qt_roundtrip',
        'virtual_account_details' => ['iban' => 'GB29NWBK60161331926819'],
        'developer_fee_bps'       => 75,
    ]);

    $this->postJson('/api/v1/ramp/session', [
        'type'            => 'on',
        'fiat_currency'   => 'USD',
        'fiat_amount'     => 100,
        'crypto_currency' => 'USDC',
        'wallet_address'  => '0xtest',
        'quote_id'        => $quoteId,
    ])->assertStatus(201);
});
