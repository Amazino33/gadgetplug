<?php

namespace App\Filament\Staff\Resources\Products\Tables;

use App\Filament\Resources\Products\Actions\EditOnlineNameAction;
use App\Filament\Resources\Products\Actions\ManageProductImagesAction;
use App\Filament\Resources\Products\Tables\ProductsTable as AdminProductsTable;
use App\Filament\Staff\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// The staff All Products table: the admin table minus cost and the commission
// overrides. Photo and online-name edits go through the same row actions, so
// the behaviour (policy checks, activity logs) is identical to the admin panel.
class ProductsTable
{
    // Neutral gray box + photo glyph — the same placeholder the vendor list uses.
    private const PLACEHOLDER_IMAGE = 'data:image/svg+xml,'.'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="%23e5e7eb"/><path d="M28 68l16-22 11 13 9-11 18 24" stroke="%239ca3af" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="37" cy="36" r="7" fill="%239ca3af"/></svg>';

    public static function configure(Table $table): Table
    {
        return $table
            // Scopes the phone styles in the theme to this table.
            ->extraAttributes(['class' => 'gp-products-table'])
            ->columns([
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('filament.admin.products.mobile-card')
                    ->disabledClick()
                    ->hiddenFrom('md'),

                ImageColumn::make('thumb')
                    ->label('')
                    // A resold listing shows its supplier's photo, as customers see it.
                    ->getStateUsing(fn (Product $record): ?string => $record->catalogueImageUrl('thumb') ?: null)
                    ->defaultImageUrl(self::PLACEHOLDER_IMAGE)
                    ->size(44)
                    ->visibleFrom('md'),

                TextColumn::make('name')
                    ->label('Internal name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (Product $record): ?string => AdminProductsTable::nameSubtitle($record))
                    ->visibleFrom('md'),

                TextColumn::make('online_name')
                    ->label('Online name')
                    ->state(fn (Product $record): ?string => $record->isLinked() ? $record->catalogueSource()?->online_name : $record->online_name)
                    ->placeholder('—')
                    ->searchable()
                    ->wrap()
                    ->visibleFrom('md'),

                TextColumn::make('vendor.name')->label('Vendor')->searchable()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),

                TextColumn::make('category.name')->label('Category')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),

                TextColumn::make('brand')->placeholder('—')->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),

                TextColumn::make('price')->label('Selling price')->money('NGN')->sortable()->visibleFrom('md'),

                TextColumn::make('image_count')
                    ->label('Photos')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => (int) $state === 0 ? 'danger' : 'gray')
                    ->visibleFrom('md'),

                TextColumn::make('vendorlink')
                    ->label('VendorLink')
                    ->state(fn (Product $record): ?string => self::vendorLinkLabel($record))
                    ->placeholder('—')
                    ->color(fn (Product $record): string => $record->isLinked() ? 'info' : 'gray')
                    ->url(fn (Product $record): ?string => $record->isLinked() ? self::supplierRowUrl($record) : null)
                    ->visibleFrom('md'),

                IconColumn::make('visible_online')
                    ->label('Online')
                    ->boolean()
                    ->state(fn (Product $record): bool => AdminProductsTable::isVisibleOnline($record))
                    ->visibleFrom('md'),
            ])
            ->groups([
                Group::make('vendor.name')->label('Vendor')->collapsible(),
            ])
            ->defaultGroup('vendor.name')
            ->defaultSort('name')
            ->filtersTriggerAction(fn (Action $action) => $action->slideOver())
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
                        'linked' => 'Resold listing (linked)',
                        'supplier' => 'Supplier product (has resellers)',
                        'own' => 'Own stock, not resold',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'linked' => $query->linked(),
                        'supplier' => $query->has('resoldListings'),
                        'own' => $query->ownStock()->doesntHave('resoldListings'),
                        default => $query,
                    }),

                TernaryFilter::make('visible_online')
                    ->label('Visible online')
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('products.id', Product::query()->visibleOnline()->select('products.id')),
                        false: fn (Builder $query) => $query->whereNotIn('products.id', Product::query()->visibleOnline()->select('products.id')),
                    ),

                Filter::make('product')
                    ->schema([TextInput::make('id')->label('Product ID')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['id'] ?? null,
                        fn (Builder $q, $id) => $q->whereKey((int) $id),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['id'] ?? null) ? 'Product #'.$data['id'] : null),
            ])
            ->recordActions([
                ActionGroup::make([
                    ManageProductImagesAction::make(),
                    EditOnlineNameAction::make(),
                ])
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->button()
                    ->size('sm')
                    ->color('gray'),
            ]);
    }

    // Where "Images managed on supplier product" sends you: the supplier's row.
    public static function supplierRowUrl(Product $record): string
    {
        return ListProducts::getUrl(['tableFilters' => ['product' => ['id' => $record->source_product_id]]]);
    }

    public static function vendorLinkLabel(Product $record): ?string
    {
        return AdminProductsTable::vendorLinkLabel($record);
    }
}
