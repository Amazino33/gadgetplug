<?php

declare(strict_types=1);

return [
    // Phase 1.4 comparison only (assistant:compare sets these at run time).
    'model_override' => env('ASSISTANT_MODEL_OVERRIDE'),
    'prompt_variant' => null,

    'rates' => [
        'openai/gpt-4o-mini' => [
            'input_micros_per_token' => 0.15,
            'output_micros_per_token' => 0.60,
        ],
        // OpenRouter prices, 2026-11-10 (micro-USD per token = USD per million / 1). Only the
        // fallback: when OpenRouter reports what it billed, that figure is used instead.
        'anthropic/claude-haiku-5.5' => [
            'input_micros_per_token' => 0.10,
            'output_micros_per_token' => 0.50,
        ],
        'anthropic/claude-sonnet-5.5' => [
            'input_micros_per_token' => 2.00,
            'output_micros_per_token' => 10.00,
        ],
        'default' => [
            'input_micros_per_token' => 0.50,
            'output_micros_per_token' => 1.50,
        ],
    ],
];
