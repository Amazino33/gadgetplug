<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        'specifications'  => 'array',
        'stock_quantity'  => 'integer',
        'reserved_stock'  => 'integer',
        'published_at'    => 'datetime',
        'unpublish_at'    => 'datetime',
    ];

    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published')
              ->where(fn($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
              ->where(fn($q) => $q->whereNull('unpublish_at')->orWhere('unpublish_at', '>', now()));
    }

    /**
     * Units actually available to sell = physical stock minus those held for pending orders.
     */
    public function getAvailableStockAttribute(): int
    {
        return max(0, $this->stock_quantity - $this->reserved_stock);
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
