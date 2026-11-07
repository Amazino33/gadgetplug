<?php

namespace App\Services\Assistant\Tools;

use RuntimeException;

/**
 * The user asked for something outside their access. The message is shown to
 * the model, so it never names or hints at the value being withheld.
 */
final class ToolDenied extends RuntimeException
{
    public static function noAccess(string $message = "You don't have access to that."): self
    {
        return new self($message);
    }
}
