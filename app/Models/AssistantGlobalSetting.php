<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantGlobalSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_enabled',
        'history_window',
        'model_name',
        'max_tokens',
        'daily_message_cap',
        'daily_cost_cap_micros',
        'daily_vendor_cap',
    ];

    protected $casts = [
        'daily_vendor_cap' => 'integer',
        'is_enabled' => 'boolean',
        'history_window' => 'integer',
        'model_name' => 'string',
        'max_tokens' => 'integer',
        'daily_message_cap' => 'integer',
        'daily_cost_cap_micros' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'is_enabled' => true,
            'history_window' => 20,
            'model_name' => 'openai/gpt-4o-mini',
            'max_tokens' => 800,
            'daily_message_cap' => 100,
            'daily_cost_cap_micros' => 200000,
            'daily_vendor_cap' => 100,
        ]);
    }
}
