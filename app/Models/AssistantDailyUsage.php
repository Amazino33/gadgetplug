<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Pos\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantDailyUsage extends Model
{
    use HasFactory;

    protected $table = 'assistant_daily_usage';

    protected $fillable = [
        'user_id',
        'date',
        'message_count',
        'input_tokens',
        'output_tokens',
        'cost_micros',
    ];

    protected $casts = [
        'date' => 'date',
        'message_count' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_micros' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Today as a usage-row date: the Lagos trading day (BusinessDate), as a
     * date value so it compares like the `date` cast stores it. Every
     * assistant cap counts this day, so they all reset at Lagos midnight.
     */
    public static function day(): CarbonImmutable
    {
        return CarbonImmutable::parse(BusinessDate::today());
    }

    public static function forToday(User $user): self
    {
        try {
            return static::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'date' => AssistantDailyUsage::day(),
                ],
                [
                    'message_count' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'cost_micros' => 0,
                ]
            );
        } catch (\Throwable) {
            return static::query()
                ->where('user_id', $user->id)
                ->where('date', AssistantDailyUsage::day())
                ->firstOrFail();
        }
    }
}
