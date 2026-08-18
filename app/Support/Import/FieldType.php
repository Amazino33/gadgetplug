<?php

declare(strict_types=1);

namespace App\Support\Import;

enum FieldType
{
    case Text;
    case Decimal;
    case Integer;
    case Boolean;
}
