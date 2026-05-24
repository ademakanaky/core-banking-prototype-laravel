<?php

/**
 * Tests for the BridgeKycProvider skeleton — only the methods that are
 * actually implemented in this PR. The "not yet implemented" methods
 * (getHostedLink, getWebhookValidator-execution, normalizeWebhookPayload)
 * deliberately throw RuntimeException and will be replaced + tested in the
 * BridgeProvider PR (handover §3.1).
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Domain\Compliance\Kyc\Providers\BridgeKycProvider;
use App\Models\User;

it('reports its name as "bridge"', function () {
    expect((new BridgeKycProvider())->getName())->toBe('bridge');
});

it('reports Bridge-Signature as the webhook signature header', function () {
    expect((new BridgeKycProvider())->getWebhookSignatureHeader())->toBe('Bridge-Signature');
});

it('returns not_started status when no bridge_customers row exists', function () {
    $user = User::factory()->create();

    $result = (new BridgeKycProvider())->getStatus($user->id);

    expect($result['status'])->toBe('not_started');
    expect($result['metadata'])->toBe([]);
});

it('reads kyc_status from bridge_customers row', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_test_123',
        'kyc_status'         => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id' => 'va_test_456',
        'supported_rails'    => ['ach', 'sepa'],
        'developer_fee_bps'  => 75,
    ]);

    $result = (new BridgeKycProvider())->getStatus($user->id);

    expect($result['status'])->toBe('approved');
    expect($result['metadata']['bridge_customer_id'])->toBe('cust_test_123');
    expect($result['metadata']['virtual_account_ready'])->toBeTrue();
    expect($result['metadata']['supported_rails'])->toBe(['ach', 'sepa']);
});

it('reports virtual_account_ready false when no virtual_account_id yet', function () {
    $user = User::factory()->create();
    BridgeCustomer::create([
        'user_id'            => $user->id,
        'bridge_customer_id' => 'cust_test_789',
        'kyc_status'         => BridgeCustomer::KYC_PENDING,
        'developer_fee_bps'  => 75,
    ]);

    $result = (new BridgeKycProvider())->getStatus($user->id);

    expect($result['status'])->toBe('pending');
    expect($result['metadata']['virtual_account_ready'])->toBeFalse();
});

it('throws a clear "deferred" RuntimeException for getHostedLink', function () {
    expect(fn () => (new BridgeKycProvider())->getHostedLink(1, KycPurpose::RAMP))
        ->toThrow(RuntimeException::class, 'BridgeProvider PR');
});

it('throws a clear "deferred" RuntimeException for normalizeWebhookPayload', function () {
    expect(fn () => (new BridgeKycProvider())->normalizeWebhookPayload(['type' => 'transfer.completed']))
        ->toThrow(RuntimeException::class, 'BridgeProvider PR');
});

it('encrypts virtual_account_details at rest', function () {
    $user = User::factory()->create();
    $details = [
        'iban'           => 'GB29NWBK60161331926819',
        'account_holder' => 'Acme Test',
        'memo'           => 'ZELTA-12345',
    ];

    BridgeCustomer::create([
        'user_id'                 => $user->id,
        'bridge_customer_id'      => 'cust_enc',
        'kyc_status'              => BridgeCustomer::KYC_APPROVED,
        'virtual_account_id'      => 'va_enc',
        'virtual_account_details' => $details,
        'developer_fee_bps'       => 75,
    ]);

    $raw = (string) Illuminate\Support\Facades\DB::table('bridge_customers')
        ->where('user_id', $user->id)
        ->value('virtual_account_details');

    // Encrypted ciphertext should NOT contain the plaintext IBAN
    expect($raw)->not->toContain('GB29NWBK60161331926819');
    expect($raw)->not->toBe(json_encode($details));

    // Reading through the model returns the decrypted array
    $customer = BridgeCustomer::where('user_id', $user->id)->firstOrFail();
    expect($customer->virtual_account_details)->toBe($details);
});
