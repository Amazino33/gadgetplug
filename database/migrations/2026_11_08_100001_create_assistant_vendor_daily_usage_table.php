<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 1.3, the locked cap decision: 100 questions per business per day, the
 * day being the Lagos trading day (App\Support\Pos\BusinessDate), adjustable
 * per business by the platform admin.
 *
 *  - assistant_global_settings.daily_vendor_cap: the default for every business.
 *  - vendors.assistant_daily_cap: a per-business override (null = the default).
 *  - assistant_vendor_daily_usage: one row per business per Lagos day, the
 *    counter the cap reads, with the tokens (cache included) and cost behind it.
 *
 * The per-user message and cost caps stay as they are, as a backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_global_settings', function (Blueprint $table) {
            $table->unsignedInteger('daily_vendor_cap')->default(100)->after('daily_cost_cap_micros');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedInteger('assistant_daily_cap')->nullable();
        });

        Schema::create('assistant_vendor_daily_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('question_count')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->timestamps();

            $table->unique(['vendor_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_vendor_daily_usage');

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('assistant_daily_cap');
        });

        Schema::table('assistant_global_settings', function (Blueprint $table) {
            $table->dropColumn('daily_vendor_cap');
        });
    }
};
