<?php

declare(strict_types=1);

use App\Domain\MobilePayment\Enums\ActivityItemType;
use App\Domain\MobilePayment\Models\ActivityFeedItem;
use App\Domain\Wallet\Models\WalletSendRecord;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Traits\CreatesSolanaTestTables;

uses(CreatesSolanaTestTables::class);

beforeEach(function (): void {
    $this->createSolanaTestTables();

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
});

afterEach(function (): void {
    Schema::dropIfExists('wallet_send_records');
    $this->dropSolanaTestTables();
});

/**
 * @param array<string, mixed> $overrides
 */
function makeSendRecord(array $overrides = []): WalletSendRecord
{
    if (! isset($overrides['user_id'])) {
        $overrides['user_id'] = User::factory()->create()->id;
    }

    return WalletSendRecord::create(array_merge([
        'public_id'         => 'pi_send_' . uniqid(),
        'network'           => 'solana',
        'asset'             => 'USDT',
        'amount'            => '1.00000000',
        'sender_address'    => 'EfkncjQTojTB6m9DqoyBqizLLwZgLu1uwg3Y3FqE6f7Z',
        'recipient_address' => 'BobxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxV',
        'status'            => WalletSendRecord::STATUS_PENDING,
    ], $overrides));
}

it('projects a pending send into the activity feed on creation', function (): void {
    $record = makeSendRecord();

    $item = ActivityFeedItem::where('reference_type', WalletSendRecord::class)
        ->where('reference_id', $record->id)
        ->first();

    expect($item)->not->toBeNull()
        ->and($item->activity_type)->toBe(ActivityItemType::TRANSFER_OUT)
        ->and($item->status)->toBe('pending')
        ->and($item->user_id)->toBe($record->user_id)
        ->and($item->asset)->toBe('USDT')
        ->and((float) $item->amount)->toBe(-1.0)
        ->and($item->to_address)->toBe('BobxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxV');
});

it('updates the feed item status when the send is submitted', function (): void {
    $record = makeSendRecord();

    $record->update([
        'status'       => WalletSendRecord::STATUS_SUBMITTED,
        'tx_hash'      => '5xRealSolanaSignature',
        'submitted_at' => now(),
    ]);

    $item = ActivityFeedItem::where('reference_id', $record->id)->first();
    expect($item->status)->toBe('submitted');
});

it('updates the feed item status when the send fails', function (): void {
    $record = makeSendRecord();

    $record->update([
        'status'        => WalletSendRecord::STATUS_FAILED,
        'error_code'    => 'SOLANA_TX_FAILED',
        'error_message' => 'This transfer could not be completed.',
        'failed_at'     => now(),
    ]);

    $item = ActivityFeedItem::where('reference_id', $record->id)->first();
    expect($item->status)->toBe('failed');
});

it('does not create a duplicate feed item across the full lifecycle', function (): void {
    $record = makeSendRecord();
    $record->update(['status' => WalletSendRecord::STATUS_SUBMITTED, 'tx_hash' => 'sig1', 'submitted_at' => now()]);
    $record->update(['status' => WalletSendRecord::STATUS_CONFIRMED, 'confirmed_at' => now()]);

    $count = ActivityFeedItem::where('reference_id', $record->id)->count();
    expect($count)->toBe(1);

    $item = ActivityFeedItem::where('reference_id', $record->id)->first();
    expect($item->status)->toBe('confirmed');
});

it('keeps occurred_at pinned to creation time across status updates', function (): void {
    $record = makeSendRecord();
    $original = ActivityFeedItem::where('reference_id', $record->id)->first()->occurred_at;

    $this->travel(10)->minutes();
    $record->update(['status' => WalletSendRecord::STATUS_SUBMITTED, 'tx_hash' => 'sig', 'submitted_at' => now()]);

    $item = ActivityFeedItem::where('reference_id', $record->id)->first();
    expect($item->occurred_at->equalTo($original))->toBeTrue();
});
