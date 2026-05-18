<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Wallet\Exceptions\SolanaRpcException;
use App\Domain\Wallet\Services\Send\HeliusRpcClient;
use App\Domain\Wallet\Services\Send\SolanaSponsorSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Monitor the Solana fee-payer (sponsor) account balance.
 *
 * Sponsored sends draw SOL from the platform sponsor account to pay
 * transaction fees. If that account runs dry, every Solana send starts
 * failing again with "Attempt to debit an account but found no record of a
 * prior credit" — the exact failure sponsorship exists to prevent.
 *
 * This command logs at CRITICAL level and exits non-zero when the balance
 * drops below the configured threshold so cron/monitoring alerts fire while
 * there is still runway to top the account up. Schedule it hourly.
 */
class SolanaSponsorBalanceCheckCommand extends Command
{
    protected $signature = 'solana:check-sponsor-balance';

    protected $description = 'Alert when the Solana fee-payer (sponsor) account SOL balance is low';

    private const LAMPORTS_PER_SOL = 1_000_000_000;

    public function handle(SolanaSponsorSigner $sponsor, HeliusRpcClient $rpc): int
    {
        if (! $sponsor->isEnabled()) {
            $this->info('Solana sponsor is not configured (WALLET_SOLANA_SPONSOR_SECRET_KEY empty) — nothing to check.');

            return self::SUCCESS;
        }

        $address = $sponsor->publicKeyBase58();
        $threshold = (int) config('wallet.solana.sponsor.low_balance_lamports', 100_000_000);

        try {
            $lamports = $rpc->getBalance($address);
        } catch (SolanaRpcException $e) {
            Log::error('Solana sponsor balance check failed to reach the RPC', [
                'address' => $address,
                'error'   => $e->getMessage(),
            ]);
            $this->error("Could not fetch sponsor balance: {$e->getMessage()}");

            return self::FAILURE;
        }

        $sol = $this->formatSol($lamports);

        if ($lamports < $threshold) {
            Log::critical('Solana sponsor account balance is LOW — top it up to keep sends flowing', [
                'address'            => $address,
                'balance_lamports'   => $lamports,
                'balance_sol'        => $sol,
                'threshold_lamports' => $threshold,
                'threshold_sol'      => $this->formatSol($threshold),
            ]);
            $this->error("Solana sponsor balance LOW: {$sol} SOL ({$address}) — below threshold {$this->formatSol($threshold)} SOL.");

            return self::FAILURE;
        }

        $this->info("Solana sponsor balance OK: {$sol} SOL ({$address}).");

        return self::SUCCESS;
    }

    private function formatSol(int $lamports): string
    {
        return rtrim(rtrim(number_format($lamports / self::LAMPORTS_PER_SOL, 9, '.', ''), '0'), '.') ?: '0';
    }
}
