<?php

namespace App\Filament\Resources\AffiliateTaskSubmissions;

use App\Filament\Resources\AffiliateTaskSubmissions\Tables\AffiliateTaskSubmissionsTable;
use App\Models\AffiliateTaskSubmission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

// Read-only-plus-actions: no create/edit form exists. Every state transition
// happens through the approve/reject row actions, wired through
// AffiliateTaskService so crediting always goes through the one locked,
// idempotent path — never a plain ->update() on the model directly.
class AffiliateTaskSubmissionResource extends Resource
{
    protected static ?string $model = AffiliateTaskSubmission::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-inbox-stack';
    protected static ?string                $navigationLabel = 'Submissions';
    protected static ?int                   $navigationSort  = 6;

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AffiliateTaskSubmission::where('status', 'submitted')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['affiliate', 'task', 'reviewer']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return AffiliateTaskSubmissionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAffiliateTaskSubmissions::route('/'),
        ];
    }
}
