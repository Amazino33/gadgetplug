<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A handover no longer has to name its receiver up front.
//
// It used to, and that was right when the only way to record one was for the
// submitter to pick somebody from a list. With a QR the receiver is whoever is
// standing there with the authority to take it — an owner one day, a named
// collector the next — and forcing a guess at submission time either sends the
// storekeeper hunting for the right name or gets the wrong one recorded.
//
// The guarantee is unchanged: two different people still end up on every
// settled handover. It is now established by the receiver scanning the code in
// person and being permitted to receive cash at that branch, rather than by the
// submitter naming them in advance. Nominating somebody is still allowed, and
// when it happens only that person can answer for it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_submissions', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cash_submissions', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable(false)->change();
        });
    }
};
