<?php

use App\Support\Pos\BusinessDate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One shift primitive, not two.
//
// pos_sessions and cash_up_sessions were describing the same thing — a cashier's
// day at a counter — from opposite ends: one knew which sales belonged to it, the
// other knew what the drawer should hold. Two rows for one day is two places for
// the same day to disagree about itself, and the cashier would have met two
// screens asking the same question and answering it differently.
//
// The session becomes the whole shift: opened with a counted float, closed with a
// counted drawer and a terminal reading, reviewed by a manager. The Z-report
// stops being a printout of system sales and becomes the slip that reconciliation
// is actually written on.
//
// business_date is what makes one-per-day possible at all. The table had no date
// of any kind, so nothing could say two sessions were the same day — which is how
// a session in this database stayed open for eighteen days.
//
// store_id stays nullable: sessions predating multi-store legitimately have none,
// and a unique key treats each NULL as distinct, which is the right answer for
// rows that cannot be placed at a branch anyway.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
            $table->date('business_date')->nullable()->after('terminal_id');

            // ── What the cashier counted. Entered blind. ──────────────────────
            $table->decimal('counted_cash', 12, 2)->nullable()->after('closing_float');
            $table->decimal('counted_terminal', 12, 2)->nullable()->after('counted_cash');

            // ── What the server said was owed, snapshotted at close. ──────────
            // Snapshots, not recomputed on read: this is what the system claimed
            // at the moment the cashier was held to it, and a late offline sale
            // must not retroactively turn a clean day into a shortage.
            $table->decimal('expected_cash', 12, 2)->nullable()->after('counted_terminal');
            $table->decimal('expected_terminal', 12, 2)->nullable()->after('expected_cash');
            $table->decimal('cash_variance', 12, 2)->nullable()->after('expected_terminal');
            $table->decimal('terminal_variance', 12, 2)->nullable()->after('cash_variance');

            // The per-tender working that produced those figures, frozen with
            // them — what answers "why doesn't the drawer equal my sales?".
            $table->json('breakdown')->nullable()->after('terminal_variance');

            $table->text('notes')->nullable()->after('breakdown');
            $table->foreignId('reviewed_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            // The offline till retries. The unique key below is the real
            // backstop; these make a replayed request return the same row
            // rather than race it.
            $table->string('open_idempotency_key')->nullable()->unique();
            $table->string('close_idempotency_key')->nullable()->unique();
        });

        // 'closed' is kept for the sessions that already hold it. Nothing writes
        // it any more — a counted day goes to pending_review and then approved.
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->enum('status', ['open', 'closed', 'pending_review', 'approved'])
                ->default('open')
                ->change();
        });

        $this->backfill();

        // One open, one close, per cashier per branch per day. In the database
        // rather than merely checked in the controller, because two retried
        // opens can arrive at once and only this can settle that.
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->unique(['cashier_id', 'store_id', 'business_date'], 'pos_sessions_unique_cashier_day');
        });
    }

    /**
     * Give the sessions that already exist a branch and a day.
     *
     * Done in PHP rather than SQL because the business date is the store's wall
     * clock, not UTC, and CONVERT_TZ is both MySQL-only and dependent on
     * timezone tables that are frequently not loaded.
     *
     * Duplicates are collapsed rather than left to break the unique key: where
     * one cashier somehow has two sessions on one day at one branch, the earlier
     * is marked closed and the latest keeps the day. Nothing is deleted — a
     * session may have sales pointing at it.
     */
    private function backfill(): void
    {
        $defaultStores = DB::table('stores')
            ->where('is_default', true)
            ->orderBy('id')
            ->pluck('id', 'vendor_id');

        $seen = [];

        foreach (DB::table('pos_sessions')->orderBy('id')->get() as $session) {
            $storeId = $session->store_id ?? ($defaultStores[$session->vendor_id] ?? null);
            $date = BusinessDate::of($session->opened_at ?? $session->created_at);

            $key = $session->cashier_id.':'.($storeId ?? 'none').':'.$date;

            if (isset($seen[$key])) {
                // An earlier session for the same day. It cannot keep the date
                // without colliding, and it was never counted, so it is retired
                // with the date left null.
                DB::table('pos_sessions')->where('id', $seen[$key])->update([
                    'business_date' => null,
                    'status'        => 'closed',
                    'closed_at'     => $session->opened_at ?? now(),
                ]);
            }

            DB::table('pos_sessions')->where('id', $session->id)->update([
                'store_id'      => $storeId,
                'business_date' => $date,
            ]);

            $seen[$key] = $session->id;
        }
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropUnique('pos_sessions_unique_cashier_day');
        });

        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'store_id', 'business_date', 'counted_cash', 'counted_terminal',
                'expected_cash', 'expected_terminal', 'cash_variance', 'terminal_variance',
                'breakdown', 'notes', 'reviewed_by', 'reviewed_at',
                'open_idempotency_key', 'close_idempotency_key',
            ]);
        });

        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->enum('status', ['open', 'closed'])->default('open')->change();
        });
    }
};
