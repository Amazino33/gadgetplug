<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A vendor's saved column mapping, so the second import from the same POS does
// not repeat the mapping step.
//
// Vendor-scoped rather than global: two vendors can both export from Aronium and
// still have hand-edited their spreadsheets differently, and one vendor's
// mapping is not evidence about another's file.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');

            // Source column header => product field. Stored as given, including
            // case and spacing, because that is what a re-export will contain.
            $table->json('mapping');

            $table->timestamps();

            // One name per vendor: saving over "Aronium export" should replace
            // it, not leave two entries that differ invisibly.
            $table->unique(['vendor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mapping_templates');
    }
};
