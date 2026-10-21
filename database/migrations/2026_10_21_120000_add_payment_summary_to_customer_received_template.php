<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// The customer's order confirmation (customer_received, platform-locked) now
// shows items, the delivery fee and the total to pay / total paid, through the
// {{payment_summary}} placeholder.
//
// Vendors' stored copies of the template still say "*Total:* {{total}}" and
// would keep showing the items-only total, so that line is swapped for the
// placeholder in place. A copy that no longer contains the line (edited before
// the template was locked) is left exactly as it is and counted in the log,
// rather than overwritten.
return new class extends Migration
{
    private const OLD = '*Total:* {{total}}';

    private const NEW = '{{payment_summary}}';

    public function up(): void
    {
        $rows = DB::table('message_templates')->where('key', 'customer_received')->get(['id', 'body']);

        $updated = 0;
        $custom = 0;

        foreach ($rows as $row) {
            if (str_contains((string) $row->body, self::NEW)) {
                continue;
            }

            if (! str_contains((string) $row->body, self::OLD)) {
                $custom++;
                continue;
            }

            DB::table('message_templates')->where('id', $row->id)->update([
                'body' => str_replace(self::OLD, self::NEW, (string) $row->body),
            ]);
            $updated++;
        }

        $line = "customer_received: {$updated} stored template(s) now show the delivery fee; {$custom} customised copy/copies left unchanged.";
        Log::info($line);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDOUT, '  '.$line.PHP_EOL);
        }
    }

    public function down(): void
    {
        DB::table('message_templates')->where('key', 'customer_received')->get(['id', 'body'])
            ->each(fn ($row) => DB::table('message_templates')->where('id', $row->id)->update([
                'body' => str_replace(self::NEW, self::OLD, (string) $row->body),
            ]));
    }
};
