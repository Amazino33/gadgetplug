<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The free plan: shops that sign themselves up at /become-a-vendor and wait
// for review. Every column defaults to how a vendor behaves today — plan
// 'paid', status 'approved' — so the shops that already exist carry on exactly
// as before and only a self-registered shop is ever 'free'.
//
// Slug, whatsapp, city, state, description and logo already exist on vendors
// and are reused as they are: the slug is the shop link, city holds the
// city/area. Each column below is added only if it is missing, so a database
// that picked one up some other way is not broken by this.
return new class extends Migration
{
    public function up(): void
    {
        $add = fn (string $column) => ! Schema::hasColumn('vendors', $column);

        Schema::table('vendors', function (Blueprint $table) use ($add) {
            if ($add('plan')) {
                $table->enum('plan', ['free', 'paid'])->default('paid');
            }
            if ($add('status')) {
                $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('approved');
            }
            if ($add('category')) {
                $table->string('category')->nullable();
            }
            if ($add('verification_photo')) {
                $table->string('verification_photo')->nullable();
            }
            if ($add('social_link')) {
                $table->string('social_link')->nullable();
            }
            if ($add('review_note')) {
                $table->text('review_note')->nullable();
            }
            if ($add('info_requested')) {
                $table->boolean('info_requested')->default(false);
            }
            if ($add('applied_at')) {
                $table->timestamp('applied_at')->nullable();
            }
            if ($add('approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if ($add('approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->index(['plan', 'status'], 'vendors_plan_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex('vendors_plan_status_index');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'plan', 'status', 'category', 'verification_photo', 'social_link',
                'review_note', 'info_requested', 'applied_at', 'approved_at',
            ]);
        });
    }
};
