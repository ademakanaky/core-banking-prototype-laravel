<?php

declare(strict_types=1);

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Account\Models\BlockchainTransaction;
use App\Domain\MobilePayment\Models\ActivityFeedItem;
use App\Domain\Wallet\Models\WalletSendRecord;
use App\Domain\Wallet\Services\EvmTransactionProcessor;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Traits\CreatesSolanaTestTables;

uses(CreatesSolanaTestTables::class);

const EVM_WALLET = '0x1111111111111111111111111111111111111111';
const EVM_COUNTERPARTY = '0x9999999999999999999999999999999999999999';

beforeEach(function (): void {
    $this->createSolanaTestTables();

    $this->user = User::factory()->create();
    $this->bcAddress = BlockchainAddress::create([
        'user_uuid'  => $this->user->uuid,
        'chain'      => 'polygon',
        'address'    => EVM_WALLET,
        'public_key' => EVM_WALLET,
        'is_active'  => true,
    ]);
});

afterEach(function (): void {
    $this->dropSolanaTestTables();
    Schema::dropIfExists('wallet_send_records');
});

/**
 * Build an Alchemy address-activity entry (rawContract.rawValue is the
 * integer token quantity, exactly as the live webhook delivers it).
 *
 * @return array<string, mixed>
 */
function evmActivity(string $hash, string $from, string $to, string $rawValueHex, string $asset = 'USDC'): array
{
    return [
        'hash'        => $hash,
        'fromAddress' => $from,
        'toAddress'   => $to,
        'asset'       => $asset,
        'category'    => 'erc20',
        'blockNum'    => '0x1234',
        'value'       => null,
        'rawContract' => ['rawValue' => $rawValueHex, 'address' => '0xtoken'],
    ];
}

function createWalletSendRecordsTable(): void
{
    Schema::dropIfExists('wallet_send_records');
    Schema::create('wallet_send_records', function ($table): void {
        $table->uuid('id')->primary();
        $table->string('public_id', 64)->unique();
        $table->unsignedBigInteger('user_id');
        $table->string('network', 20);
        $table->string('asset', 10);
        $table->decimal('amount', 30, 8);
        $table->string('sender_address', 128);
        $table->string('recipient_address', 128);
        $table->string('status', 20)->default('pending');
        $table->string('tx_hash', 128)->nullable();
        $table->string('user_op_hash', 128)->nullable();
        $table->string('idempotency_key', 128)->nullable()->unique();
        $table->string('quote_id', 64)->nullable();
        $table->string('error_code', 50)->nullable();
        $table->text('error_message')->nullable();
        $table->json('metadata')->nullable();
        $table->dateTime('submitted_at')->nullable();
        $table->dateTime('confirmed_at')->nullable();
        $table->dateTime('failed_at')->nullable();
        $table->timestamps();
    });
}

it('mirrors an inbound EVM transfer into both tables', function (): void {
    // 100 USDC = 100_000_000 base units (6 decimals) = 0x5f5e100.
    $activity = evmActivity('0xa1a1a1', EVM_COUNTERPARTY, EVM_WALLET, '0x5f5e100');

    $created = (new EvmTransactionProcessor())->processActivity(
        EVM_WALLET,
        $this->bcAddress,
        $this->user->id,
        'polygon',
        $activity,
    );

    expect($created)->toBeTrue();

    $btx = BlockchainTransaction::where('tx_hash', '0xa1a1a1')->where('chain', 'polygon')->first();
    expect($btx)->not->toBeNull();
    assert($btx instanceof BlockchainTransaction);
    expect($btx->type)->toBe('receive')
        ->and($btx->status)->toBe('confirmed')
        ->and($btx->address_uuid)->toBe($this->bcAddress->uuid)
        ->and((float) $btx->amount)->toBe(100.0);

    $feedItem = ActivityFeedItem::where('reference_type', 'evm_tx')
        ->where('reference_id', EvmTransactionProcessor::txHashToReferenceId('polygon', '0xa1a1a1'))
        ->first();
    expect($feedItem)->not->toBeNull();
    assert($feedItem instanceof ActivityFeedItem);
    expect($feedItem->activity_type->value)->toBe('transfer_in')
        ->and($feedItem->asset)->toBe('USDC')
        ->and($feedItem->network)->toBe('polygon')
        ->and((float) $feedItem->amount)->toBe(100.0)
        ->and((int) $feedItem->user_id)->toBe($this->user->id);
});

