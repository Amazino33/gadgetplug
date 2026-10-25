<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateApplication extends Model
{
    public const CATEGORIES = [
        'student'          => 'University student',
        'stay_at_home_mum' => 'Stay-at-home mum',
        'nysc'             => 'NYSC',
        'business_owner'   => 'Business owner / hustler',
        'other'            => 'Other',
    ];

    public const PLATFORMS = [
        'whatsapp'  => 'WhatsApp',
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
        'tiktok'    => 'TikTok',
        'x'         => 'X (Twitter)',
        'snapchat'  => 'Snapchat',
    ];

    public const AUDIENCE_SIZES = [
        'under_100' => 'Under 100',
        '100-500'   => '100–500',
        '500-1000'  => '500–1,000',
        '1000-5000' => '1,000–5,000',
        '5000_plus' => '5,000+',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'platforms'    => 'array',
            'consent'      => 'boolean',
            'consented_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function getConsentAtAttribute(): mixed
    {
        return $this->consented_at;
    }

    public function getConsentTimestampAttribute(): mixed
    {
        return $this->consented_at;
    }

    public function setConsentAtAttribute($value): void
    {
        $this->attributes['consented_at'] = $value;
    }
}
