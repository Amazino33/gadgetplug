<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Counters (release 2): people who count stock but have no use for the site.
// Each is a real user underneath (tasks, counts and the audit trail all need
// one) with a made-up address nobody can mail and a password nobody knows;
// they sign in at the shop's QR page with their name and a 6-digit code.
//
//   stock_take_counters      one per counter: the code (hashed), whether the
//                            counter is on, and the lock after wrong codes
//   vendors.stock_take_join_key   the secret in the shop's QR code, so the
//                            sign-in page can't be found by guessing
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_take_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('phone', 30)->nullable();
            $table->string('code_hash');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_signed_in_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'is_active']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->string('stock_take_join_key', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique(['stock_take_join_key']);
            $table->dropColumn('stock_take_join_key');
        });

        Schema::dropIfExists('stock_take_counters');
    }
};
