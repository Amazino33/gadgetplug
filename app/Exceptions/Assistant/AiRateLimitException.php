<?php

declare(strict_types=1);

namespace App\Exceptions\Assistant;

use RuntimeException;
use Throwable;

class AiRateLimitException extends RuntimeException
{
    public string $failureCategory = 'rate_limited';

    public function __construct(
        string $message = 'AI rate limit exceeded.',
        string $failureCategory = 'rate_limited',
        int $code = 429,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->failureCategory = $failureCategory;
    }

    public function getFailureCategory(): string
    {
        return $this->failureCategory;
    }
}
