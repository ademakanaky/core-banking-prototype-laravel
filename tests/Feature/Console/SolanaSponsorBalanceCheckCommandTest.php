<?php

declare(strict_types=1);

use App\Domain\Wallet\Exceptions\SolanaRpcException;
use App\Domain\Wallet\Helpers\Crypto\Base58;
use App\Domain\Wallet\Services\Send\HeliusRpcClient;

/**
 * Configure a valid sponsor key and return its base58 public address.
 */
function configureSponsorKey(): string
{
    $kp = sodium_crypto_sign_keypair();
    config(['wallet.solana.sponsor.secret_key' => Base58::encode(sodium_crypto_sign_secretkey($kp))]);

    return Base58::encode(sodium_crypto_sign_publickey($kp));
}

/**
 * Bind a HeliusRpcClient whose getBalance() returns the given lamport value.
 */
function fakeHeliusBalance(int $lamports): void
{
    $rpc = Mockery::mock(HeliusRpcClient::class);
    $rpc->shouldReceive('getBalance')->once()->andReturn($lamports);
    app()->instance(HeliusRpcClient::class, $rpc);
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
    fakeHeliusBalance(500_000_000); // 0.5 SOL

    $this->artisan('solana:check-sponsor-balance')
        ->expectsOutputToContain('OK')
        ->assertExitCode(0);
});

it('fails loudly when the sponsor balance is below the threshold', function (): void {
    configureSponsorKey();
    config(['wallet.solana.sponsor.low_balance_lamports' => 100_000_000]);
    fakeHeliusBalance(10_000_000); // 0.01 SOL

    $this->artisan('solana:check-sponsor-balance')
        ->expectsOutputToContain('LOW')
        ->assertExitCode(1);
});

it('fails when the RPC balance lookup errors', function (): void {
    configureSponsorKey();

    $rpc = Mockery::mock(HeliusRpcClient::class);
    $rpc->shouldReceive('getBalance')->once()->andThrow(
        SolanaRpcException::fromRpcError(0, 'RPC unreachable'),
    );
    app()->instance(HeliusRpcClient::class, $rpc);

    $this->artisan('solana:check-sponsor-balance')->assertExitCode(1);
});
