<?php

declare(strict_types=1);

namespace App\Exceptions\Assistant;

use RuntimeException;
use Throwable;

class AiProviderException extends RuntimeException
{
    public string $failureCategory;

    public function __construct(
        string $message = '',
        string $failureCategory = 'unknown',
        int $code = 0,
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
