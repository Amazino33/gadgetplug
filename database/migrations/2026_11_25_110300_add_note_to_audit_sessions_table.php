<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A note on an audit line. Stock Take writes one when the owner approves a
// product they counted themselves — the one case where counting and approving
// are allowed to be the same person, so the line says why. Null on every
// other line.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
