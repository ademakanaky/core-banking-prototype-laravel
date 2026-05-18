<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Per-user non-custodial wallet addresses. Read-only.
 *
 * Privy registers one row per chain for the same EVM smart-account address,
 * plus one Solana row — so a support agent can confirm exactly which address
 * a customer is operating on each network.
 */
class BlockchainAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'blockchainAddresses';

    protected static ?string $recordTitleAttribute = 'address';

    protected static ?string $title = 'Wallet Addresses';

    protected static ?string $icon = 'heroicon-o-wallet';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('chain')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('address')
                    ->limit(24)
                    ->copyable()
                    ->tooltip(fn ($record): string => (string) $record->address)
                    ->searchable(),
                Tables\Columns\TextColumn::make('label')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('chain')
            ->filters([
                Tables\Filters\SelectFilter::make('chain')
                    ->options([
                        'solana'   => 'Solana',
                        'polygon'  => 'Polygon',
                        'base'     => 'Base',
                        'arbitrum' => 'Arbitrum',
                        'ethereum' => 'Ethereum',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
