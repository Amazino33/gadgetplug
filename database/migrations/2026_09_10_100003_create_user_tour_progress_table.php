<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whether this person has already been offered a given tour.
//
// Its own table, in the same spirit as vendor_notification_settings and
// vendor_receipt_settings, but keyed per *user* as well as per vendor. A store
// is a team: the owner having waved away the procurement tour is no reason for
// a storekeeper hired next month to never be shown it. Per-vendor would have
// silenced it for everybody at once.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tour_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            // Key from App\Support\Tours\TourRegistry, e.g. 'record-procurement'.
            $table->string('tour_key');

            // offered   — auto-shown once; do not offer again
            // completed — walked to the end
            // dismissed — closed part-way
            //
            // Only 'offered' distinguishes "we already asked" from "never asked";
            // the auto-offer treats all three the same and stays quiet.
            $table->enum('status', ['offered', 'completed', 'dismissed'])->default('offered');

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'vendor_id', 'tour_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tour_progress');
    }
};
