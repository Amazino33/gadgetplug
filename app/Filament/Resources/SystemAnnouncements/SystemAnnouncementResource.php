<?php

namespace App\Filament\Resources\SystemAnnouncements;

use App\Filament\Resources\SystemAnnouncements\Pages\CreateSystemAnnouncement;
use App\Filament\Resources\SystemAnnouncements\Pages\EditSystemAnnouncement;
use App\Filament\Resources\SystemAnnouncements\Pages\ListSystemAnnouncements;
use App\Filament\Resources\SystemAnnouncements\Schemas\SystemAnnouncementForm;
use App\Filament\Resources\SystemAnnouncements\Tables\SystemAnnouncementsTable;
use App\Models\SystemAnnouncement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SystemAnnouncementResource extends Resource
{
    protected static ?string $model = SystemAnnouncement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return SystemAnnouncementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SystemAnnouncementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSystemAnnouncements::route('/'),
            'create' => CreateSystemAnnouncement::route('/create'),
            'edit' => EditSystemAnnouncement::route('/{record}/edit'),
        ];
    }
}
