<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Product;
use App\Models\SupplierLink;
use App\Services\VendorLink\Rounding\RoundingRules;
use App\Services\VendorLink\SupplierCatalogue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use UnitEnum;

/**
 * Browse a supplier's live catalogue and publish listings from it.
 *
 * A Page rather than a Resource, and that is the whole safety argument: the
 * table queries the SUPPLIER's products, which belong to a different tenant.
 * Filament's tenancy scoping applies to resources, so a resource here would
 * either fight the scope or have to switch it off — and switching it off on a
 * product resource is exactly the kind of blanket hole this feature must not
 * open. A page holds its own query, and that query is filtered by an active
 * link or it returns nothing at all.
 */
class VendorLinkPage extends Page implements HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-link';
    protected static string|null|UnitEnum   $navigationGroup = 'Products';
    protected static ?string                $navigationLabel = 'VendorLink';
    protected static ?string                $title           = 'VendorLink — sell from a supplier';
    protected static ?int                   $navigationSort  = 6;

    protected string $view = 'filament.vendor.pages.vendor-link';

    /** Which linked supplier's catalogue is on screen. */
    public ?int $supplierLinkId = null;

    public function mount(): void
    {
        $this->supplierLinkId = $this->availableLinks()->keys()->first();
    }

    /**
     * The links this vendor may sell through.
     *
     * Only ever links where this vendor is the RESELLER. A vendor must never
     * see, let alone publish from, a link somebody else was granted.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function availableLinks(): \Illuminate\Support\Collection
    {
        $vendor = filament()->getTenant();

        return SupplierLink::forReseller($vendor->id)
            ->active()
            ->with('supplier')
            ->get()
            ->mapWithKeys(fn (SupplierLink $link) => [$link->id => $link->supplier?->name ?? 'Unknown supplier']);
    }

    public function currentLink(): ?SupplierLink
    {
        if (! $this->supplierLinkId) {
            return null;
        }

        // Re-checked against this tenant on every read rather than trusted from
        // the property: the id arrives from the browser, and a hand-edited one
        // must not reach another vendor's catalogue.
        return SupplierLink::forReseller(filament()->getTenant()->id)
            ->active()
            ->find($this->supplierLinkId);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->catalogueQuery())
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('')
                    ->state(fn (Product $record) => $record->getFirstMediaUrl('product-images', 'thumb') ?: null)
                    ->circular(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->description(fn (Product $record) => $record->brand),

                Tables\Columns\TextColumn::make('price')
                    ->label('Supplier price')
                    ->money('NGN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('retail')
                    ->label('Your price')
                    ->state(fn (Product $record) => $this->retailFor($record))
                    ->money('NGN')
                    ->weight('bold')
                    ->color('success')
                    ->description(fn () => $this->currentLink()
                        ? $this->currentLink()->markup_percent.'% markup'
                        : null),

                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label('His stock')
                    ->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'danger')
                    ->sortable(),

                Tables\Columns\IconColumn::make('published')
                    ->label('Published')
                    ->boolean()
                    ->state(fn (Product $record) => $this->publishedSourceIds()->contains($record->id)),
            ])
            ->filters([
                Tables\Filters\Filter::make('in_stock')
                    ->label('In stock only')
                    ->query(fn (Builder $q) => $q->where('stock_quantity', '>', 0)),

                Tables\Filters\Filter::make('unpublished')
                    ->label('Not yet published')
                    ->query(fn (Builder $q) => $q->whereNotIn('id', $this->publishedSourceIds()->all())),
            ])
            // Select-all across the page, then untick what is not wanted —
            // publishing a catalogue one row at a time is the thing this screen
            // exists to avoid.
            ->selectable()
            ->toolbarActions([
                BulkAction::make('publish')
                    ->label('Publish to my store')
                    ->icon('heroicon-o-arrow-up-on-square')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Publish these to your storefront')
                    ->modalDescription('Their pictures and description are copied to you and are yours to edit. Price and stock keep following the supplier.')
                    ->action(function ($records) {
                        $link = $this->currentLink();

                        if (! $link) {
                            Notification::make()->title('Choose a supplier first.')->danger()->send();

                            return;
                        }

                        try {
                            $result = app(PublishLinkedListingAction::class)
                                ->execute($link, $records->pluck('id')->all());
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title($result['published'].' published, '.$result['updated'].' already yours')
                            ->body($result['skipped'] > 0 ? $result['skipped'].' were no longer in his catalogue.' : null)
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('Nothing to sell from here')
            ->emptyStateDescription('Either no supplier is linked to you yet, or the one selected has no products. An admin creates the link.');
    }

    /**
     * The supplier's catalogue, read live.
     *
     * Returns nothing at all when there is no usable link — the query is
     * constrained by the link, so an absent one cannot fall back to "everything".
     */
    protected function catalogueQuery(): Builder
    {
        $link = $this->currentLink();

        if (! $link) {
            return Product::query()->whereRaw('1 = 0');
        }

        return SupplierCatalogue::query($link)
            ->with('media')
            ->orderBy('name');
    }

    /** Source products this vendor has already published from the current link. */
    public function publishedSourceIds(): \Illuminate\Support\Collection
    {
        $link = $this->currentLink();

        if (! $link) {
            return collect();
        }

        return Product::where('supplier_link_id', $link->id)
            ->whereNotNull('source_product_id')
            ->pluck('source_product_id');
    }

    public function retailFor(Product $source): float
    {
        $link = $this->currentLink();

        if (! $link) {
            return 0.0;
        }

        $marked = (float) $source->price * (1 + ((float) $link->markup_percent / 100));

        return RoundingRules::make($link->rounding_rule)->apply($marked);
    }

    /**
     * Only the reseller vendor's owner.
     *
     * Publishing decides what the shop sells and at what price, and the markup
     * behind it is a commercial arrangement — not a counter task. Enforced here
     * rather than by hiding the nav item, which a URL walks straight past.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && ($user->isSuperAdmin() || $vendor->isOwner($user));
    }

    /** Hidden entirely from a vendor nobody has linked a supplier to. */
    public static function shouldRegisterNavigation(): bool
    {
        if (! static::canAccess()) {
            return false;
        }

        return SupplierLink::forReseller(filament()->getTenant()->id)->active()->exists();
    }
}
