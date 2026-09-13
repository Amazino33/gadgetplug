<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_category_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('excerpt', 500)->nullable();

            // Sanitised HTML produced by Filament's RichEditor. Images inside it
            // are media-library attachments on the 'help-article-media'
            // collection, so the body and its pictures are saved together.
            $table->longText('body')->nullable();

            // Optional link to a tour defined in App\Support\Tours\TourRegistry.
            // This is what lets the help centre put a "Start tour" button next to
            // the article that describes the same flow, without a second table
            // mapping one to the other.
            $table->string('tour_key')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'help_category_id', 'sort_order']);
        });

        // Search index, MySQL only.
        //
        // SQLite is the test driver and has no FULLTEXT; issuing this DDL
        // unguarded aborts the migration and takes the whole suite down with it.
        // HelpArticle::search() checks the same driver and falls back to LIKE,
        // so the search path is genuinely exercised by the tests rather than
        // skipped.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE help_articles ADD FULLTEXT help_articles_search (title, excerpt, body)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
    }
};
