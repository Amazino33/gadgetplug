<?php

namespace App\Filament\Resources\Vendors\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('user.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label('Owner Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('online_sales_enabled')
                    ->label('Online Sales')
                    ->boolean()
                    ->alignCenter(),

                // Reads the opposite way round to the flag on purpose: green
                // tick means "has access", which is what you scan a vendor list
                // for. A red cross is the exception you are looking for.
                IconColumn::make('dashboard_blocked')
                    ->label('Dashboard')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->tooltip(fn ($record) => $record->dashboard_blocked ? $record->dashboardBlockMessage() : null)
                    ->alignCenter(),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products')
                    ->alignCenter()
                    ->sortable(),

                IconColumn::make('pos_vat_enabled')
                    ->label('VAT')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('whatsapp')
                    ->label('WhatsApp')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_verified')->label('Verified'),
                TernaryFilter::make('online_sales_enabled')->label('Online Sales'),
                TernaryFilter::make('dashboard_blocked')->label('Dashboard blocked'),
            ])
            ->recordActions([
                Action::make('toggleOnlineSales')
                    ->label(fn ($record) => $record->online_sales_enabled ? 'Disable Online Sales' : 'Enable Online Sales')
                    ->icon(fn ($record) => $record->online_sales_enabled ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn ($record) => $record->online_sales_enabled ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn ($record) => $record->online_sales_enabled
                        ? 'This vendor\'s products will disappear from the storefront and Orders will be hidden from their panel. POS/offline sales are unaffected. Existing online orders are untouched.'
                        : 'This vendor\'s products will become visible on the storefront and they will regain access to Orders in their panel.')
                    ->action(fn ($record) => $record->update(['online_sales_enabled' => ! $record->online_sales_enabled])),
                // Separate from the edit form so support can lock or release an
                // account in one click from the list, without opening a form
                // that could save four other fields by accident.
                Action::make('toggleDashboardBlock')
                    ->label(fn ($record) => $record->dashboard_blocked ? 'Restore Access' : 'Block Access')
                    ->icon(fn ($record) => $record->dashboard_blocked ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                    ->color(fn ($record) => $record->dashboard_blocked ? 'success' : 'danger')
                    ->modalHeading(fn ($record) => $record->dashboard_blocked
                        ? 'Restore access for '.$record->name
                        : 'Block access for '.$record->name)
                    ->modalDescription(fn ($record) => $record->dashboard_blocked
                        ? 'The owner and their team get the vendor panel and the POS till back immediately.'
                        : 'The owner and their whole team lose the vendor panel and the POS till immediately — till logins already open stop working on their next request. Storefront listings are not affected.')
                    ->modalSubmitActionLabel(fn ($record) => $record->dashboard_blocked ? 'Restore access' : 'Block access')
                    ->schema(fn ($record) => $record->dashboard_blocked ? [] : [
                        Textarea::make('dashboard_blocked_reason')
                            ->label('Reason shown to the vendor')
                            ->rows(2)
                            ->maxLength(500)
                            ->required()
                            ->default(fn () => $record->dashboard_blocked_reason)
                            ->placeholder('e.g. Outstanding platform commission for August. Contact accounts to settle.'),
                    ])
                    // Confirmation is redundant once there is a form to fill in,
                    // but the unblock direction has no form and still deserves a
                    // deliberate click.
                    ->requiresConfirmation(fn ($record) => (bool) $record->dashboard_blocked)
                    ->action(function ($record, array $data) {
                        $record->update($record->dashboard_blocked
                            ? ['dashboard_blocked' => false]
                            : [
                                'dashboard_blocked'        => true,
                                'dashboard_blocked_reason' => $data['dashboard_blocked_reason'] ?? null,
                            ]);
                    }),
                Action::make('open_panel')
                    ->label('Open Panel')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record) => route('filament.vendor.home', ['tenant' => $record->slug]))
                    ->openUrlInNewTab(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
