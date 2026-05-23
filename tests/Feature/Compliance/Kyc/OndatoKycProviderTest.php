<?php

/**
 * Unit-ish tests for OndatoKycProvider. Verifies purpose gating + status
 * normalization. The actual Ondato HTTP integration is owned by
 * OndatoService and is covered by the existing Compliance test suite — we
 * don't re-test it here.
 */

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use App\Domain\Compliance\Kyc\Exceptions\UnsupportedKycPurposeException;
use App\Domain\Compliance\Kyc\Providers\OndatoKycProvider;
use App\Domain\Compliance\Services\OndatoService;
use App\Models\User;

it('reports its name as "ondato"', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));

    expect($provider->getName())->toBe('ondato');
});

it('throws UnsupportedKycPurposeException when asked for RAMP', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));

    expect(fn () => $provider->getHostedLink(1, KycPurpose::RAMP))
        ->toThrow(UnsupportedKycPurposeException::class);
});

it('throws UnsupportedKycPurposeException when asked for CARDS', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));

    expect(fn () => $provider->getHostedLink(1, KycPurpose::CARDS))
        ->toThrow(UnsupportedKycPurposeException::class);
});

it('requires application_id in context for TRUSTCERT purpose', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));
    $user = User::factory()->create();

    expect(fn () => $provider->getHostedLink($user->id, KycPurpose::TRUSTCERT))
        ->toThrow(InvalidArgumentException::class, 'application_id');
});

it('maps User kyc_status to the canonical four-value enum', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));

    $approvedUser = User::factory()->create(['kyc_status' => 'approved']);
    expect($provider->getStatus($approvedUser->id)['status'])->toBe('approved');

    $pendingUser = User::factory()->create(['kyc_status' => 'pending']);
    expect($provider->getStatus($pendingUser->id)['status'])->toBe('pending');

    $rejectedUser = User::factory()->create(['kyc_status' => 'rejected']);
    expect($provider->getStatus($rejectedUser->id)['status'])->toBe('rejected');

    $expiredUser = User::factory()->create(['kyc_status' => 'expired']);
    expect($provider->getStatus($expiredUser->id)['status'])->toBe('rejected'); // expired collapses to rejected

    $notStartedUser = User::factory()->create(['kyc_status' => 'not_started']);
    expect($provider->getStatus($notStartedUser->id)['status'])->toBe('not_started');

    $inReviewUser = User::factory()->create(['kyc_status' => 'in_review']);
    expect($provider->getStatus($inReviewUser->id)['status'])->toBe('pending');
});

it('preserves the raw Ondato status under metadata', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));
    $user = User::factory()->create(['kyc_status' => 'expired']);

    $result = $provider->getStatus($user->id);

    expect($result['metadata']['ondato_status'])->toBe('expired');
});

it('reports X-Ondato-Signature as the webhook signature header', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));

    expect($provider->getWebhookSignatureHeader())->toBe('X-Ondato-Signature');
});

it('returns null from normalizeWebhookPayload on unknown status', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));

    expect($provider->normalizeWebhookPayload(['status' => 'WAT']))->toBeNull();
});

it('normalizes Ondato PROCESSED status to "approved"', function () {
    $provider = new OndatoKycProvider(app(OndatoService::class));

    $normalized = $provider->normalizeWebhookPayload([
        'status' => 'PROCESSED',
        'id'     => 'evt-1',
    ]);

    expect($normalized)->not->toBeNull();
    expect($normalized['status'])->toBe('approved');
});
