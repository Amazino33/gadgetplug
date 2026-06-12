<?php

namespace App\Filament\Resources\Vendors\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
            Select::make('user_id')
                ->relationship('user', 'name')
                ->required()
                ->label('Shop Owner'),
            TextInput::make('name')
                ->required(),
            TextInput::make('slug')
                ->required()
                ->helperText('Use lowercase letters and dashes (e.g., supreme-gadgets)'),
            Toggle::make('is_verified')
                ->default(true),
            Toggle::make('owner_can_manage_roles')
                ->label('Allow owner to manage roles')
                ->helperText('Grants the vendor owner access to create and assign roles for their team.'),
        ]);
    }
}
