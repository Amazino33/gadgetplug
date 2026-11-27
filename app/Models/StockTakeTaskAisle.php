<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** An aisle on a task; lock_key is set while an open count task holds it. */
class StockTakeTaskAisle extends Pivot
{
    protected $table = 'stock_take_task_aisles';

    public $incrementing = true;

    protected $guarded = [];
}
