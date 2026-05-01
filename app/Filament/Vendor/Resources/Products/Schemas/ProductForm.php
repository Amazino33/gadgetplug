<?php

namespace App\Filament\Vendor\Resources\Products\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Product Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('category_id')
                                    ->relationship('category', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload(),

                                TextInput::make('brand')
                                    ->placeholder('e.g., Apple, Samsung'),

                                TextInput::make('name')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(
                                        fn (string $operation, $state, Set $set) =>
                                        $operation === 'create'
                                            ? $set('slug', Str::slug($state))
                                            : null
                                    )
                                    ->columnSpanFull(),

                                TextInput::make('slug')
                                    ->required()
                                    ->columnSpanFull(),

                                TextInput::make('price')
                                    ->numeric()
                                    ->required()
                                    ->prefix('₦'),

                                TextInput::make('stock_quantity')
                                    ->numeric()
                                    ->required()
                                    ->default(0),

                                Textarea::make('description')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Product Images')
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('images')
                            ->collection('product-images')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->imageEditor()
                            ->maxFiles(8)
                            ->panelLayout('grid')
                            ->columnSpanFull(),
                    ]),

                Section::make('Specifications')
                    ->schema([
                        KeyValue::make('specifications')
                            ->keyLabel('Spec Name (e.g., RAM)')
                            ->valueLabel('Value (e.g., 8GB)')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

            ]);
    }
}