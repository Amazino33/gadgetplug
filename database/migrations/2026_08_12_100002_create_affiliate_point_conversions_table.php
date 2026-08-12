<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The single bridge between the two economies. One row per conversion,
        // pairing a Points debit with a wallet credit written through the
        // existing wallet primitive — never a second money path.
        Schema::create('affiliate_point_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('points_spent');

            // Frozen at conversion time. An admin changing the rate later must
            // never restate what an affiliate already converted — same
            // discipline as the frozen level_progress_value and the frozen
            // commission cost/margin split.
            $table->decimal('naira_per_point', 10, 4);
            $table->decimal('amount', 12, 2);

            // Idempotency guard: a double-submitted convert action carries the
            // same key and the unique index rejects the second attempt, so the
            // points can never be spent twice for one intent.
            $table->string('idempotency_key', 64);

            $table->timestamps();

            $table->unique(['affiliate_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_point_conversions');
    }
};
