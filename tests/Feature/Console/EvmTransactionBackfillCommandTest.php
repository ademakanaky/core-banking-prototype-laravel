<?php

declare(strict_types=1);

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Account\Models\BlockchainTransaction;
use App\Domain\MobilePayment\Models\ActivityFeedItem;
use App\Domain\Wallet\Services\EvmTransactionProcessor;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Traits\CreatesSolanaTestTables;

uses(CreatesSolanaTestTables::class);

beforeEach(function (): void {
    $this->createSolanaTestTables();
    config(['relayer.balance_checking.alchemy_api_key' => 'test-alchemy-key']);
});

afterEach(function (): void {
    $this->dropSolanaTestTables();
});

/**
 * @param array<int, array<string, mixed>> $transfers
 */
function fakeAlchemyTransfers(array $transfers): void
{
    Http::fake([
        '*.g.alchemy.com/*' => Http::response([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => ['transfers' => $transfers],
        ]),
    ]);
}

it('fails when the Alchemy API key is not configured', function (): void {
    config(['relayer.balance_checking.alchemy_api_key' => '']);

    $this->artisan('evm:backfill-transactions')->assertFailed();
});

it('backfills an inbound EVM transfer from the Alchemy Transfers API', function (): void {
    $wallet = '0x1111111111111111111111111111111111111111';
    $user = User::factory()->create();
    BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'polygon',
        'address'    => $wallet,
        'public_key' => $wallet,
        'is_active'  => true,
    ]);

    // 50 USDC = 50_000_000 base units = 0x2faf080.
    fakeAlchemyTransfers([[
        'blockNum'    => '0x1234',
        'hash'        => '0xbackfill01',
        'from'        => '0x9999999999999999999999999999999999999999',
        'to'          => $wallet,
        'value'       => 50.0,
        'asset'       => 'USDC',
        'category'    => 'erc20',
        'rawContract' => ['value' => '0x2faf080', 'address' => '0xtoken', 'decimal' => '0x6'],
        'metadata'    => ['blockTimestamp' => '2026-05-01T12:00:00.000Z'],
    ]]);

    $this->artisan('evm:backfill-transactions', ['--network' => 'polygon', '--limit' => 10])
        ->assertSuccessful();

    $btx = BlockchainTransaction::where('tx_hash', '0xbackfill01')->where('chain', 'polygon')->first();
    expect($btx)->not->toBeNull();
    assert($btx instanceof BlockchainTransaction);
    expect($btx->type)->toBe('receive')
        ->and((float) $btx->amount)->toBe(50.0);

    $feedItem = ActivityFeedItem::where('reference_id', EvmTransactionProcessor::txHashToReferenceId('polygon', '0xbackfill01'))
        ->first();
    expect($feedItem)->not->toBeNull();
    assert($feedItem instanceof ActivityFeedItem);
    expect($feedItem->network)->toBe('polygon')
        // occurred_at is taken from the transfer's block timestamp, not "now".
        ->and($feedItem->occurred_at->format('Y-m-d'))->toBe('2026-05-01');
});

it('stores nothing on a dry run', function (): void {
    $wallet = '0x2222222222222222222222222222222222222222';
    $user = User::factory()->create();
    BlockchainAddress::create([
        'user_uuid'  => $user->uuid,
        'chain'      => 'arbitrum',
        'address'    => $wallet,
        'public_key' => $wallet,
        'is_active'  => true,
    ]);

    fakeAlchemyTransfers([[
        'blockNum'    => '0x1',
        'hash'        => '0xdryrun01',
        'from'        => '0x9999999999999999999999999999999999999999',
        'to'          => $wallet,
        'value'       => 5.0,
        'asset'       => 'USDC',
        'category'    => 'erc20',
        'rawContract' => ['value' => '0x4c4b40', 'address' => '0xtoken', 'decimal' => '0x6'],
    ]]);

    $this->artisan('evm:backfill-transactions', ['--network' => 'arbitrum', '--dry-run' => true])
        ->assertSuccessful();

    expect(BlockchainTransaction::where('tx_hash', '0xdryrun01')->exists())->toBeFalse();
});
