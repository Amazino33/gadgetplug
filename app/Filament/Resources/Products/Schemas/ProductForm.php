<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Product')
                ->schema([
                    Grid::make(3)->schema([
                        Placeholder::make('name_display')
                            ->label('Name')
                            ->content(fn ($record) => $record->name),

                        Placeholder::make('vendor_display')
                            ->label('Store')
                            ->content(fn ($record) => $record->vendor?->name ?? '—'),

                        Placeholder::make('category_display')
                            ->label('Category')
                            ->content(fn ($record) => $record->category?->name ?? '—'),

                        Placeholder::make('price_display')
                            ->label('Price')
                            ->content(fn ($record) => '₦' . number_format((float) $record->price, 2)),

                        Placeholder::make('category_rate_display')
                            ->label('Category Rate (fallback)')
                            ->content(fn ($record) => $record->category?->commission_rate !== null
                                ? number_format((float) $record->category->commission_rate, 2) . '%'
                                : 'No category rate set'),
                    ]),
                ]),

            Section::make('Affiliate Commission')
                ->schema([
                    TextInput::make('commission_rate')
                        ->label('Commission Rate Override (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->placeholder('No override — falls back to the category rate, then platform default')
                        ->helperText('Leave blank to use this product\'s category rate (or the platform default).'),
                ]),

            Section::make('Reseller Discount')
                ->schema([
                    TextInput::make('reseller_discount')
                        ->label('Reseller Discount Override (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->placeholder('No override — falls back to the category discount, then platform default')
                        ->helperText('Percentage off this product\'s price when an affiliate buys it with their wallet to resell. Leave blank to use this product\'s category discount (or the platform default).'),
                ]),
        ]);
    }
}
