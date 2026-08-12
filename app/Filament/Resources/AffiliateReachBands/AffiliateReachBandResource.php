<?php

namespace App\Filament\Resources\AffiliateReachBands;

use App\Filament\Resources\AffiliateReachBands\Pages\ManageAffiliateReachBands;
use App\Models\AffiliateReachBand;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;

// The reward ladder for daily shares. Deliberately coarse: exact self-reported
// view counts are forgeable, so the money question is only ever "which bucket",
// and the gap between adjacent buckets is what a lie is worth.
class AffiliateReachBandResource extends Resource
{
    protected static ?string $model = AffiliateReachBand::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-signal';
    protected static ?string                $navigationLabel = 'Reach Bands';
    protected static ?int                   $navigationSort  = 7;

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->columnSpanFull()
                ->helperText('Shown to the affiliate and to the reviewer, e.g. "Solid (500–1,999)".'),

            TextInput::make('min_reach')
                ->label('Minimum Reach')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),

            TextInput::make('max_reach')
                ->label('Maximum Reach')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->placeholder('No upper limit')
                ->helperText('Leave blank for the open-ended top band.'),

            TextInput::make('points')
                ->label('Plug Points Awarded')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),

            TextInput::make('sort_order')
                ->numeric()
                ->integer()
                ->default(0)
                ->required(),

            Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive bands are skipped when matching a reported reach. Past awards already frozen onto their submission are unaffected.'),
        ])->columns(2);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAffiliateReachBands::route('/'),
        ];
    }
}
