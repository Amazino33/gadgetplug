<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A vendor's physical locations. The vendor stays the tenant and the Spatie
// team — a store is a place inside it, never a second tenant, so nothing here
// touches vendor_id-based scoping or permissions.
//
// slug is unique per vendor, not globally: two different vendors may each have
// a "Main Store", and forcing one of them to be "main-store-2" would leak the
// existence of the other vendor's store into this one's URLs. Same shape
// products already use (see Product::getSlugOptions()'s extraScope).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            // Deliberately a plain boolean rather than a unique index on
            // (vendor_id, is_default): a partial/filtered unique index is not
            // portable between MySQL and the SQLite test driver, and "unique
            // where true" cannot be expressed as a plain unique. The one-default
            // invariant is enforced by the backfill's assertion and by tests.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
