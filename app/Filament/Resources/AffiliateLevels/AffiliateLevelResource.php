<?php

namespace App\Filament\Resources\AffiliateLevels;

use App\Filament\Resources\AffiliateLevels\Schemas\AffiliateLevelForm;
use App\Filament\Resources\AffiliateLevels\Tables\AffiliateLevelsTable;
use App\Models\AffiliateLevel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AffiliateLevelResource extends Resource
{
    protected static ?string $model = AffiliateLevel::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-arrow-trending-up';
    protected static ?string                $navigationLabel = 'Levels';
    protected static ?int                   $navigationSort  = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    // A level with affiliates already assigned to it uses nullOnDelete on the
    // FK — deleting it would silently strip those affiliates' current level
    // rather than reassigning them anywhere sane. Deactivate instead.
    public static function canDelete(Model $record): bool
    {
        return $record->affiliates()->doesntExist();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('affiliates');
    }

    public static function form(Schema $schema): Schema
    {
        return AffiliateLevelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AffiliateLevelsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAffiliateLevels::route('/'),
            'create' => Pages\CreateAffiliateLevel::route('/create'),
            'edit'   => Pages\EditAffiliateLevel::route('/{record}/edit'),
        ];
    }
}
