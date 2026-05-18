<?php

declare(strict_types=1);

use App\Domain\Wallet\Helpers\Crypto\Base58;
use App\Domain\Wallet\Services\Send\SolanaSponsorSigner;

uses(Tests\TestCase::class);

/**
 * @return array{secretBase58: string, publicBase58: string, publicRaw: non-empty-string}
 */
function makeSponsorKeypair(): array
{
    $kp = sodium_crypto_sign_keypair();

    return [
        'secretBase58' => Base58::encode(sodium_crypto_sign_secretkey($kp)), // 64 bytes
        'publicBase58' => Base58::encode(sodium_crypto_sign_publickey($kp)),
        'publicRaw'    => sodium_crypto_sign_publickey($kp),
    ];
}

it('reports disabled when no sponsor key is configured', function (): void {
    config(['wallet.solana.sponsor.secret_key' => '']);

    expect((new SolanaSponsorSigner())->isEnabled())->toBeFalse();
});

it('reports enabled when a sponsor key is configured', function (): void {
    config(['wallet.solana.sponsor.secret_key' => makeSponsorKeypair()['secretBase58']]);

    expect((new SolanaSponsorSigner())->isEnabled())->toBeTrue();
});

it('derives the sponsor public address from the configured secret key', function (): void {
    $kp = makeSponsorKeypair();
    config(['wallet.solana.sponsor.secret_key' => $kp['secretBase58']]);

    expect((new SolanaSponsorSigner())->publicKeyBase58())->toBe($kp['publicBase58']);
});

it('produces a 64-byte signature that verifies against the sponsor public key', function (): void {
    $kp = makeSponsorKeypair();
    config(['wallet.solana.sponsor.secret_key' => $kp['secretBase58']]);

    $message = random_bytes(120);
    $signature = (new SolanaSponsorSigner())->sign($message);

    expect(strlen($signature))->toBe(64)
        ->and(sodium_crypto_sign_verify_detached($signature, $message, $kp['publicRaw']))->toBeTrue();
});

it('throws when the configured secret key is not valid Base58', function (): void {
    config(['wallet.solana.sponsor.secret_key' => '0OIl-invalid-base58']);

    (new SolanaSponsorSigner())->publicKeyBase58();
})->throws(RuntimeException::class);

it('throws when the configured secret key is not 64 bytes', function (): void {
    // A valid 32-byte Base58 string — wrong length for an ed25519 secret key.
    config(['wallet.solana.sponsor.secret_key' => Base58::encode(random_bytes(32))]);

    (new SolanaSponsorSigner())->sign('anything');
})->throws(RuntimeException::class);

it('throws on sign() when no sponsor key is configured', function (): void {
    config(['wallet.solana.sponsor.secret_key' => '']);

    (new SolanaSponsorSigner())->sign('anything');
})->throws(RuntimeException::class);
