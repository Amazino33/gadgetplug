<?php

declare(strict_types=1);

namespace App\Exceptions\Assistant;

use InvalidArgumentException;

/** The model asked for a period or a date the assistant cannot read. */
class InvalidPeriod extends InvalidArgumentException {}
