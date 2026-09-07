<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// What the feed needs on a product row.
//
// like_count and share_count are a derived cache, not the truth — the truth is
// product_interactions, and a reconcile command rebuilds these from it. They
// exist so a feed page of twenty posts does not run twenty COUNT() queries. No
// save_count: saves are private and never shown on a post, so counting them
// would only invite showing them.
//
// feed_bucket is what makes the feed feel fresh without making it random. A
// stable small integer per product, assigned once: a session picks a starting
// bucket, reads from there to the end, then wraps around to the start. Every
// visit begins somewhere different, while the order within a session stays
// fixed — which is what lets a cursor page through it without repeating or
// skipping a post as new products arrive mid-scroll.
//
// Deliberately not (feed_bucket + seed) % 100 in the ORDER BY: a runtime
// expression cannot use an index, so every page would filesort the whole
// catalogue. Reading a plain range twice uses the index both times.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('like_count')->default(0)->after('stock_quantity');
            $table->unsignedInteger('share_count')->default(0)->after('like_count');
            $table->unsignedTinyInteger('feed_bucket')->default(0)->after('share_count');
        });

        // Existing rows get a bucket now; new ones are assigned by the model.
        // Random once, then stable forever, which is the whole point of storing
        // it rather than computing it per query.
        //
        // Spelled per driver because the function differs: the suite runs on
        // SQLite and production on MySQL, so a MySQL-only RAND() here would
        // pass nothing and fail everything.
        DB::table('products')->update([
            'feed_bucket' => DB::raw(
                DB::getDriverName() === 'sqlite'
                    ? 'ABS(RANDOM() % 100)'
                    : 'FLOOR(RAND() * 100)'
            ),
        ]);

        Schema::table('products', function (Blueprint $table) {
            // The feed's keyset order, exactly: bucket, then newest first.
            $table->index(['feed_bucket', 'published_at', 'id'], 'products_feed_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_feed_order_index');
            $table->dropColumn(['like_count', 'share_count', 'feed_bucket']);
        });
    }
};
