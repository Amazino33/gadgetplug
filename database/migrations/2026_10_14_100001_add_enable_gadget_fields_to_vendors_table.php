<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Specifications and serial/IMEI tracking only make sense for a shop that sells
// phones and the like. There is no business-type field on vendors to key off,
// so this is an explicit switch: off for everyone, on for the existing gadget
// shops. The fields are hidden, never deleted, when it is off.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('enable_gadget_fields')->default(false)->after('allow_negative_stock');
        });

        // Existing gadget shops keep what they have. Matched by name because
        // nothing else identifies them; anyone missed can be switched on by hand.
        DB::table('vendors')
            ->whereRaw('LOWER(name) LIKE ?', ['%gadget%'])
            ->update(['enable_gadget_fields' => true]);

        // Any shop already tracking serials keeps seeing that field.
        $using = DB::table('products')->where('track_serial', true)->distinct()->pluck('vendor_id');

        if ($using->isNotEmpty()) {
            DB::table('vendors')->whereIn('id', $using)->update(['enable_gadget_fields' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('enable_gadget_fields');
        });
    }
};
