<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vendor's saved column mapping, so a repeat import from the same POS skips
 * the mapping step.
 */
class ImportMappingTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'mapping' => 'array',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How much of this file the template actually covers. A template saved for
     * an Aronium export applied to a Loyverse file would match almost nothing,
     * and the vendor should see that before trusting it.
     *
     * @param  array<int, string>  $headers
     */
    public function coverageOf(array $headers): int
    {
        $mapped = collect($this->mapping ?? [])->keys()->intersect($headers)->count();

        return $headers === [] ? 0 : (int) round($mapped / count($headers) * 100);
    }
}
