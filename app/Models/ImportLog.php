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
        'errors'             => 'array',
        'total_rows'         => 'integer',
        'created_count'      => 'integer',
        'updated_count'      => 'integer',
        'skipped_count'      => 'integer',
        'performed_by_admin' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Who the vendor should understand ran this, in their own terms.
     *
     * Platform staff are named as the platform, not as a person. The vendor
     * has no relationship with an individual on our side and no way to check
     * whether "Tolu Adeyemi" is someone who should have touched their
     * catalogue; "GadgetPlug support" is a party they can actually hold to
     * account. The individual is still on the row for us.
     *
     * Falls back rather than failing: user_id is nullOnDelete, and the CLI
     * importer passes no user at all.
     */
    public function actorLabel(): string
    {
        if ($this->performed_by_admin) {
            return config('app.name').' support';
        }

        return $this->user?->name ?? 'System';
    }

    /** Whether this run changed anything at all, however it ended. */
    public function touchedCatalogue(): bool
    {
        return $this->created_count > 0 || $this->updated_count > 0;
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
