<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Services\VendorLink\LinkedPricing;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia, HasSlug, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'price', 'cost_price', 'stock_quantity', 'status', 'category_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->vendor_id = $this->vendor_id;
    }

    protected $guarded = [];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->slugsShouldBeNoLongerThan(80)
            ->doNotGenerateSlugsOnUpdate()
            ->extraScope(fn ($builder) => $builder->where('vendor_id', $this->vendor_id));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected $casts = [
        'price'           => 'decimal:2',
        'cost_price'      => 'decimal:2',
        'allow_pos_price_override' => 'boolean',
        'specifications'  => 'array',
        'stock_quantity'  => 'integer',
        'reserved_stock'  => 'integer',
        'is_service'      => 'boolean',
        'reorder_point'      => 'integer',
        'preferred_quantity' => 'integer',
        'published_at'    => 'datetime',
        'unpublish_at'    => 'datetime',
    ];

    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published')
              ->where(fn($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
              ->where(fn($q) => $q->whereNull('unpublish_at')->orWhere('unpublish_at', '>', now()));
    }

    // "Published" governs whether a product physically exists/is live at all;
    // these two further gate which sales channel(s) it's actually exposed to.
    /**
     * The supplier's product this listing resells, if it is a linked listing.
     *
     * Price and stock resolve from here live; images and description do not —
     * those are copied once at publish and belong to the reseller thereafter,
     * so the supplier editing his product never mutates a published listing.
     */
    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_product_id');
    }

    /** The link that permits this listing to read its source. */
    public function supplierLink(): BelongsTo
    {
        return $this->belongsTo(SupplierLink::class);
    }

    /** Listings other vendors have published from this product. */
    public function resoldListings(): HasMany
    {
        return $this->hasMany(self::class, 'source_product_id');
    }

    /**
     * Whether this listing is resold from a supplier rather than genuinely
     * stocked. Null source means an ordinary product, which is every row that
     * existed before this feature.
     */
    public function isLinked(): bool
    {
        return $this->source_product_id !== null;
    }

    public function scopeLinked(Builder $query): Builder
    {
        return $query->whereNotNull('source_product_id');
    }

    public function scopeOwnStock(Builder $query): Builder
    {
        return $query->whereNull('source_product_id');
    }

    public function scopeVisibleOnline(Builder $query): void
    {
        $query->published()->where('show_online', true)
              ->whereHas('vendor', fn (Builder $q) => $q->where('online_sales_enabled', true));
    }

    public function scopeVisibleInPos(Builder $query): void
    {
        $query->published()->where('show_in_pos', true);
    }

    /**
     * Units actually available to sell = physical stock minus those held for pending orders.
     *
     * A linked listing holds no stock of its own, so it answers with the
     * supplier's. This is where every PHP reader of stock — the cart, the
     * product page, the checkout guard — resolves it, which is why the
     * interception is here rather than at each of them.
     */
    public function getAvailableStockAttribute(): int
    {
        if ($this->isLinked()) {
            return LinkedPricing::stockFor($this);
        }

        return max(0, $this->stock_quantity - $this->reserved_stock);
    }

    /**
     * What this sells for.
     *
     * A linked listing quotes the supplier's price plus the link's markup, live,
     * so his price change moves the shelf price with no sync and nothing to run.
     * The stored column is a mirror kept for SQL sorting, and is the fallback
     * when the source is gone or the link switched off — a stale real price
     * beats showing a customer zero.
     */
    public function getPriceAttribute($value)
    {
        // castAttribute, not the raw value: defining an accessor BYPASSES the
        // decimal:2 cast on this column, so returning $value straight would
        // quietly change the type every reader has always seen — which is
        // exactly what it did, and what broke the catalogue export's CSV.
        if (! $this->isLinked()) {
            return $this->castAttribute('price', $value);
        }

        $resolved = LinkedPricing::priceFor($this);

        return $this->castAttribute('price', $resolved ?? $value);
    }

    /**
     * Products that can actually be bought right now.
     *
     * Own stock is the column; a linked listing's is the supplier's, which SQL
     * has to reach through the source row — the storefront filters and sorts in
     * the database, so an accessor alone would leave every linked listing out of
     * the catalogue.
     */
    public function scopeInStockForSale(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(function (Builder $own) {
                $own->whereNull('source_product_id')
                    ->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) > 0');
            })->orWhere(function (Builder $linked) {
                $linked->whereNotNull('source_product_id')
                    ->whereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('products as src')
                            ->whereColumn('src.id', 'products.source_product_id')
                            ->whereRaw('CAST(src.stock_quantity AS SIGNED) - CAST(src.reserved_stock AS SIGNED) > 0');
                    })
                    // The arrangement has to still be on. A switched-off link
                    // stops the listing selling, the same as it stops it pricing.
                    ->whereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('supplier_links')
                            ->whereColumn('supplier_links.id', 'products.supplier_link_id')
                            ->where('supplier_links.is_active', true);
                    });
            });
        });
    }

    // Store-scoped counterparts of the three above, for screens that operate
    // inside one store rather than across the whole vendor.
    //
    // They read store_quantity / store_reserved, which ProductResource's
    // store-scoped query selects onto the model from that store's
    // product_store_stock row. When those are absent — every other caller, the
    // POS feed and storefront included — these fall back to the vendor-wide
    // mirror and behave exactly as available_stock always has. Deliberately
    // separate from available_stock so no existing reader changes meaning.
    public function storeQuantity(): int
    {
        return array_key_exists('store_quantity', $this->attributes)
            ? (int) $this->attributes['store_quantity']
            : (int) $this->stock_quantity;
    }

    public function storeReserved(): int
    {
        return array_key_exists('store_reserved', $this->attributes)
            ? (int) $this->attributes['store_reserved']
            : (int) $this->reserved_stock;
    }

    public function storeAvailable(): int
    {
        return max(0, $this->storeQuantity() - $this->storeReserved());
    }

    public function isStoreLowStock(): bool
    {
        $available = $this->storeAvailable();

        return $available > 0 && $available < (int) $this->low_stock_threshold;
    }

    // Single source of truth for the "low stock" boundary — every stock badge
    // across the admin panel and POS reads this instead of a hardcoded number.
    public function getIsLowStockAttribute(): bool
    {
        return $this->available_stock > 0 && $this->available_stock < $this->low_stock_threshold;
    }

    // Profit/margin/markup are always derived from price + cost_price, never stored —
    // null whenever cost_price hasn't been set, rather than faking a number.
    public function getProfitAttribute(): ?float
    {
        if ($this->cost_price === null) return null;

        return (float) $this->price - (float) $this->cost_price;
    }

    public function getMarginPercentAttribute(): ?float
    {
        if ($this->cost_price === null || (float) $this->price <= 0) return null;

        return ($this->profit / (float) $this->price) * 100;
    }

    public function getMarkupPercentAttribute(): ?float
    {
        if ($this->cost_price === null || (float) $this->cost_price <= 0) return null;

        return ($this->profit / (float) $this->cost_price) * 100;
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Who this is bought from. Set on import from the vendor's own file, and
    // nulled rather than cascaded if the supplier record is ever removed.
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    // The one branch this product belongs to. A product lives in exactly one
    // store: it never appears in another store's catalogue, inventory or till.
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    // Per-store stock rows. With a home store this is a single row, held at
    // that store — the quantity, where store() is the identity.
    public function storeStocks()
    {
        return $this->hasMany(ProductStoreStock::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('product-images')
            ->useDisk('public')          // which disk to store on
            ->withResponsiveImages();    // auto-generates srcset sizes (optional but nice)
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 300, 300)
            ->quality(90)
            ->sharpen(10)
            ->nonQueued();

        $this->addMediaConversion('preview')
            ->fit(Fit::Crop, 800, 800)
            ->quality(90)
            ->sharpen(5)
            ->nonQueued();
    }
}
