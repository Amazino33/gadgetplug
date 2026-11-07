<?php

declare(strict_types=1);

namespace App\Models;

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

    public static function forToday(User $user): self
    {
        try {
            return static::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'date' => today(),
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
                ->where('date', today())
                ->firstOrFail();
        }
    }
}
