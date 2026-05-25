<?php

declare(strict_types=1);

namespace App\Domain\Ramp\Exceptions;

use RuntimeException;

/**
 * Thrown by RampService::createSession when the supplied quote_id encodes a
 * timestamp older than the quote's validity window (default 60s per the
 * `validUntil` returned by GET /api/v1/ramp/quotes).
 *
 * Surfaced as HTTP 422 with error code `ERR_RAMP_QUOTE_EXPIRED` so mobile
 * can render "Quote expired, refresh and try again" instead of a generic
 * SESSION_ERROR toast.
 *
 * Validation is stateless: quote_id format is `qt_<unix_timestamp>_<random>`
 * and we validate the timestamp prefix. Providers that emit quote_ids in
 * other formats (Onramper, StripeCryptoOnramp) pass through unchecked —
 * their quote-id semantics are their own concern.
 */
final class QuoteExpiredException extends RuntimeException
{
    public function __construct(int $issuedAt, int $now, int $ttlSeconds)
    {
        parent::__construct(sprintf(
            'Quote expired: issued at %d (UNIX), validity window %ds, current time %d.',
            $issuedAt,
            $ttlSeconds,
            $now,
        ));
    }
}
