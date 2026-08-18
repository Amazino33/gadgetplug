<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one import attempt.
 *
 * Kept whether the run succeeded or failed, because "it failed and changed
 * nothing" is exactly the answer somebody needs when a catalogue looks wrong.
 */
class ImportLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'errors'        => 'array',
        'total_rows'    => 'integer',
        'created_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function summary(): string
    {
        return sprintf(
            '%d new, %d updated, %d skipped',
            $this->created_count,
            $this->updated_count,
            $this->skipped_count,
        );
    }

    public function hasSnapshot(): bool
    {
        return filled($this->snapshot_path) && is_readable((string) $this->snapshot_path);
    }
}
