<?php

declare(strict_types=1);

namespace App\Services\Assistant;

class TokenEstimator
{
    /**
     * Estimates the input token count for messages and tools.
     * Rule of thumb: ~4 characters per token in JSON representation.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     */
    public static function estimateInput(array $messages, array $tools): int
    {
        $messagesJson = json_encode($messages) ?: '';
        $toolsJson = json_encode($tools) ?: '';

        $totalChars = strlen($messagesJson) + strlen($toolsJson);

        return (int) ceil($totalChars / 4);
    }
}
