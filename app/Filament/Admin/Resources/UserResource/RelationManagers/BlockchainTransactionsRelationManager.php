<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Per-user on-chain transaction history, across every wallet address and
 * chain. Read-only — this is the support-facing mirror of what actually
 * settled on-chain (Solana via Helius, EVM via Alchemy), including dust
 * that is hidden from the customer's in-app feed.
 */
class BlockchainTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'blockchainTransactions';

    protected static ?string $recordTitleAttribute = 'tx_hash';

    protected static ?string $title = 'Blockchain Transactions';

    protected static ?string $icon = 'heroicon-o-arrows-right-left';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                Tables\Columns\TextColumn::make('chain')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'receive' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric(decimalPlaces: 6)
                    ->weight('bold')
                    ->color(fn ($record): string => $record->type === 'receive' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'failed'    => 'danger',
                        default     => 'warning',
                    }),
                Tables\Columns\TextColumn::make('from_address')
                    ->label('From')
                    ->limit(16)
                    ->copyable()
                    ->tooltip(fn ($record): string => (string) $record->from_address)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('to_address')
                    ->label('To')
                    ->limit(16)
                    ->copyable()
                    ->tooltip(fn ($record): string => (string) $record->to_address)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tx_hash')
                    ->label('Tx Hash')
                    ->limit(18)
                    ->copyable()
                    ->tooltip(fn ($record): string => (string) $record->tx_hash)
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('chain')
                    ->options([
                        'solana'   => 'Solana',
                        'polygon'  => 'Polygon',
                        'base'     => 'Base',
                        'arbitrum' => 'Arbitrum',
                        'ethereum' => 'Ethereum',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'receive' => 'Receive',
                        'send'    => 'Send',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'confirmed' => 'Confirmed',
                        'failed'    => 'Failed',
                    ]),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
