<?php

declare(strict_types=1);

namespace App\Domain\MobilePayment\Enums;

/**
 * Supported assets for mobile payments.
 *
 * USDC and USDT — both 6-decimal stablecoins on every chain we support
 * (USDT is absent on Base mainnet; {@see \App\Domain\Wallet\Constants\EvmTokens::contractFor()}
 * returns null and the preparer rejects with InvalidAssetException).
 */
enum PaymentAsset: string
{
    case USDC = 'USDC';
    case USDT = 'USDT';

    public function label(): string
    {
        return match ($this) {
            self::USDC => 'USD Coin',
            self::USDT => 'Tether USD',
        };
    }

    public function decimals(): int
    {
        return match ($this) {
            self::USDC, self::USDT => 6,
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
