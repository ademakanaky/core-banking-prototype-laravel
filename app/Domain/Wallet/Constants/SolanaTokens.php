<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Constants;

final class SolanaTokens
{
    /** @var array<string, array{symbol: string, decimals: int}> */
    public const KNOWN_MINTS = [
        'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v' => ['symbol' => 'USDC', 'decimals' => 6],
        'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB' => ['symbol' => 'USDT', 'decimals' => 6],
    ];

    public static function resolveSymbol(string $mint): string
    {
        return self::KNOWN_MINTS[$mint]['symbol'] ?? 'SPL';
    }

    /**
     * Resolve a symbol (USDC, USDT, …) to its canonical Solana mint address.
     * Falls back to the input string when the symbol isn't a known SPL token
     * so the caller's QR/URL doesn't end up empty.
     */
    public static function mintFor(string $symbol): string
    {
        foreach (self::KNOWN_MINTS as $mint => $meta) {
            if ($meta['symbol'] === $symbol) {
                return $mint;
            }
        }

        return $symbol;
    }
}
