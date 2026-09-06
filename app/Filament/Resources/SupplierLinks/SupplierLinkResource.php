<?php

namespace App\Filament\Resources\SupplierLinks;

use App\Filament\Resources\SupplierLinks\Pages\ListSupplierLinks;
use App\Models\SupplierLink;
use App\Models\Vendor;
use App\Services\VendorLink\Rounding\RoundingRules;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Declaring that one vendor may sell from another's catalogue.
 *
 * Admin panel only. Creating a link hands a vendor read access to another
 * vendor's entire catalogue and wholesale prices, so it is not something a
 * vendor can do for themselves — see SupplierLinkPolicy, which is where the
 * rule is actually enforced rather than here.
 */
class SupplierLinkResource extends Resource
{
    protected static ?string $model = SupplierLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Supplier Links';

    protected static ?string $modelLabel = 'supplier link';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('reseller_vendor_id')
                ->label('Reseller — the vendor doing the selling')
                ->options(fn () => Vendor::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->helperText('Their storefront shows the listings. They owe the supplier for what they sell.'),

            Select::make('supplier_vendor_id')
                ->label('Supplier — the vendor whose catalogue is sold')
                ->options(fn () => Vendor::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->different('reseller_vendor_id')
                ->helperText('Read only. Their stock and account are never written to by this.'),

            TextInput::make('markup_percent')
                ->label('Markup')
                ->numeric()
                ->required()
                ->default(40)
                ->suffix('%')
                ->helperText('Added on top of the supplier price. 40% turns ₦10,000 into ₦14,000 before rounding.'),

            Select::make('rounding_rule')
                ->label('Rounding')
                ->options(RoundingRules::options())
                ->default('ends_990')
                ->required(),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Switching off stops new listings and stops prices resolving. Nothing already published or owed is removed.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reseller.name')->label('Reseller')->searchable(),
                Tables\Columns\TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                Tables\Columns\TextColumn::make('markup_percent')->label('Markup')->suffix('%'),
                Tables\Columns\TextColumn::make('rounding_rule')->label('Rounding')->badge(),
                Tables\Columns\TextColumn::make('listings_count')
                    ->counts('listings')
                    ->label('Listings published'),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // No delete action: listings and debts point at these rows. The
            // active toggle is how a link is ended.
            ->emptyStateHeading('No supplier links')
            ->emptyStateDescription('A link lets one vendor sell from another vendor’s catalogue.');
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierLinks::route('/')];
    }
}
