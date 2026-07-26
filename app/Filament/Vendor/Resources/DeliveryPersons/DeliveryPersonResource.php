<?php

namespace App\Filament\Vendor\Resources\DeliveryPersons;

use App\Models\DeliveryPerson;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DeliveryPersonResource extends Resource
{
    protected static ?string $model = DeliveryPerson::class;

    // Filament pluralizes "DeliveryPerson" to "DeliveryPeople" by default, which doesn't
    // match this resource's "DeliveryPersons" namespace segment and produces a doubled
    // URL ("delivery-persons/delivery-people"). Pin the slug explicitly to avoid that.
    protected static ?string $slug = 'delivery-persons';

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-truck';
    protected static string|null|\UnitEnum  $navigationGroup = 'Logistics';
    protected static ?string                $navigationLabel = 'Riders';
    protected static ?int                   $navigationSort  = 20;

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_logistics');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Rider Details')->schema([
                Select::make('logistics_company_id')
                    ->label('Logistics Company')
                    ->relationship(
                        name: 'logisticsCompany',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->where('vendor_id', filament()->getTenant()->id)
                            ->where('is_active', true),
                    )
                    ->searchable()
                    ->preload()
                    ->placeholder('Freelance / independent rider')
                    ->helperText('Leave blank for a rider not tied to any logistics company.')
                    ->columnSpanFull(),

                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('phone')->tel()->required()->maxLength(20),
                TextInput::make('whatsapp')->tel()->maxLength(20),
                TextInput::make('vehicle_type')->placeholder('e.g., Motorcycle, Bicycle, Van'),
                TextInput::make('plate_number')->maxLength(20),
                Toggle::make('is_active')->label('Active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('logisticsCompany.name')
                    ->label('Company')
                    ->placeholder('Freelance')
                    ->sortable(),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('vehicle_type')->placeholder('—'),
                TextColumn::make('plate_number')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('logistics_company_id')
                    ->label('Company')
                    ->relationship(
                        name: 'logisticsCompany',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query->where('vendor_id', filament()->getTenant()->id),
                    ),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDeliveryPersons::route('/'),
        ];
    }
}
