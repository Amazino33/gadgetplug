<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app tips, "What's new" announcements and the Learn GadgetPlug page.
 *
 * Four tables, one job each:
 *
 *  - app_features: what users READ about a feature. One row per class in
 *    App\Support\Engagement\FeatureRegistry, written by `features:sync`. What a
 *    feature is and how its use is detected lives in code; this row only holds
 *    the words, the video and whether it is listed.
 *  - tips: the pop-ups and announcements admins write.
 *  - tip_events: append-only log of what happened to a tip for a person. Every
 *    piece of per-user state (seen, retired, cooling down, today's pop-up) is
 *    derived from it, so there is no counter anywhere to drift out of step.
 *  - tip_settings: the single row of platform-wide defaults.
 *
 * Portable schema only: the suite runs on SQLite (no raw MySQL DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            // False when the class behind this row has been removed from the
            // registry. Kept rather than deleted so old tip events still name
            // something; never shown to users.
            $table->boolean('is_registered')->default(true);
            // Optional links into the existing Help & Guides page.
            $table->string('help_article_slug')->nullable();
            $table->string('tour_key')->nullable();
            $table->timestamps();
        });

        Schema::create('tips', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('tip'); // tip | announcement
            $table->string('feature_key')->nullable()->index();

            $table->string('title');
            $table->text('message')->nullable();

            $table->string('button_label')->nullable();
            // A named panel route plus parameters, resolved per tenant when
            // shown. button_url is the fallback for anything outside a panel.
            $table->string('button_route')->nullable();
            $table->json('button_params')->nullable();
            $table->string('button_url')->nullable();
            $table->boolean('show_not_now')->default(true);
            $table->string('display_style')->default('sheet'); // sheet | modal

            $table->json('panels');
            $table->json('roles')->nullable();
            $table->string('condition')->default('always');
            $table->unsignedInteger('condition_value')->nullable();
            $table->boolean('hide_if_feature_used')->default(true);
            $table->string('moment')->nullable();
            $table->unsignedInteger('moment_threshold')->nullable();

            $table->string('status')->default('draft'); // draft | active | paused
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('priority')->default(0);
            $table->unsignedInteger('delay_seconds')->default(4);
            $table->string('frequency')->default('once'); // once | times | until_clicked
            $table->unsignedInteger('max_views')->nullable();
            $table->unsignedInteger('cooldown_days')->nullable();
            $table->unsignedInteger('adoption_window_days')->nullable();
            $table->boolean('show_on_form_pages')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type']);
        });

        Schema::create('tip_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tip_id')->nullable()->constrained('tips')->nullOnDelete();
            // Set for Learn-page events (thumbs, video plays) that belong to a
            // feature rather than a tip.
            $table->string('feature_key')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 32);
            $table->string('route')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Eligibility: everything this person did with tips at this vendor.
            $table->index(['user_id', 'vendor_id', 'tip_id']);
            // The daily cap: this person's pop-ups since the start of the day.
            $table->index(['user_id', 'event', 'created_at']);
            // Per-tip report.
            $table->index(['tip_id', 'event', 'user_id']);
            // Learn-page thumbs and plays.
            $table->index(['feature_key', 'user_id', 'event']);
        });

        Schema::create('tip_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('daily_cap')->default(1);
            $table->unsignedInteger('adoption_window_days')->default(7);
            $table->unsignedInteger('cooldown_days')->default(3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tip_events');
        Schema::dropIfExists('tips');
        Schema::dropIfExists('app_features');
        Schema::dropIfExists('tip_settings');
    }
};
