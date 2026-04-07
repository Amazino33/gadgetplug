<?php

namespace App\Filament\Vendor\Resources\Products\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\KeyValue;
use Illuminate\Support\Str;
use Filament\Schemas\Components\Utilities\Set;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('category_id')
                    ->relationship('category', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),

                TextInput::make('name')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),

                TextInput::make('slug')
                    ->required(),

                TextInput::make('brand')
                    ->placeholder('e.g., Apple, Samsung'),

                TextInput::make('price')
                    ->numeric()
                    ->required()
                    ->prefix('₦'),

                TextInput::make('stock_quantity')
                    ->numeric()
                    ->required()
                    ->default(0),

                Textarea::make('description')
                    ->columnSpanFull(),

                KeyValue::make('specifications')
                    ->keyLabel('Spec Name (e.g., RAM)')
                    ->valueLabel('Value (e.g., 8GB)')
                    ->columnSpanFull(),
            ]);
    }
}
