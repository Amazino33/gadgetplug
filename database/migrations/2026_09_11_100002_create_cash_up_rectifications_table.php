<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The explained part of a difference. Whatever is left after these is the
// unexplained shortage, and that is the only number worth arguing about.
//
// Append-only, the same discipline as accountability_ledger_entries and
// pos_customer_ledger_entries: a rectification entered wrongly is corrected by
// appending an opposing one, never by editing. These rows are the cashier's
// explanation of missing money, and an explanation that can be quietly rewritten
// afterwards is worth nothing to the person it exonerates.
//
// Every kind reduces to the same arithmetic: money moved off one tender's
// expected figure and onto another's. An expense is cash out with nowhere to go
// (to_tender null); a reclass moves between two real tenders; a debt_paid moves
// off debt, which was never part of either leg, onto a real one. Keeping the
// kinds distinct anyway is for the human reading the review screen, who needs to
// know whether money was spent, handed over, or simply rung on the wrong button.
//
// Deliberately does NOT rewrite the sale it points at. related_sale_id is the
// flag: a sale needing correction is one with a rectification aimed at it. That
// keeps the sale record exactly as it was rung, which is the point of a till
// journal, and keeps the flag derived rather than stored — the same rule every
// balance in this codebase follows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_up_rectifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cash_up_session_id')->constrained('cash_up_sessions')->cascadeOnDelete();

            // Denormalised for tenancy filtering without a join, as everywhere
            // else in the repo.
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            // expense | cash_out | tender_reclass | debt_paid. A string validated
            // by a model constant, not an enum — see the sessions migration.
            $table->string('kind', 20);

            // Always positive. Direction is carried by from_tender/to_tender, not
            // by the sign, so no caller has to remember a convention.
            $table->decimal('amount', 12, 2);

            // Where the money left, and where it actually went. from_tender is
            // 'cash' for an expense or handover and null for nothing; to_tender is
            // null when the money left the shop entirely. 'debt' is a valid
            // from_tender and belongs to neither leg, which is precisely why a
            // debt_paid raises one leg without lowering the other.
            $table->string('from_tender', 20)->nullable();
            $table->string('to_tender', 20)->nullable();

            // The sale this corrects, for reclass and debt_paid. Nullable because
            // an expense corrects no sale. nullOnDelete rather than cascade: if a
            // sale is ever removed the explanation of the money must survive it.
            $table->foreignId('related_sale_id')->nullable()->constrained('pos_sales')->nullOnDelete();

            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // The offline client retries, and these rows have no natural
            // uniqueness — two identical ₦500 transport expenses on one day are
            // both real. A caller-supplied key is the only thing that can tell a
            // retry from a second genuine entry.
            $table->string('idempotency_key')->nullable()->unique();

            // created_at only. There is no update path, so updated_at would hold
            // a permanent duplicate of it.
            $table->timestamp('created_at')->nullable();

            $table->index(['cash_up_session_id', 'kind']);

            // "Which sales are flagged for correction?" — the derived-flag read.
            $table->index('related_sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_up_rectifications');
    }
};
