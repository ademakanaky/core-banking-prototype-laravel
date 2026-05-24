<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Providers;

use App\Domain\Compliance\Kyc\Contracts\KycProviderInterface;
use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use App\Domain\Compliance\Kyc\Exceptions\UnsupportedKycPurposeException;
use App\Domain\Compliance\Services\OndatoService;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * Adapter that exposes the existing OndatoService through KycProviderInterface.
 *
 * Ondato handles TRUSTCERT only. The RAMP and CARDS purposes route to
 * BridgeKycProvider via config('kyc.routing.*'); calling those purposes on
 * this adapter throws UnsupportedKycPurposeException by design rather than
 * silently no-oping.
 *
 * Status mapping: User::kyc_status is the existing per-user Ondato column
 * (values: not_started, pending, approved, rejected, expired). We map
 * `expired` → `rejected` to fit the interface's four-value enum; the
 * Ondato-specific value is preserved under metadata.ondato_status.
 */
final class OndatoKycProvider implements KycProviderInterface
{
    public function __construct(
        private readonly OndatoService $ondato,
    ) {
    }

    public function getName(): string
    {
        return 'ondato';
    }

    public function getHostedLink(int $userId, KycPurpose $purpose, array $context = []): string
    {
        if ($purpose !== KycPurpose::TRUSTCERT) {
            throw UnsupportedKycPurposeException::for($this->getName(), $purpose);
        }

        if (! isset($context['application_id'])) {
            throw new InvalidArgumentException(
                'OndatoKycProvider requires $context["application_id"] (TrustCertApplication ID) for TRUSTCERT purpose.'
            );
        }

        $user = User::findOrFail($userId);

        $data = [
            'application_id' => (string) $context['application_id'],
        ];

        if (isset($context['target_level'])) {
            $data['target_level'] = $context['target_level'];
        }
        if (isset($context['first_name'])) {
            $data['first_name'] = $context['first_name'];
        }
        if (isset($context['last_name'])) {
            $data['last_name'] = $context['last_name'];
        }

        $result = $this->ondato->createIdentityVerification($user, $data);

        $url = $result['url'] ?? $result['verificationUrl'] ?? $result['link'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException(
                'OndatoService::createIdentityVerification did not return a hosted link URL.'
            );
        }

        return $url;
    }

    public function getStatus(int $userId): array
    {
        $user = User::findOrFail($userId);

        $raw = (string) ($user->kyc_status ?? 'not_started');

        $normalized = match ($raw) {
            'approved'             => 'approved',
            'pending', 'in_review' => 'pending',
            'rejected', 'expired'  => 'rejected',
            default                => 'not_started',
        };

        return [
            'status'   => $normalized,
            'metadata' => [
                'ondato_status' => $raw,
            ],
        ];
    }

    public function getWebhookValidator(): callable
    {
        return fn (string $rawBody, string $signatureHeader): bool => $this->ondato->validateWebhookSignature(
            $rawBody,
            $signatureHeader,
        );
    }

    public function getWebhookSignatureHeader(): string
    {
        return 'X-Ondato-Signature';
    }

    public function normalizeWebhookPayload(array $payload): ?array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        if ($status === '') {
            return null;
        }

        $normalized = match (true) {
            in_array($status, ['approved', 'processed', 'completed'], true)   => 'approved',
            in_array($status, ['rejected', 'declined', 'failed'], true)       => 'rejected',
            in_array($status, ['pending', 'processing', 'in_progress'], true) => 'pending',
            default                                                           => null,
        };

        if ($normalized === null) {
            return null;
        }

        return [
            'user_id'    => null,
            'event_type' => (string) ($payload['type'] ?? $payload['status'] ?? 'unknown'),
            'status'     => $normalized,
            'raw'        => $payload,
        ];
    }
}
