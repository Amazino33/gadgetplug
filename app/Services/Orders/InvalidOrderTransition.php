<?php

namespace App\Services\Orders;

use RuntimeException;

/** A status change that isn't allowed from where the order is now; the message is fit to show. */
class InvalidOrderTransition extends RuntimeException
{
}
