<?php

namespace App\Filament\Resources\Affiliates\Schemas;

use App\Models\Affiliate;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AffiliateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Affiliate')
                ->schema([
                    // Create: pick any user who isn't already an affiliate — the
                    // code is generated automatically (Affiliate::generateUniqueCode()).
                    Select::make('user_id')
                        ->label('User')
                        ->options(fn () => User::whereDoesntHave('affiliate')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->visible(fn (string $operation) => $operation === 'create'),

                    Placeholder::make('user_display')
                        ->label('User')
                        ->content(fn (?Affiliate $record) => $record?->user?->name . ' (' . $record?->user?->email . ')')
                        ->visible(fn (string $operation) => $operation === 'edit'),

                    Placeholder::make('code_display')
                        ->label('Referral Code')
                        ->content(fn (?Affiliate $record) => $record?->code)
                        ->visible(fn (string $operation) => $operation === 'edit'),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Deactivating stops new commissions from attributing to this affiliate — existing history is untouched.'),
                ])
                ->columns(2),
        ]);
    }
}
