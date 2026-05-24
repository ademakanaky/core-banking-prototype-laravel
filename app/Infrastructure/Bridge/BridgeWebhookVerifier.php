<?php

declare(strict_types=1);

namespace App\Infrastructure\Bridge;

/**
 * Verifies a Bridge.xyz webhook signature.
 *
 * Bridge sends events with a `Bridge-Signature` header in a format similar
 * to Stripe's: `t=<unix_timestamp>,v1=<hex_hmac_sha256>`. The HMAC is
 * computed over `<timestamp>.<raw_body>` using the webhook secret.
 *
 * Mirrors the layout of the StripeCryptoOnramp signature scheme so the
 * pattern reads identically across providers. The signature scheme should
 * be sanity-checked against an actual Bridge sandbox webhook payload
 * before the first production deploy — see TODO note on the parser.
 *
 * Anti-replay: rejects timestamps drifted more than `tolerance` seconds
 * from the current time. Default 300 seconds matches Stripe's default.
 */
final class BridgeWebhookVerifier
{
    private const TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly string $secret,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self((string) config('kyc.providers.bridge.webhook_secret', ''));
    }

    public function verify(string $rawBody, string $signatureHeader, ?int $now = null): bool
    {
        if ($this->secret === '') {
            // No secret configured: in non-production we accept any well-formed
            // signature so the dev loop works; in production we hard-fail.
            return ! app()->environment('production');
        }

        if ($signatureHeader === '') {
            return false;
        }

        // TODO: confirm Bridge's actual signature header format against a
        // live sandbox webhook. We assume Stripe-style `t=...,v1=...` here.
        /** @var array<string, list<string>> $parts */
        $parts = [];
        foreach (explode(',', $signatureHeader) as $element) {
            $element = trim($element);
            if ($element === '') {
                continue;
            }
            $pair = array_pad(explode('=', $element, 2), 2, '');
            $parts[$pair[0]][] = $pair[1];
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);
        $signatures = $parts['v1'] ?? [];

        if ($timestamp === 0 || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
