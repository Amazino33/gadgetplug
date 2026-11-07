<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantMessages;

use App\Filament\Resources\AssistantMessages\Pages\ListAssistantMessages;
use App\Filament\Resources\AssistantMessages\Pages\ViewAssistantMessage;
use App\Filament\Resources\AssistantMessages\Tables\AssistantMessagesTable;
use App\Models\AssistantMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AssistantMessageResource extends Resource
{
    protected static ?string $model = AssistantMessage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'All Messages';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Assistant';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AssistantMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssistantMessages::route('/'),
            'view' => ViewAssistantMessage::route('/{record}'),
        ];
    }
}
