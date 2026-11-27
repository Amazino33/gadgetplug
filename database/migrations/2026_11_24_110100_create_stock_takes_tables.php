<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stock Take: counting a branch aisle by aisle, in batches over several days,
// while it keeps selling.
//
// stock_takes             one exercise ("November Full Count") at one branch
// stock_take_tasks        a batch of it given to one person: count, recount
//                         or aisle mapping
// stock_take_task_aisles  the aisles a count or mapping task covers
// stock_take_task_products the products a recount covers
// stock_take_entries      one product counted in one aisle, as the counter
//                         entered it
// stock_take_results      one product per task, built on submit; what the
//                         approver decides on
// stock_take_unknown_items barcodes the system does not know
//
// No system quantity is stored on an entry. What the shelf "should" have held
// is worked out from the ledger for the moment of counting (StockAtTime), and
// it can still change after the count — a till sale rung before the count but
// synced hours later belongs before it — so a figure frozen at save time
// would be wrong for exactly the cases this design exists to handle. Results
// carry it, computed when built and again under lock when approved.
//
// Statuses are strings, not ENUMs: a value added later needs no MySQL-only
// ALTER that the SQLite test suite cannot see (see count_adjustment).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            // ST-0001, per vendor. What ledger rows and audit lines cite.
            $table->string('reference', 32);
            $table->string('name', 120);
            $table->date('start_date');
            $table->date('end_date');
            // draft → active (first task assigned) → closed
            $table->string('status', 20)->default('draft');
            // A result needs a recount when it is off by more than either.
            $table->decimal('variance_threshold_percent', 5, 2)->default(10);
            $table->decimal('variance_threshold_value', 12, 2)->default(5000);
            // Uncounted products carried over from a closed stock take.
            $table->foreignId('carried_from_id')->nullable()->constrained('stock_takes')->nullOnDelete();
            // The branch id while draft or active, null once closed. Unique, so
            // a branch has one open stock take at a time even when two managers
            // press Create together — two would let one aisle be counted twice.
            $table->string('open_key', 32)->nullable()->unique('stock_takes_open_key_unique');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'reference'], 'stock_takes_vendor_reference_unique');
            $table->index(['store_id', 'status'], 'stock_takes_store_status_idx');
        });

        Schema::create('stock_take_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            // Null only for a mapping task set outside any stock take.
            $table->foreignId('stock_take_id')->nullable()->constrained()->cascadeOnDelete();
            // count | recount | mapping
            $table->string('type', 16)->default('count');
            // The task a recount re-checks.
            $table->foreignId('parent_task_id')->nullable()->constrained('stock_take_tasks')->nullOnDelete();
            $table->foreignId('assigned_to')->constrained('users');
            $table->date('due_date')->nullable();
            // assigned → in_progress → submitted → approved | partially_approved;
            // or cancelled
            $table->string('status', 24)->default('assigned');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['assigned_to', 'status'], 'stt_assignee_status_idx');
            $table->index(['stock_take_id', 'status'], 'stt_stock_take_status_idx');
        });

        Schema::create('stock_take_task_aisles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aisle_id')->constrained();
            // "{stock_take_id}:{aisle_id}" while the task holds the aisle,
            // null once it lets go. Unique, so one aisle cannot sit in two
            // open count tasks of the same stock take, race or no race.
            $table->string('lock_key', 48)->nullable()->unique('stta_lock_key_unique');
            $table->timestamps();

            $table->unique(['stock_take_task_id', 'aisle_id'], 'stta_task_aisle_unique');
        });

        Schema::create('stock_take_task_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->timestamps();

            $table->unique(['stock_take_task_id', 'product_id'], 'sttp_task_product_unique');
        });

        Schema::create('stock_take_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('stock_take_task_id')->constrained()->cascadeOnDelete();
            // Always where it was found, recounts included, so a product in
            // two places is two entries that add up.
            $table->foreignId('aisle_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('counted_by')->constrained('users');
            // Base units, the same scale as stock.
            $table->decimal('counted_qty_base', 12, 3);
            // As entered: [{"unit_id":3,"unit":"Crate","factor":12,"qty":3}, …]
            $table->json('entry_breakdown')->nullable();
            // Server time of the last save (Add or Replace). Stock at this
            // moment is what the count is compared with.
            $table->timestamp('counted_at');
            // Counted here although the aisle map does not list it here.
            $table->boolean('is_unlisted')->default(false);
            // Confirmed absent at submit ("Not on shelf (0)").
            $table->boolean('is_not_on_shelf')->default(false);
            $table->timestamps();

            $table->unique(['stock_take_task_id', 'aisle_id', 'product_id'], 'ste_task_aisle_product_unique');
            $table->index(['stock_take_task_id', 'product_id'], 'ste_task_product_idx');
        });

        Schema::create('stock_take_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('stock_take_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_take_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            // Sum of the product's entries across the task's aisles.
            $table->decimal('total_counted_base', 12, 3);
            // The product's latest entry in the task: the moment the count is
            // compared at.
            $table->timestamp('counted_at');
            // Stock at counted_at, from the ledger. Worked out when the result
            // is built; worked out again under the stock lock at approval
            // (applied_system_qty), which also catches till sales that synced
            // in between.
            $table->decimal('system_qty_at_count', 12, 3);
            $table->decimal('variance_qty', 12, 3);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('variance_value', 14, 2)->nullable();
            $table->boolean('needs_recount')->default(false);
            // pending | approved | rejected | sent_to_recount
            $table->string('status', 20)->default('pending');
            // The recount task this was sent to, and on a recount's own result,
            // the original it re-checks — the pair shown side by side.
            $table->foreignId('recount_task_id')->nullable()->constrained('stock_take_tasks')->nullOnDelete();
            $table->foreignId('recount_of_id')->nullable()->constrained('stock_take_results')->nullOnDelete();
            // Room for Phase 2 (damaged, expired, theft, wrong unit, …).
            $table->string('variance_reason', 40)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // Required when the owner approves a product they counted.
            $table->text('override_note')->nullable();
            $table->decimal('applied_system_qty', 12, 3)->nullable();
            $table->decimal('applied_adjustment_qty', 12, 3)->nullable();
            $table->foreignId('inventory_ledger_id')->nullable()->constrained('inventory_ledgers')->nullOnDelete();
            $table->foreignId('audit_session_id')->nullable()->constrained('audit_sessions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stock_take_task_id', 'product_id'], 'str_task_product_unique');
            $table->index(['stock_take_id', 'product_id'], 'str_stock_take_product_idx');
            $table->index(['stock_take_id', 'status'], 'str_stock_take_status_idx');
        });

        Schema::create('stock_take_unknown_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            // Null when found while mapping outside any task.
            $table->foreignId('stock_take_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('aisle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('barcode', 64)->nullable();
            $table->string('name_entered', 160);
            $table->string('photo_path')->nullable();
            $table->decimal('qty', 12, 3)->nullable();
            $table->string('unit_entered', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            // open | resolved | ignored
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'stui_store_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_unknown_items');
        Schema::dropIfExists('stock_take_results');
        Schema::dropIfExists('stock_take_entries');
        Schema::dropIfExists('stock_take_task_products');
        Schema::dropIfExists('stock_take_task_aisles');
        Schema::dropIfExists('stock_take_tasks');
        Schema::dropIfExists('stock_takes');
    }
};
