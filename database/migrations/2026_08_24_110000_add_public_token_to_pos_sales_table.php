<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Addresses a sale's customer-facing copy without exposing its id.
//
// The QR on the paper has to open without a login, so the URL itself is the
// secret. Sequential ids would let anyone walk the whole day's takings by
// counting upward, which is why this is a random token rather than the id.
//
// 16 base62 characters is roughly 4.7e28 possibilities — unguessable, while
// staying short enough to keep the printed QR coarse and easy to scan off a
// thermal print, which a longer URL would not.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique()->after('reference');

            // Claiming is recorded against the sale, so refreshing or forwarding
            // the link cannot stamp the same purchase twice.
            $table->timestamp('loyalty_claimed_at')->nullable()->after('public_token');
        });

        // Existing sales get one too, so old receipts can still be reprinted
        // with a working QR.
        DB::table('pos_sales')->whereNull('public_token')->orderBy('id')
            ->chunkById(500, function ($sales) {
                foreach ($sales as $sale) {
                    DB::table('pos_sales')
                        ->where('id', $sale->id)
                        ->update(['public_token' => Str::random(16)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'loyalty_claimed_at']);
        });
    }
};
