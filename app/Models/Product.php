<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'specifications' => 'array',
    ];

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
