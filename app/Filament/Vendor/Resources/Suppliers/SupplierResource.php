<?php
namespace App\Filament\Vendor\Resources\Suppliers;

use App\Models\Supplier;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-truck';
    protected static string|null|\UnitEnum  $navigationGroup = 'Procurement';
    protected static ?string                $navigationLabel = 'Suppliers';
    protected static ?int                   $navigationSort  = 10;

    public static function canAccess(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('suppliers')) {
            return false;
        }

        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $vendor && $user->hasVendorPermission($vendor->id, 'view_any_products');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Supplier Details')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(20),
                TextInput::make('email')->email()->maxLength(255),
                Textarea::make('address')->rows(2),
                Textarea::make('notes')->rows(2)->label('Internal Notes'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('email')->placeholder('—'),
                TextColumn::make('procurements_count')
                    ->label('Orders')
                    ->counts('procurements')
                    ->alignCenter(),
                TextColumn::make('created_at')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSuppliers::route('/'),
        ];
    }
}
