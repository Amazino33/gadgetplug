<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\Actions\EditOnlineNameAction;
use App\Filament\Resources\Products\Actions\ManageProductImagesAction;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Vendor;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    // Neutral gray box + photo glyph — the same placeholder the vendor list uses.
    private const PLACEHOLDER_IMAGE = 'data:image/svg+xml,'.'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="%23e5e7eb"/><path d="M28 68l16-22 11 13 9-11 18 24" stroke="%239ca3af" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="37" cy="36" r="7" fill="%239ca3af"/></svg>';

    public static function configure(Table $table): Table
    {
        $canSeeCost = ProductResource::canSeeCost();

        return $table
            ->columns([
                ImageColumn::make('thumb')
                    ->label('')
                    // A resold listing shows its supplier's photo, as customers see it.
                    ->getStateUsing(fn (Product $record): ?string => $record->catalogueImageUrl('thumb') ?: null)
                    ->defaultImageUrl(self::PLACEHOLDER_IMAGE)
                    ->size(44),

                TextColumn::make('name')
                    ->label('Internal name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (Product $record): ?string => $record->isLinked()
                        ? 'Images managed on supplier product'
                        : null),

                TextColumn::make('online_name')
                    ->label('Online name')
                    // A resold listing shows its supplier's online name.
                    ->state(fn (Product $record): ?string => $record->isLinked() ? $record->catalogueSource()?->online_name : $record->online_name)
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('vendor.name')->label('Vendor')->searchable()->sortable(),

                TextColumn::make('category.name')->label('Category')->sortable()->toggleable(),

                TextColumn::make('brand')->placeholder('—')->searchable()->toggleable(),

                TextColumn::make('price')->label('Selling price')->money('NGN')->sortable(),

                TextColumn::make('image_count')
                    ->label('Photos')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => (int) $state === 0 ? 'danger' : 'gray'),

                TextColumn::make('vendorlink')
                    ->label('VendorLink')
                    ->state(fn (Product $record): ?string => self::vendorLinkLabel($record))
                    ->placeholder('—')
                    ->color(fn (Product $record): string => $record->isLinked() ? 'info' : 'gray')
                    // A resold listing's photos live on the supplier's product, so
                    // the useful thing to offer is a jump to that row.
                    ->url(fn (Product $record): ?string => $record->isLinked()
                        ? ListProducts::getUrl(['tableFilters' => ['product' => ['id' => $record->source_product_id]]])
                        : null),

                IconColumn::make('visible_online')
                    ->label('Online')
                    ->boolean()
                    ->state(fn (Product $record): bool => isset(self::visibleOnlineIds()[$record->id])),

                // Super-admin only — the reason this page originally existed.
                TextColumn::make('commission_rate')
                    ->label('Rate Override')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2).'%' : '—')
                    ->badge()
                    ->color(fn ($state) => $state !== null ? 'success' : 'gray')
                    ->visible($canSeeCost)
                    ->toggleable(),
                TextColumn::make('reseller_discount')
                    ->label('Discount Override')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2).'%' : '—')
                    ->badge()
                    ->color(fn ($state) => $state !== null ? 'success' : 'gray')
                    ->visible($canSeeCost)
                    ->toggleable(),
            ])
            ->groups([
                Group::make('vendor.name')->label('Vendor')->collapsible(),
            ])
            ->defaultGroup('vendor.name')
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn () => Vendor::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                // Own products only: a resold listing takes its supplier's.
                Filter::make('no_online_name')
                    ->label('No online name')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->ownStock()->where(
                        fn (Builder $q) => $q->whereNull('products.online_name')->orWhere('products.online_name', ''),
                    )),

                Filter::make('no_photo')
                    ->label('No photo')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereDoesntHave(
                        'media',
                        fn (Builder $q) => $q->where('collection_name', 'product-images'),
                    )),

                SelectFilter::make('vendorlink')
                    ->label('VendorLink')
                    ->options([
                        'linked'   => 'Resold listing (linked)',
                        'supplier' => 'Supplier product (has resellers)',
                        'own'      => 'Own stock, not resold',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'linked'   => $query->linked(),
                        'supplier' => $query->has('resoldListings'),
                        'own'      => $query->ownStock()->doesntHave('resoldListings'),
                        default    => $query,
                    }),

                TernaryFilter::make('visible_online')
                    ->label('Visible online')
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('products.id', Product::query()->visibleOnline()->select('products.id')),
                        false: fn (Builder $query) => $query->whereNotIn('products.id', Product::query()->visibleOnline()->select('products.id')),
                    ),

                // Lets "Images managed on supplier product" jump to one row.
                Filter::make('product')
                    ->schema([TextInput::make('id')->label('Product ID')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['id'] ?? null,
                        fn (Builder $q, $id) => $q->whereKey((int) $id),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['id'] ?? null) ? 'Product #'.$data['id'] : null),
            ])
            ->recordActions([
                ManageProductImagesAction::make(),
                EditOnlineNameAction::make(),
                EditAction::make()->label('Commission')->visible($canSeeCost),
            ]);
    }

    private static function vendorLinkLabel(Product $record): ?string
    {
        if ($record->isLinked()) {
            return 'Reseller of '.($record->supplierLink?->supplierName() ?? 'a supplier');
        }

        $count = (int) ($record->resold_listings_count ?? 0);

        return $count > 0
            ? 'Supplier — '.$count.' reseller '.str('listing')->plural($count)
            : null;
    }

    /**
     * Ids of every product a customer can see right now, looked up once per
     * request rather than once per row.
     *
     * @return array<int, true>
     */
    private static function visibleOnlineIds(): array
    {
        return once(fn () => Product::query()->visibleOnline()->pluck('products.id')->flip()->map(fn () => true)->all());
    }
}
