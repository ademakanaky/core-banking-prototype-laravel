<?php

declare(strict_types=1);

use App\Domain\Wallet\Services\WalletTransferService;

describe('WalletTransferService', function (): void {
    beforeEach(function (): void {
        $this->service = new WalletTransferService();
    });

    // ── Address Validation ──────────────────────────────────────

    describe('validateAddress', function (): void {
        it('validates a valid Solana address', function (): void {
            $result = $this->service->validateAddress(
                '7xR9Hk3JpZ1mNvLqQ8nWX5vY2bF4cD6eA9tGTsP4f2Z',
                'SOLANA',
            );

            expect($result['valid'])->toBeTrue();
            expect($result['network'])->toBe('SOLANA');
            expect($result['address_type'])->not->toBeNull();
            expect($result['error'])->toBeNull();
        });

        it('rejects an invalid Solana address', function (): void {
            $result = $this->service->validateAddress(
                'invalid-address!',
                'SOLANA',
            );

            expect($result['valid'])->toBeFalse();
            expect($result['network'])->toBe('SOLANA');
            expect($result['address_type'])->toBeNull();
            expect($result['error'])->toContain('Invalid Solana address format');
        });

        it('validates a valid Tron address', function (): void {
            $result = $this->service->validateAddress(
                'TJYbqJPyFqFNeAXUGNsmbmh7ya2Bji3XDQ',
                'TRON',
            );

            expect($result['valid'])->toBeTrue();
            expect($result['network'])->toBe('TRON');
            expect($result['address_type'])->toBe('base58');
            expect($result['error'])->toBeNull();
        });

        it('rejects an invalid Tron address', function (): void {
            $result = $this->service->validateAddress(
                'notATronAddress',
                'TRON',
            );

            expect($result['valid'])->toBeFalse();
            expect($result['error'])->toContain('Invalid Tron address format');
        });

        it('rejects unsupported networks', function (): void {
            $result = $this->service->validateAddress(
                '0x742d35Cc6634C0532925a3b844Bc9e7595f2bD08',
                'ETHEREUM',
            );

            expect($result['valid'])->toBeFalse();
            expect($result['error'])->toContain('Unsupported network');
        });

        it('detects short Solana addresses as wallet type', function (): void {
            // 32-char base58 address (wallet)
            $result = $this->service->validateAddress(
                '7xR9Hk3JpZ1mNvLqQ8nWX5vY2bF4cDaK',
                'SOLANA',
            );

            expect($result['valid'])->toBeTrue();
            expect($result['address_type'])->toBe('wallet');
        });

        it('validates address with exact 44 base58 characters', function (): void {
            $result = $this->service->validateAddress(
                'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                'SOLANA',
            );

            expect($result['valid'])->toBeTrue();
            expect($result['address_type'])->toBe('program');
        });
    });

    // ── Name Resolution ─────────────────────────────────────────

    describe('resolveName', function (): void {
        it('returns stub for .sol names on Solana', function (): void {
            $result = $this->service->resolveName('alice.sol', 'SOLANA');

            expect($result['resolved'])->toBeFalse();
            expect($result['name'])->toBe('alice.sol');
            expect($result['address'])->toBeNull();
            expect($result['network'])->toBe('SOLANA');
            expect($result['error'])->toContain('SNS resolution');
        });

        it('rejects .eth names on Solana', function (): void {
            $result = $this->service->resolveName('vitalik.eth', 'SOLANA');

            expect($result['resolved'])->toBeFalse();
            expect($result['error'])->toContain('not supported');
        });

        it('rejects .eth names on Tron', function (): void {
            $result = $this->service->resolveName('vitalik.eth', 'TRON');

            expect($result['resolved'])->toBeFalse();
            expect($result['error'])->toContain('not supported on Tron');
        });

        it('rejects unsupported networks', function (): void {
            $result = $this->service->resolveName('alice.sol', 'ETHEREUM');

            expect($result['resolved'])->toBeFalse();
            expect($result['error'])->toContain('Unsupported network');
        });

        it('returns error for unknown name formats', function (): void {
            $result = $this->service->resolveName('alice.xyz', 'SOLANA');

            expect($result['resolved'])->toBeFalse();
            expect($result['error'])->toContain('not available');
        });

        it('returns correct network in result', function (): void {
            $result = $this->service->resolveName('alice.sol', 'SOLANA');

            expect($result['network'])->toBe('SOLANA');
            expect($result['name'])->toBe('alice.sol');
        });
    });

    // ── Transfer Quotes ─────────────────────────────────────────

    describe('getTransferQuote', function (): void {
        it('returns a quote for Solana USDC transfer', function (): void {
            $result = $this->service->getTransferQuote('SOLANA', 'USDC', '100.00');

            expect($result['network'])->toBe('SOLANA');
            expect($result['asset'])->toBe('USDC');
            expect($result['amount'])->toBe('100.00');
            expect($result['fee_currency'])->toBe('USD');
            expect($result['estimated_time_seconds'])->toBe(5);
            expect($result['estimated_fee'])->toBe('0.001000');
        });

        it('returns a quote for Tron USDC transfer', function (): void {
            $result = $this->service->getTransferQuote('TRON', 'USDC', '50.00');

            expect($result['network'])->toBe('TRON');
            expect($result['asset'])->toBe('USDC');
            expect($result['estimated_time_seconds'])->toBe(30);
            expect($result['estimated_fee'])->toBe('0.500000');
        });

        it('throws for invalid network', function (): void {
            expect(fn () => $this->service->getTransferQuote('ETHEREUM', 'USDC', '100.00'))
                ->toThrow(ValueError::class);
        });

        it('throws for invalid asset', function (): void {
            // 'BTC' was 'USDT' before USDT was added to PaymentAsset. Pick a
            // symbol that genuinely isn't in the enum so the cast throws.
            expect(fn () => $this->service->getTransferQuote('SOLANA', 'BTC', '100.00'))
                ->toThrow(ValueError::class);
        });

        it('accepts USDT on Solana now that the enum supports it', function (): void {
            $result = $this->service->getTransferQuote('SOLANA', 'USDT', '50.00');

            expect($result['network'])->toBe('SOLANA');
            expect($result['asset'])->toBe('USDT');
        });
    });
});
