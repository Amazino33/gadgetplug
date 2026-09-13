<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTourProgress extends Model
{
    protected $table = 'user_tour_progress';

    protected $guarded = [];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public const STATUS_OFFERED = 'offered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_OFFERED,
        self::STATUS_COMPLETED,
        self::STATUS_DISMISSED,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The tour keys this person has already been shown for this store.
     *
     * All three statuses count. Once someone has been offered a tour we do not
     * ask again, whether they finished it, closed it, or ignored it — they can
     * still start it themselves from the help centre or the page's own button.
     *
     * @return array<int, string>
     */
    public static function seenKeys(int $userId, int $vendorId): array
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('vendor_id', $vendorId)
            ->pluck('tour_key')
            ->all();
    }

    public static function record(int $userId, int $vendorId, string $tourKey, string $status): self
    {
        /** @var self $row */
        $row = static::query()->firstOrNew([
            'user_id' => $userId,
            'vendor_id' => $vendorId,
            'tour_key' => $tourKey,
        ]);

        // 'completed' is the end of the road: someone who walks a tour to the end
        // and later reruns it and closes it half way has still completed it once,
        // and downgrading that row would be a lie about what they have seen.
        if ($row->status !== self::STATUS_COMPLETED) {
            $row->status = $status;
        }

        $row->last_seen_at = now();
        $row->save();

        return $row;
    }
}
