<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A reason for stock that was never missing.
//
// The first count of a branch that has never been stocked reads as a variance
// against zero on every line — 151 of them at Itel Home. Filing those under
// "Data Entry Error" or "Suspected Theft" would put a fictional loss into the
// shrinkage reports on the branch's first day, and every reading of those
// reports afterwards would carry it.
//
// MySQL-only because the column is an ENUM there and MySQL rejects a value
// outside the list under strict mode. SQLite — the test-suite driver — stores
// this column as plain TEXT with no CHECK constraint attached (Laravel only
// emits one for enums it creates in a fresh table, not for a column added to
// an existing one), so there is genuinely nothing to widen on that driver.
// This is NOT the general claim that SQLite ignores enums; it is specific to
// how this column was created, and it is why the test suite cannot prove this
// migration is needed. Verified by writing an 'Opening Stock Count' row
// against the MySQL development database.
return new class extends Migration
{
    private const VALUES = [
        'Damaged in Store',
        'Suspected Theft',
        'Waybill Shortage',
        'Data Entry Error',
        'Supplier Short Delivery',
        'Opening Stock Count',
        'Other',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement($this->alterTo(self::VALUES));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Rows already filed under the new reason would violate the narrowed
        // column, so they are moved to 'Other' first — the only value that
        // does not assert something untrue about them.
        DB::table('audit_sessions')
            ->where('reason_code', 'Opening Stock Count')
            ->update(['reason_code' => 'Other']);

        DB::statement($this->alterTo(array_values(array_diff(self::VALUES, ['Opening Stock Count']))));
    }

    private function alterTo(array $values): string
    {
        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));

        return "ALTER TABLE audit_sessions MODIFY COLUMN reason_code ENUM({$list}) NULL";
    }
};
