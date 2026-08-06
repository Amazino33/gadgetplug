<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Proof screenshot follows Product's InteractsWithMedia convention exactly
// (the only other media-backed model in the app) rather than Procurement's
// raw-string-path pattern.
class AffiliateTaskSubmission extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected $casts = [
        'submitted_at'          => 'datetime',
        'reviewed_at'           => 'datetime',
        'level_progress_value'  => 'decimal:2',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('proof')
            ->useDisk('public')
            ->singleFile();
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 300, 300)
            ->quality(90)
            ->nonQueued();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AffiliateTask::class, 'affiliate_task_id');
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'affiliate_task_submission_id');
    }
}
