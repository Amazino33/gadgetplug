<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// "POS" is the card machine in Nigeria, and the till is now the Sales Point,
// so the daily summary's "POS total" line becomes "Shop total".
//
// Only that one line, in each vendor's own copy. Templates are per vendor and
// editable, so rewriting the whole body (messages:sync-templates) would wipe
// whatever a vendor had changed. A vendor who already reworded the line is
// left alone: the old text simply is not there to replace.
return new class extends Migration
{
    private const OLD = '• POS total: {{pos_taken}}';

    private const NEW = '• Shop total: {{pos_taken}}';

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    // Done in PHP rather than SQL REPLACE() so it reads the same on MySQL and
    // on the SQLite the test suite runs.
    private function swap(string $from, string $to): void
    {
        DB::table('message_templates')
            ->where('key', 'vendor_daily_summary')
            ->where('body', 'like', '%'.$from.'%')
            ->get(['id', 'body'])
            ->each(fn ($row) => DB::table('message_templates')
                ->where('id', $row->id)
                ->update(['body' => str_replace($from, $to, $row->body)]));
    }
};
