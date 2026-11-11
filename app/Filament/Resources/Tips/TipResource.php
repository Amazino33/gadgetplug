<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tips;

use App\Filament\Resources\Tips\Pages\CreateTip;
use App\Filament\Resources\Tips\Pages\EditTip;
use App\Filament\Resources\Tips\Pages\ListTips;
use App\Filament\Resources\Tips\Pages\ReportTip;
use App\Filament\Resources\Tips\Schemas\TipForm;
use App\Filament\Resources\Tips\Tables\TipsTable;
use App\Models\Tip;
use App\Policies\TipPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * In-app tips and "What's new" announcements, written here with no code.
 * Who sees one, and when, is App\Support\Engagement\TipEligibility's call.
 */
class TipResource extends Resource
{
    protected static ?string $model = Tip::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationLabel = 'In-App Tips';

    protected static ?string $modelLabel = 'tip';

    protected static ?string $pluralModelLabel = 'in-app tips';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return 'Help Centre';
    }

    public static function canAccess(): bool
    {
        return TipPolicy::canManage(auth()->user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return TipForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TipsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTips::route('/'),
            'create' => CreateTip::route('/create'),
            'edit' => EditTip::route('/{record}/edit'),
            'report' => ReportTip::route('/{record}/report'),
        ];
    }
}
