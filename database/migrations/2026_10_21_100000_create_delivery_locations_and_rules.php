<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Delivery locations & fees.
//
// states / lgas: every state (36 + FCT) and all 774 LGAs, seeded from
// database/data/nigeria-locations.json by NigeriaLocationsSeeder. One
// approximate reference point per LGA — for straight-line distance and map
// markers only, never routing or boundaries.
//
// delivery_rules: one row per state (the default for its LGAs) plus optional
// LGA overrides (lga_id set). An override beats its state's rule. Uniqueness of
// (state_id, lga_id) is enforced in DeliveryRule, not by an index, because a
// unique index treats every NULL lga_id as distinct on MySQL.
//
// stores gain coordinates: a store with a pin is a delivery hub.
// checkouts gain the foreign keys the checkout-split phase deferred.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 4)->unique();
            // Centroid of the state's LGA points, for map zoom and labels.
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestamps();
        });

        Schema::create('lgas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('wikidata_id', 20)->nullable();
            $table->timestamps();

            $table->unique(['state_id', 'name']);
        });

        Schema::create('delivery_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            // Null = the state's default rule; set = an override for one LGA.
            $table->foreignId('lga_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('mode', 20); // free | fee | not_delivered
            $table->decimal('fee', 12, 2)->nullable();
            // What we expect to pay the logistics company; pre-fills dispatch cost.
            $table->decimal('expected_cost', 12, 2)->nullable();
            $table->string('delivery_days', 60)->nullable();
            $table->timestamps();

            $table->index(['state_id', 'lga_id']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('latitude', 9, 6)->nullable()->after('phone');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('lga_id')->references('id')->on('lgas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropForeign(['state_id']);
            $table->dropForeign(['lga_id']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::dropIfExists('delivery_rules');
        Schema::dropIfExists('lgas');
        Schema::dropIfExists('states');
    }
};
