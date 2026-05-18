<?php

declare(strict_types=1);

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Account\Models\BlockchainTransaction;
use App\Domain\Wallet\Models\WalletSendRecord;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @param array<string, mixed> $overrides
 */
function makeAddress(User $user, string $chain, array $overrides = []): BlockchainAddress
{
    $address = $overrides['address'] ?? ('addr_' . Str::random(16));

    return BlockchainAddress::create(array_merge([
        'user_uuid'  => $user->uuid,
        'chain'      => $chain,
        'address'    => $address,
        'public_key' => $address,
        'is_active'  => true,
    ], $overrides));
}

function makeTx(BlockchainAddress $address, string $hash, string $type = 'receive'): BlockchainTransaction
{
    return BlockchainTransaction::create([
        'address_uuid' => $address->uuid,
        'tx_hash'      => $hash,
        'chain'        => $address->chain,
        'type'         => $type,
        'amount'       => '1.5',
        'fee'          => '0',
        'from_address' => 'from',
        'to_address'   => 'to',
        'status'       => 'confirmed',
    ]);
}

it('exposes the user wallet addresses, scoped to that user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    makeAddress($user, 'solana');
    makeAddress($user, 'polygon');
    makeAddress($other, 'solana');

    expect($user->blockchainAddresses)->toHaveCount(2)
        ->and($user->blockchainAddresses->pluck('chain')->sort()->values()->all())
        ->toBe(['polygon', 'solana']);
});

it('aggregates on-chain transactions across every address the user owns', function (): void {
    $user = User::factory()->create();
    $solAddress = makeAddress($user, 'solana');
    $polyAddress = makeAddress($user, 'polygon');
    makeTx($solAddress, 'sol-tx-1');
    makeTx($polyAddress, 'poly-tx-1', 'send');

    // A different user's transaction must not leak through the hasManyThrough.
    $other = User::factory()->create();
    makeTx(makeAddress($other, 'solana'), 'other-tx-1');

    expect($user->blockchainTransactions()->pluck('tx_hash')->sort()->values()->all())
        ->toBe(['poly-tx-1', 'sol-tx-1']);
});

it('exposes the user outbound wallet send records', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    foreach (['confirmed', 'failed'] as $i => $status) {
        WalletSendRecord::create([
            'public_id'         => 'pi_send_' . Str::random(12),
            'user_id'           => $user->id,
            'network'           => 'polygon',
            'asset'             => 'USDC',
            'amount'            => '10.00000000',
            'sender_address'    => 'sender',
            'recipient_address' => 'recipient',
            'status'            => $status,
        ]);
    }

    WalletSendRecord::create([
        'public_id'         => 'pi_send_' . Str::random(12),
        'user_id'           => $other->id,
        'network'           => 'solana',
        'asset'             => 'USDC',
        'amount'            => '5.00000000',
        'sender_address'    => 'sender',
        'recipient_address' => 'recipient',
        'status'            => 'confirmed',
    ]);

    expect($user->walletSendRecords)->toHaveCount(2);
});
