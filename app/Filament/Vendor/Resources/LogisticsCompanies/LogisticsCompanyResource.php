<?php

namespace App\Filament\Vendor\Resources\LogisticsCompanies;

use App\Models\LogisticsCompany;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogisticsCompanyResource extends Resource
{
    protected static ?string $model = LogisticsCompany::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-building-office-2';
    protected static string|null|\UnitEnum  $navigationGroup = 'Logistics';
    protected static ?string                $navigationLabel = 'Logistics Companies';
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_logistics');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Company Details')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('phone')->tel()->required()->maxLength(20),
                TextInput::make('whatsapp')->tel()->maxLength(20),
                TextInput::make('email')->email()->maxLength(255),
                Textarea::make('address')->rows(2)->columnSpanFull(),
                Toggle::make('is_active')->label('Active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('whatsapp')->placeholder('—'),
                TextColumn::make('delivery_persons_count')
                    ->label('Riders')
                    ->counts('deliveryPersons')
                    ->alignCenter(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageLogisticsCompanies::route('/'),
        ];
    }
}
