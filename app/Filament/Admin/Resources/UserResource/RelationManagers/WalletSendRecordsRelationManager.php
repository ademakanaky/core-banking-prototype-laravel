<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Per-user outbound wallet sends (the prepare/submit flow). Read-only.
 *
 * Surfaces the full send lifecycle — pending → submitted → confirmed/failed —
 * with the failure `error_code` / `error_message`, so a support agent can see
 * exactly why a customer's transfer did not go through.
 */
class WalletSendRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'walletSendRecords';

    protected static ?string $recordTitleAttribute = 'public_id';

    protected static ?string $title = 'Wallet Sends';

    protected static ?string $icon = 'heroicon-o-paper-airplane';

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
                Tables\Columns\TextColumn::make('network')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('asset'),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric(decimalPlaces: 6)
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'confirmed'            => 'success',
                        'failed'               => 'danger',
                        'pending', 'submitted' => 'warning',
                        default                => 'gray',
                    }),
                Tables\Columns\TextColumn::make('recipient_address')
                    ->label('Recipient')
                    ->limit(16)
                    ->copyable()
                    ->tooltip(fn ($record): string => (string) $record->recipient_address),
                Tables\Columns\TextColumn::make('tx_hash')
                    ->label('Tx Hash')
                    ->limit(18)
                    ->copyable()
                    ->placeholder('—')
                    ->tooltip(fn ($record): ?string => $record->tx_hash)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_code')
                    ->label('Error')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->tooltip(fn ($record): ?string => $record->error_message)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'submitted' => 'Submitted',
                        'confirmed' => 'Confirmed',
                        'failed'    => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('network')
                    ->options([
                        'solana'   => 'Solana',
                        'polygon'  => 'Polygon',
                        'base'     => 'Base',
                        'arbitrum' => 'Arbitrum',
                        'ethereum' => 'Ethereum',
                    ]),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
