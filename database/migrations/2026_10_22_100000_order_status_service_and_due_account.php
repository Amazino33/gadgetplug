<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Status service and money decoupling.
//
// orders.status and financial_accounts.type stop being ENUMs and become plain
// strings — the same move delivery_messages.recipient_type made: a schema
// change that widens identically on MySQL and the SQLite test database, with
// the allowed values enforced in code (OrderStatusService, FinancialAccount).
// That is what lets the new 'packed' status and the 'gadgetplug_due' account
// type exist without MySQL-only DDL.
//
// orders gains a timeline: who packed, handed over, delivered, collected the
// money for, or cancelled each order, and when. Delivered by override records
// its reason. stale_alerted_at stops the unpaid-POD alert repeating.
//
// vendor_payouts.funded_from separates requests made under the new rule (drawn
// on the collected "Due from GadgetPlug" balance) from the existing ones, which
// are left exactly as they are.
//
// No ledger entry is moved: revenue already posted to vendors' own cash/bank
// accounts stays there. Only money recognised after this point goes to Due.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();

            $table->timestamp('packed_at')->nullable();
            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivered_override_reason', 500)->nullable();
            $table->timestamp('payment_collected_at')->nullable();
            $table->foreignId('payment_collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('stale_alerted_at')->nullable();
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->string('type', 30)->change();
        });

        Schema::table('vendor_payouts', function (Blueprint $table) {
            // 'legacy' = requested before this change (untouched);
            // 'gadgetplug_due' = drawn on the collected Due from GadgetPlug balance.
            $table->string('funded_from', 30)->default('legacy');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_payouts', function (Blueprint $table) {
            $table->dropColumn('funded_from');
        });

        Schema::table('orders', function (Blueprint $table) {
            foreach (['packed_by', 'handed_over_by', 'delivered_by', 'payment_collected_by', 'cancelled_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['packed_at', 'handed_over_at', 'delivered_at', 'delivered_override_reason',
                'payment_collected_at', 'cancelled_at', 'cancel_reason', 'stale_alerted_at']);
        });
        // status / type stay strings: narrowing back to an ENUM would fail on
        // any 'packed' order or 'gadgetplug_due' account written since.
    }
};