it('records an outbound EVM transfer as a negative-amount send', function (): void {
    createWalletSendRecordsTable();

    // 25 USDC = 25_000_000 = 0x17d7840.
    $activity = evmActivity('0xb2b2b2', EVM_WALLET, EVM_COUNTERPARTY, '0x17d7840');

    (new EvmTransactionProcessor())->processActivity(
        EVM_WALLET,
        $this->bcAddress,
        $this->user->id,
        'polygon',
        $activity,
    );

    $btx = BlockchainTransaction::where('tx_hash', '0xb2b2b2')->first();
    assert($btx instanceof BlockchainTransaction);
    expect($btx->type)->toBe('send');

    $feedItem = ActivityFeedItem::where('reference_type', 'evm_tx')->first();
    assert($feedItem instanceof ActivityFeedItem);
    expect($feedItem->activity_type->value)->toBe('transfer_out')
        ->and((float) $feedItem->amount)->toBe(-25.0);
});

it('resolves tiny amounts precisely from the hex raw value', function (): void {
    // 1 base unit = 0.000001 USDC — float `value` would lose this.
    $activity = evmActivity('0xc3c3c3', EVM_COUNTERPARTY, EVM_WALLET, '0x1');

    (new EvmTransactionProcessor())->processActivity(
        EVM_WALLET,
        $this->bcAddress,
        $this->user->id,
        'polygon',
        $activity,
    );

    $btx = BlockchainTransaction::where('tx_hash', '0xc3c3c3')->first();
    assert($btx instanceof BlockchainTransaction);
    expect((float) $btx->amount)->toBe(0.000001);
});

it('is idempotent — processing the same transfer twice creates one row', function (): void {
    $activity = evmActivity('0xd4d4d4', EVM_COUNTERPARTY, EVM_WALLET, '0xf4240');
    $processor = new EvmTransactionProcessor();

    expect($processor->processActivity(EVM_WALLET, $this->bcAddress, $this->user->id, 'polygon', $activity))->toBeTrue()
        ->and($processor->processActivity(EVM_WALLET, $this->bcAddress, $this->user->id, 'polygon', $activity))->toBeFalse();

    expect(BlockchainTransaction::where('tx_hash', '0xd4d4d4')->count())->toBe(1)
        ->and(ActivityFeedItem::where('reference_type', 'evm_tx')->count())->toBe(1);
});

it('skips the duplicate feed item when a wallet send already owns the outbound tx', function (): void {
    createWalletSendRecordsTable();

    // A send initiated through our prepare/submit flow already owns a feed item.
    WalletSendRecord::create([
        'public_id'         => 'pi_send_evmtest',
        'user_id'           => $this->user->id,
        'network'           => 'polygon',
        'asset'             => 'USDC',
        'amount'            => '10.00000000',
        'sender_address'    => EVM_WALLET,
        'recipient_address' => EVM_COUNTERPARTY,
        'status'            => 'submitted',
        'tx_hash'           => '0xe5e5e5',
        'submitted_at'      => now(),
    ]);

    $activity = evmActivity('0xe5e5e5', EVM_WALLET, EVM_COUNTERPARTY, '0x989680');

    (new EvmTransactionProcessor())->processActivity(
        EVM_WALLET,
        $this->bcAddress,
        $this->user->id,
        'polygon',
        $activity,
    );

    // Audit row is still written...
    expect(BlockchainTransaction::where('tx_hash', '0xe5e5e5')->exists())->toBeTrue()
        // ...but no duplicate `evm_tx` feed item — the wallet send owns the feed entry.
        ->and(ActivityFeedItem::where('reference_type', 'evm_tx')->count())->toBe(0);
});
