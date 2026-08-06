<?php

namespace App\Filament\Resources\AffiliateTasks;

use App\Filament\Resources\AffiliateTasks\Schemas\AffiliateTaskForm;
use App\Filament\Resources\AffiliateTasks\Tables\AffiliateTasksTable;
use App\Models\AffiliateTask;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AffiliateTaskResource extends Resource
{
    protected static ?string $model = AffiliateTask::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-clipboard-document-check';
    protected static ?string                $navigationLabel = 'Tasks';
    protected static ?int                   $navigationSort  = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('submissions');
    }

    public static function form(Schema $schema): Schema
    {
        return AffiliateTaskForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AffiliateTasksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAffiliateTasks::route('/'),
            'create' => Pages\CreateAffiliateTask::route('/create'),
            'edit'   => Pages\EditAffiliateTask::route('/{record}/edit'),
        ];
    }
}
