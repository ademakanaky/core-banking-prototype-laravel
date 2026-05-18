<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services\Send;

use App\Domain\Wallet\Helpers\Crypto\Base58;
use InvalidArgumentException;
use RuntimeException;
use SodiumException;

/**
 * Holds the platform Solana fee-payer ("sponsor") keypair and produces the
 * sponsor's ed25519 signature for outbound sends.
 *
 * A non-custodial wallet holds SPL stablecoins but no SOL, so it cannot pay
 * its own transaction fee. When a sponsor key is configured, the sponsor
 * account becomes the transaction fee payer (account index 0): the sponsor
 * co-signs server-side while the user's device signs as the transfer
 * authority. Both sign the SAME unsigned message bytes.
 *
 * The configured secret key is the base58-encoded 64-byte ed25519 secret key
 * (32-byte seed || 32-byte public key — the standard Solana / libsodium
 * layout), so the public key is the trailing 32 bytes and never needs to be
 * stored separately.
 *
 * When no key is configured {@see self::isEnabled()} returns false and the
 * send flow falls back to the legacy single-signer behaviour (sender pays its
 * own fee). Callers MUST check `isEnabled()` before calling the other methods.
 */
class SolanaSponsorSigner
{
    private const SECRET_KEY_BYTES = 64;

    private const PUBLIC_KEY_BYTES = 32;

    /**
     * True when a sponsor key is configured and the send flow should route
     * the transaction fee through the sponsor account.
     */
    public function isEnabled(): bool
    {
        return $this->configuredSecretKey() !== '';
    }

    /**
     * Base58 public address of the sponsor account — the account that must be
     * funded with SOL and that becomes the transaction fee payer.
     *
     * @throws RuntimeException when no sponsor key is configured
     */
    public function publicKeyBase58(): string
    {
        $secret = $this->decodedSecretKey();

        return Base58::encode(substr($secret, self::SECRET_KEY_BYTES - self::PUBLIC_KEY_BYTES));
    }

    /**
     * Produce the sponsor's detached 64-byte ed25519 signature over the given
     * unsigned Solana message bytes.
     *
     * @throws RuntimeException when no sponsor key is configured
     */
    public function sign(string $messageBytes): string
    {
        $secret = $this->decodedSecretKey();

        try {
            $signature = sodium_crypto_sign_detached($messageBytes, $secret);
        } catch (SodiumException $e) {
            throw new RuntimeException('Failed to produce Solana sponsor signature: ' . $e->getMessage(), 0, $e);
        } finally {
            sodium_memzero($secret);
        }

        return $signature;
    }

    /**
     * Decode and validate the configured secret key into raw 64 bytes.
     *
     * @return non-empty-string the raw 64-byte ed25519 secret key
     *
     * @throws RuntimeException when the key is missing or malformed
     */
    private function decodedSecretKey(): string
    {
        $configured = $this->configuredSecretKey();

        if ($configured === '') {
            throw new RuntimeException(
                'Solana sponsor key is not configured (WALLET_SOLANA_SPONSOR_SECRET_KEY is empty).',
            );
        }

        try {
            $raw = Base58::decode($configured);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                'WALLET_SOLANA_SPONSOR_SECRET_KEY is not valid Base58: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($raw === '' || strlen($raw) !== self::SECRET_KEY_BYTES) {
            throw new RuntimeException(
                'WALLET_SOLANA_SPONSOR_SECRET_KEY must decode to ' . self::SECRET_KEY_BYTES
                . ' bytes (seed || public key); got ' . strlen($raw) . '.',
            );
        }

        return $raw;
    }

    private function configuredSecretKey(): string
    {
        return trim((string) config('wallet.solana.sponsor.secret_key', ''));
    }
}
