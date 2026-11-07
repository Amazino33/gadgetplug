<?php

declare(strict_types=1);

return [
    'rates' => [
        'openai/gpt-4o-mini' => [
            'input_micros_per_token' => 0.15,
            'output_micros_per_token' => 0.60,
        ],
        'default' => [
            'input_micros_per_token' => 0.50,
            'output_micros_per_token' => 1.50,
        ],
    ],
];
