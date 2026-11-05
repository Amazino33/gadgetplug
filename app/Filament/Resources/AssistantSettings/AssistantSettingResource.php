<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantSettings;

use App\Filament\Resources\AssistantSettings\Pages\ListAssistantSettings;
use App\Filament\Resources\AssistantSettings\Tables\AssistantSettingsTable;
use App\Models\AssistantSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AssistantSettingResource extends Resource
{
    protected static ?string $model = AssistantSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Assistant Vendor Access';

    protected static ?int $navigationSort = 96;

    public static function getNavigationGroup(): ?string
    {
        return 'Assistant';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Scope to vendor-panel users only (vendor owners and team members).
     * Excludes system super-admins, shoppers, and non-vendor users.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('user', function (Builder $query) {
                $query->where(function (Builder $sub) {
                    $sub->whereHas('ownedVendors')
                        ->orWhereHas('memberVendors');
                });
            });
    }

    public static function table(Table $table): Table
    {
        return AssistantSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssistantSettings::route('/'),
        ];
    }
}
