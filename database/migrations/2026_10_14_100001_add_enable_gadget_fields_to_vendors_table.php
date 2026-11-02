<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Specifications and serial/IMEI tracking. Every shop on GadgetPlug sells
// phones and the like, so the switch is on for every vendor, existing and new;
// a vendor that does not need them can have them turned off. The fields are
// hidden, never deleted, when it is off.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('enable_gadget_fields')->default(true)->after('allow_negative_stock');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('enable_gadget_fields');
        });
    }
};
