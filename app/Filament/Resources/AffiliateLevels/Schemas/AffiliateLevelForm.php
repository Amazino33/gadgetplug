<?php

namespace App\Filament\Resources\AffiliateLevels\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AffiliateLevelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Level')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('target')
                        ->label('Lifetime Sales Target (₦)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₦')
                        ->required()
                        ->helperText('Lifetime cleared sales value an affiliate must reach to be promoted into this level.'),

                    TextInput::make('rate_value')
                        ->label('Rate Multiplier')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->default(1.00)
                        ->required()
                        ->helperText('Multiplies the resolved base rate — e.g. 1.20 boosts every commission at this level by 20%, capped by the margin-cap setting.'),

                    TextInput::make('sort_order')
                        ->label('Rank (ascending, lowest first)')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->helperText('Determines promotion/demotion order — must be unique across levels.'),

                    Toggle::make('is_active')
                        ->default(true)
                        ->helperText('Inactive levels are skipped by promotion and demotion entirely.'),
                ])
                ->columns(2),
        ]);
    }
}
