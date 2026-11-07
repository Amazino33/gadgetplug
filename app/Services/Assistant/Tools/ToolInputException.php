<?php

namespace App\Services\Assistant\Tools;

use InvalidArgumentException;

/** The model sent input the tool's schema does not allow. */
final class ToolInputException extends InvalidArgumentException {}
