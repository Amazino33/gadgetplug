<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
            TextInput::make('name')
                ->required(),
            Textarea::make('description')
                ->columnSpanFull(),
            SpatieMediaLibraryFileUpload::make('image')
                ->collection('category-image')
                ->image()
                ->imageEditor()
                ->panelLayout('compact')
                ->helperText('Shown on the storefront\'s category tiles. Leave blank and a photo from a product in this category is used instead.')
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->default(true),
            TextInput::make('commission_rate')
                ->label('Affiliate Commission Rate (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->placeholder('No override — falls back to the platform default')
                ->helperText('Leave blank to use the platform default rate for products in this category.'),
            TextInput::make('reseller_discount')
                ->label('Reseller Discount (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->placeholder('No override — falls back to the platform default')
                ->helperText('Leave blank to use the platform default reseller discount for products in this category.'),
        ]);
    }
}
