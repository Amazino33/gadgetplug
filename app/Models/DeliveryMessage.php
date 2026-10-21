<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryMessage extends Model
{
    /** Lanes, most urgent first. See config/messaging.php. */
    public const P1 = 1;

    public const P2 = 2;

    public const P3 = 3;

    /** Not sent: WhatsApp is paused; goes out when an admin resumes sending. */
    public const STATUS_HELD = 'held';

    /** Not sent on its own: combined into another message to the same number. */
    public const STATUS_MERGED = 'merged';

    public const CATEGORY_TRANSACTIONAL = 'transactional';

    public const CATEGORY_MARKETING = 'marketing';

    protected $guarded = [];

    protected $casts = [
        'provider_response' => 'array',
        'sent_at'           => 'datetime',
    ];

    protected static function booted(): void
    {
        // The lane follows the template: whoever creates the row names the
        // template, and config/messaging.php decides how urgent it is.
        static::creating(function (self $message) {
            if ($message->getAttribute('priority') === null) {
                $message->priority = self::laneFor($message->template_key);
            }

            // One form per number from the start (send() normalised it anyway),
            // so "the same recipient" means the same row value — the throttle
            // and the digest both match on it.
            if (filled($message->to_number) && ! str_contains((string) $message->to_number, '@')) {
                $message->to_number = \App\Services\Messaging\PhoneNumber::toNigerianInternational($message->to_number);
            }
        });
    }

    public static function laneFor(?string $templateKey): int
    {
        return (int) (config('messaging.lanes.'.$templateKey) ?? self::P2);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function isUrgent(): bool
    {
        return (int) $this->priority === self::P1;
    }
}
