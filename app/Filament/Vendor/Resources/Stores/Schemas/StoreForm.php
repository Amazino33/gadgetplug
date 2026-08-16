<?php

namespace App\Filament\Vendor\Resources\Stores\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Branch details')
                ->description('A new branch opens active, holding no stock, and is not the main store. Move stock into it from Procurement or an inventory adjustment.')
                ->schema([
                    TextInput::make('name')
                        ->label('Store name')
                        ->required()
                        ->maxLength(80)
                        ->helperText('What your staff call this branch — "Uyo Branch", "Ikot Ekpene Shop".'),

                    // Not editable and not shown on create: HasSlug generates it
                    // from the name, unique within this vendor, so two vendors
                    // can both have a "main-store" without either knowing.
                    TextInput::make('address')
                        ->label('Address')
                        ->maxLength(255)
                        ->helperText('Optional. Shown on the store cards so staff can tell branches apart.'),

                    TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->maxLength(30)
                        ->helperText('Optional.'),
                ])
                ->columns(1),
        ]);
    }
}
