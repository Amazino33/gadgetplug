<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Records moving a product's stock from one branch to another when its home
// store changes.
//
// MySQL only, and not because "SQLite doesn't enforce ENUM" as a general truth
// — that claim has been copied through this codebase without checking. It
// happens to hold for THIS table: 'reserved', 'dispatched',
// 'reservation_released', 'pos_void' and 'pos_return' were all added to the
// MySQL enum in earlier migrations that skipped SQLite, and the test suite has
// been writing every one of them against SQLite ever since without a single
// rejection. So there is genuinely nothing to widen there.
//
// The consequence worth naming: the SQLite test suite CANNOT catch a value
// missing from the MySQL enum. A new type that skipped this migration would
// pass every test and then fail in production under strict mode. This one was
// verified by running it against the MySQL development database and writing a
// real store_transfer row.
return new class extends Migration
{
    private const TYPES_AFTER = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer'";

    private const TYPES_BEFORE = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type ENUM('.self::TYPES_AFTER.') NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type ENUM('.self::TYPES_BEFORE.') NOT NULL');
    }
};
