<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Each Save on the phone carries an id the phone made up. A Retry sends the
// same id, so a save that reached the server but whose answer was lost on the
// way back is recognised and answered again — not counted a second time, and
// not met with "already counted, Add or Replace?".
//
// An entry keeps the id of the save that last wrote it; an unknown item, the
// id of the save that recorded it. Unique, so two tries cannot both land.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_entries', function (Blueprint $table) {
            $table->uuid('last_save_id')->nullable()->unique('ste_last_save_id_unique');
        });

        Schema::table('stock_take_unknown_items', function (Blueprint $table) {
            $table->uuid('save_id')->nullable()->unique('stui_save_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_entries', function (Blueprint $table) {
            $table->dropUnique('ste_last_save_id_unique');
            $table->dropColumn('last_save_id');
        });

        Schema::table('stock_take_unknown_items', function (Blueprint $table) {
            $table->dropUnique('stui_save_id_unique');
            $table->dropColumn('save_id');
        });
    }
};
