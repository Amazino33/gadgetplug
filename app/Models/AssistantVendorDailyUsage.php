<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Questions a business has asked the assistant on one Lagos trading day — the
 * counter the per-business daily cap reads (PreflightCheckAction).
 */
class AssistantVendorDailyUsage extends Model
{
    protected $table = 'assistant_vendor_daily_usage';

    protected $fillable = [
        'vendor_id', 'date', 'question_count', 'input_tokens', 'output_tokens',
        'cache_read_tokens', 'cache_write_tokens', 'cost_micros',
    ];

    protected $casts = [
        'date' => 'date',
        'question_count' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'cost_micros' => 'integer',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Questions asked today (the Lagos day) by everyone in this business. */
    public static function questionsToday(int $vendorId): int
    {
        return (int) static::query()
            ->where('vendor_id', $vendorId)
            ->where('date', AssistantDailyUsage::day())
            ->value('question_count');
    }

    /**
     * Counts one question against today's row, with what it cost.
     *
     * @param  array{input_tokens?: int, output_tokens?: int, cache_read_tokens?: int, cache_write_tokens?: int, cost_micros?: int}  $usage
     */
    public static function record(int $vendorId, array $usage): void
    {
        $date = AssistantDailyUsage::day();

        DB::transaction(function () use ($vendorId, $date, $usage) {
            $row = static::query()->where('vendor_id', $vendorId)->where('date', $date)->lockForUpdate()->first();

            if (! $row) {
                try {
                    $row = static::create(['vendor_id' => $vendorId, 'date' => $date]);
                } catch (Throwable) {
                    $row = static::query()->where('vendor_id', $vendorId)->where('date', $date)->lockForUpdate()->firstOrFail();
                }
            }

            $row->increment('question_count');

            foreach (['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_micros'] as $column) {
                $amount = (int) ($usage[$column] ?? 0);

                if ($amount > 0) {
                    $row->increment($column, $amount);
                }
            }
        });
    }
}
