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
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

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
                                    ->columnSpanFull(),

                                TextInput::make('sku')
                                    ->label('SKU')
                                    ->placeholder('e.g., APL-IP15-128-BLK')
                                    ->maxLength(100),

                                TextInput::make('barcode')
                                    ->label('Barcode')
                                    ->placeholder('e.g., 0123456789012')
                                    ->maxLength(100),

                                TextInput::make('cost_price')
                                    ->numeric()
                                    ->prefix('₦')
                                    ->placeholder('Leave blank if unknown')
                                    ->helperText('Optional — shown as "—" in reports until set.'),

                                TextInput::make('price')
                                    ->numeric()
                                    ->required()
                                    ->prefix('₦')
                                    ->gt('cost_price'),


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

                Section::make('Visibility')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('status')
                                    ->options([
                                        'draft'     => 'Draft',
                                        'published' => 'Published',
                                        'archived'  => 'Archived',
                                    ])
                                    ->default('draft')
                                    ->required()
                                    ->live(),

                                DateTimePicker::make('published_at')
                                    ->label('Publish Date')
                                    ->placeholder('Publish immediately')
                                    ->hidden(fn ($get) => $get('status') !== 'published'),

                                DateTimePicker::make('unpublish_at')
                                    ->label('Unpublish Date')
                                    ->placeholder('Never (stays live)')
                                    ->after('published_at')
                                    ->hidden(fn ($get) => $get('status') !== 'published'),
                            ]),
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