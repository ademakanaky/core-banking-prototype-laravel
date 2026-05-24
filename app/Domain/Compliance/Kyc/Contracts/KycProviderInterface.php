<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Contracts;

use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use App\Domain\Compliance\Kyc\Exceptions\UnsupportedKycPurposeException;
use InvalidArgumentException;

/**
 * Pluggable KYC provider seam. Adapters wrap external providers (Ondato,
 * Bridge.xyz, future) and present a uniform surface to the rest of the app.
 *
 * Selection is per-purpose via KycProviderRouter::resolve(KycPurpose). A
 * provider that does not support a requested purpose MUST throw
 * UnsupportedKycPurposeException rather than silently no-oping.
 */
interface KycProviderInterface
{
    /**
     * Stable identifier used in webhook URL path segments, logs, and config
     * routing. Examples: "ondato", "bridge".
     */
    public function getName(): string;

    /**
     * Issue (or re-issue) a hosted KYC link the user opens in an in-app
     * browser. Purpose-specific context is passed via $context — e.g. Ondato
     * needs $context['application_id'] (TrustCertApplication ID) to derive
     * the target trust level; Bridge ignores $context entirely.
     *
     * Implementations are responsible for lazy provisioning of any
     * provider-side customer record (e.g. Bridge customer creation) on first
     * call, using idempotency keys to make retries safe.
     *
     * @param  array<string, mixed>  $context  Purpose-specific keys; adapter validates schema.
     * @return string  Hosted link URL the user opens to start KYC.
     *
     * @throws UnsupportedKycPurposeException  When this provider does not handle $purpose.
     * @throws InvalidArgumentException  When $context is missing required keys for $purpose.
     */
    public function getHostedLink(int $userId, KycPurpose $purpose, array $context = []): string;

    /**
     * Per-user KYC state from this provider's perspective. Distinct from
     * other providers' state for the same user (per §7.5 partitioning).
     *
     * Status values are normalized to one of: 'not_started', 'pending',
     * 'approved', 'rejected'. Provider-specific detail (trust level, rejection
     * reason, etc.) belongs under `metadata`.
     *
     * @return array{status: 'not_started'|'pending'|'approved'|'rejected', metadata: array<string, mixed>}
     */
    public function getStatus(int $userId): array;

    /**
     * Webhook signature validator. The callable receives the raw HTTP body
     * bytes (NOT re-encoded) and the full signature header string. MUST use
     * hash_equals() for constant-time comparison.
     *
     * @return callable(string $rawBody, string $signatureHeader): bool
     */
    public function getWebhookValidator(): callable;

    /**
     * HTTP header name the validator reads (e.g. "Bridge-Signature",
     * "X-Ondato-Signature").
     */
    public function getWebhookSignatureHeader(): string;

    /**
     * Unwrap a provider-specific webhook event into a canonical shape, or
     * null to explicitly ignore the event (returning 200 + no-op).
     *
     * Status values match getStatus()'s 'status' enum so downstream handlers
     * can treat all providers uniformly.
     *
     * @param  array<string, mixed>  $payload  Parsed JSON body (decoded after signature verification).
     * @return array{user_id: int|null, event_type: string, status: 'not_started'|'pending'|'approved'|'rejected', raw: array<string, mixed>}|null
     */
    public function normalizeWebhookPayload(array $payload): ?array;
}
