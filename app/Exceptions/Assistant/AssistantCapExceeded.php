<?php

declare(strict_types=1);

namespace App\Exceptions\Assistant;

use RuntimeException;
use Throwable;

class AssistantCapExceeded extends RuntimeException
{
    public string $reason;

    public function __construct(
        string $reason,
        string $message,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
