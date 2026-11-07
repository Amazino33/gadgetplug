<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every change to a free shop's review status, with who made it and why.
// from_status is null on the first row (the application itself). A "more info"
// request and the vendor's answer to it keep the status at pending, and are
// logged as pending → pending with the note saying what happened.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_status_logs');
    }
};
