<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Debt becomes a tender the till can take, alongside cash, card and transfer.
//
// Both columns need it. pos_sales.payment_method carries it for a sale paid
// entirely on credit, and pos_sale_payments.method for the debt slice of a
// mixed sale — and, per the build's rule that any sale containing debt writes
// per-tender rows, for the debt-only case as well.
//
// Uses ->change() rather than raw ALTER on either driver: Laravel's SQLite
// grammar emits a real CHECK constraint for enum(), so a MySQL-only fix would
// leave every debt sale failing in the test suite — which is exactly what
// happened when 'split' was added and had to be repaired afterwards.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'split', 'debt'])->change();
        });

        Schema::table('pos_sale_payments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'card', 'bank_transfer', 'debt'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'split'])->change();
        });

        Schema::table('pos_sale_payments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'card', 'bank_transfer'])->change();
        });
    }
};
