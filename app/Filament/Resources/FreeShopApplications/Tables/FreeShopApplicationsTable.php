<?php

namespace App\Filament\Resources\FreeShopApplications\Tables;

use App\Models\Vendor;
use App\Support\WhatsAppNumber;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FreeShopApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->imageSize(40),

                TextColumn::make('name')
                    ->label('Business')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (Vendor $record): string => $record->shopLinkLabel()),

                TextColumn::make('user.name')
                    ->label('Owner')
                    ->description(fn (Vendor $record): string => WhatsAppNumber::local($record->whatsapp))
                    // WhatsApp is searched here, in whatever shape the admin
                    // types it: "0803 123", "234803…" and "803…" all find it.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $digits = ltrim(preg_replace('/\D/', '', $search), '0');

                        return $query->orWhereHas('user', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                            ->when(strlen($digits) >= 3, fn (Builder $q) => $q->orWhere('whatsapp', 'like', "%{$digits}%"));
                    }),

                TextColumn::make('category')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('city')
                    ->label('City / Area')
                    ->description(fn (Vendor $record): ?string => $record->state)
                    ->searchable(),

                TextColumn::make('applied_at')
                    ->label('Applied')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('info_requested')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Info requested' : '')
                    ->color('warning')
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) === Vendor::STATUS_PENDING),
            ])
            ->defaultSort('applied_at', 'desc')
            ->recordActions([
                ViewAction::make()->label('Review'),
            ])
            ->emptyStateHeading('No applications here')
            ->emptyStateDescription('Free shops appear here as soon as they sign up at /become-a-vendor.');
    }
}
