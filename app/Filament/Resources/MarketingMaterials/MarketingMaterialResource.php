<?php

namespace App\Filament\Resources\MarketingMaterials;

use App\Filament\Resources\MarketingMaterials\Pages\ManageMarketingMaterials;
use App\Models\MarketingMaterial;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

// Branded creative affiliates post. One stored image serves everyone; the
// per-affiliate part is the caption, which carries their code/link. Burning the
// code into the artwork itself is Prompt 5 and intentionally not done here.
class MarketingMaterialResource extends Resource
{
    protected static ?string $model = MarketingMaterial::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-photo';
    protected static ?string                $navigationLabel = 'Marketing Material';
    protected static ?int                   $navigationSort  = 8;

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
            TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),

            Textarea::make('description')
                ->rows(2)
                ->columnSpanFull()
                ->helperText('Shown to the affiliate — what this creative is for.'),

            SpatieMediaLibraryFileUpload::make('artwork')
                ->collection('artwork')
                ->image()
                ->imageEditor()
                ->columnSpanFull()
                ->required(),

            Textarea::make('caption_template')
                ->label('Caption Template')
                ->rows(3)
                ->columnSpanFull()
                ->default('Shop GadgetPlug — :link (code :code)')
                ->helperText('Use :link for the affiliate\'s referral URL and :code for their code. The caption is how their share stays attributable and reviewable, so it must include one of them.'),

            TextInput::make('sort_order')->numeric()->integer()->default(0)->required(),

            Toggle::make('is_active')->default(true)
                ->helperText('Inactive material is hidden from affiliates but keeps its history.'),
        ])->columns(2);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMarketingMaterials::route('/'),
        ];
    }
}
