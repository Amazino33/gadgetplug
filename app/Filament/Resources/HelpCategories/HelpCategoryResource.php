<?php

namespace App\Filament\Resources\HelpCategories;

use App\Filament\Resources\HelpCategories\Pages\ManageHelpCategories;
use App\Models\HelpCategory;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;

// The shelves the vendor help centre is arranged on. Platform-wide content, so
// admin-only to write and readable by every vendor: the same shape as
// MarketingMaterialResource, which this follows deliberately.
class HelpCategoryResource extends Resource
{
    protected static ?string $model = HelpCategory::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationLabel = 'Help Categories';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Help Centre';
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
                ->helperText('What vendors will see on the help centre landing page, e.g. "Procurement".'),

            TextInput::make('icon')
                ->label('Icon')
                ->maxLength(255)
                ->placeholder('heroicon-o-truck')
                ->helperText('Optional Heroicon name. Left blank, the category gets a neutral book icon.'),

            TextInput::make('sort_order')
                ->numeric()
                ->integer()
                ->default(0)
                ->required()
                ->helperText('Lower numbers come first.'),

            Toggle::make('is_published')
                ->label('Visible to vendors')
                ->default(true)
                ->helperText('Hiding a category hides its articles with it, without deleting anything.'),
        ])->columns(2);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHelpCategories::route('/'),
        ];
    }
}
