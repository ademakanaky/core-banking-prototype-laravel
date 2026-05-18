<?php

declare(strict_types=1);

use App\Domain\Wallet\Helpers\Crypto\Base58;
use App\Domain\Wallet\Services\Send\HeliusRpcClient;
use Mockery\MockInterface;

/**
 * Configure a valid sponsor key and return its base58 public address.
 */
function configureSponsorKey(): string
{
    $kp = sodium_crypto_sign_keypair();
    config(['wallet.solana.sponsor.secret_key' => Base58::encode(sodium_crypto_sign_secretkey($kp))]);

    return Base58::encode(sodium_crypto_sign_publickey($kp));
}

it('is a no-op when the sponsor key is not configured', function (): void {
    config(['wallet.solana.sponsor.secret_key' => '']);

    $this->artisan('solana:check-sponsor-balance')
        ->expectsOutputToContain('not configured')
        ->assertExitCode(0);
});

it('reports success when the sponsor balance is above the threshold', function (): void {
    configureSponsorKey();
    config(['wallet.solana.sponsor.low_balance_lamports' => 100_000_000]);

    $this->mock(HeliusRpcClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getBalance')->once()->andReturn(500_000_000); // 0.5 SOL
    });

    $this->artisan('solana:check-sponsor-balance')
        ->expectsOutputToContain('OK')
        ->assertExitCode(0);
});

it('fails loudly when the sponsor balance is below the threshold', function (): void {
    configureSponsorKey();
    config(['wallet.solana.sponsor.low_balance_lamports' => 100_000_000]);

    $this->mock(HeliusRpcClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getBalance')->once()->andReturn(10_000_000); // 0.01 SOL
    });

    $this->artisan('solana:check-sponsor-balance')
        ->expectsOutputToContain('LOW')
        ->assertExitCode(1);
});

it('fails when the RPC balance lookup errors', function (): void {
    configureSponsorKey();

    $this->mock(HeliusRpcClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getBalance')->once()->andThrow(
            App\Domain\Wallet\Exceptions\SolanaRpcException::fromRpcError(0, 'RPC unreachable'),
        );
    });

    $this->artisan('solana:check-sponsor-balance')->assertExitCode(1);
});
