<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One cashier proving, once a day, that every sale they rang ties out to real
// money in a real place: notes in the drawer, and the total on their own
// Moniepoint terminal.
//
// Keyed on (cashier, store, business_date) rather than on pos_session_id, which
// is the whole reason this table exists rather than pos_z_reports being
// extended. Sales replayed through the offline sync endpoint are written with
// pos_session_id = null (PosSyncController), so anything aggregating by session
// silently omits every sale rung while the till was offline — the exact sales a
// shop most needs reconciled. A cashier, a branch and a date are all recorded on
// every sale by both write paths, so they are what the day can be proved
// against.
//
// pos_z_reports stays as it is: it is the per-session till printout, a different
// artifact answering a different question. Neither replaces the other.
//
// business_date is the store's wall-clock day, resolved through
// config('reporting.timezone') — not the UTC date. A sale at 00:30 Lagos time
// belongs to the day the shopkeeper thinks it does, which is the same rule
// ReportPeriod already applies to every dashboard figure.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_up_sessions', function (Blueprint $table) {
            $table->id();

            // Denormalised from the store, as every other financial table in the
            // repo carries it: tenancy filters and vendor relationships then work
            // off this table alone with no join.
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            // Required, unlike pos_sales.store_id. A reconciliation with no
            // branch cannot be reconciled — there is no drawer to count.
            $table->foreignId('store_id')->constrained();

            $table->foreignId('cashier_id')->constrained('users');

            // The cashier's own Moniepoint terminal. One per cashier (locked
            // decision), so the terminal leg reconciles per person exactly as the
            // cash leg does. Free text to match pos_sessions.terminal_id.
            $table->string('terminal_id')->nullable();

            $table->date('business_date');

            // ── Opening ──────────────────────────────────────────────────────
            // Not nullable and not defaulted. A session cannot open without it,
            // because a closing variance measured against an unknown float is
            // not a variance, it is a guess.
            $table->decimal('opening_float', 12, 2);

            // ── What the cashier counted. Entered blind. ──────────────────────
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('counted_terminal', 12, 2)->nullable();

            // ── What the server said was owed, snapshotted at close. ──────────
            // Snapshots, deliberately, not recomputed on read: this is what the
            // system claimed at the moment the cashier was held to it. A late
            // offline sale syncing tomorrow must not retroactively turn a clean
            // cash-up into a shortage that nobody was ever shown.
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('expected_terminal', 12, 2)->nullable();

            // counted - expected, after rectifications. Stored alongside its
            // inputs so the arithmetic a cashier was shown can always be
            // reproduced, even once the formula changes.
            $table->decimal('cash_variance', 12, 2)->nullable();
            $table->decimal('terminal_variance', 12, 2)->nullable();

            // The per-tender working that produced the expected figures, frozen
            // with them. This is what answers "why doesn't the drawer equal my
            // total sales?" on the review screen without re-running a query
            // against data that has since moved.
            $table->json('breakdown')->nullable();

            // open | pending_review | approved. A string validated by a model
            // constant rather than an enum: adding a status later must not mean
            // rewriting a CHECK constraint under the SQLite test driver — the
            // lesson pos_customer_ledger_entries.direction already records.
            $table->string('status', 20)->default('open');

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            // Approval is non-blocking (locked decision): a cashier keeps selling
            // whether or not yesterday was signed off, so nothing here gates the
            // till.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->text('notes')->nullable();

            // ── Idempotency ──────────────────────────────────────────────────
            // The offline client retries. Open is additionally protected by the
            // unique key below, which is the real backstop; these keys make a
            // replayed request return the same row rather than racing it.
            $table->string('open_idempotency_key')->nullable()->unique();
            $table->string('close_idempotency_key')->nullable()->unique();

            $table->timestamps();

            // One open, one close, per cashier per branch per day (locked
            // decision). Enforced in the database, not merely checked in the
            // controller, because two retried opens can arrive concurrently.
            $table->unique(['cashier_id', 'store_id', 'business_date'], 'cash_up_unique_cashier_day');

            // The manager's queue, and a store's history.
            $table->index(['vendor_id', 'status']);
            $table->index(['store_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_up_sessions');
    }
};
