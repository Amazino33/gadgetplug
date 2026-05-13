<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Schemas\Schema;
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
            Toggle::make('is_active')
                ->default(true),
        ]);
    }
}
