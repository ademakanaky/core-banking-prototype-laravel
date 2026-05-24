<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Exceptions;

use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use RuntimeException;

/**
 * Thrown when a KycProvider is asked to handle a KycPurpose it doesn't
 * support (e.g. OndatoKycProvider asked for KycPurpose::RAMP).
 *
 * The router selects providers via config, so reaching this exception in
 * production indicates a misconfiguration — KycServiceProvider binds an
 * unsupported (provider, purpose) pair.
 */
final class UnsupportedKycPurposeException extends RuntimeException
{
    public static function for(string $providerName, KycPurpose $purpose): self
    {
        return new self(sprintf(
            'KYC provider "%s" does not support purpose "%s".',
            $providerName,
            $purpose->value,
        ));
    }
}
