<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How a shop wants its product SKUs made.
//
//   category_name  ALC-CASTLI-0042   (what every shop has had until now)
//   serial         1, 2, 3 ... 8226  (continues from the highest number held)
//   prefix_serial  VWD-8226
//   manual         the shop types every SKU itself
//
// Everyone starts on category_name: nothing changes for a shop until its owner
// picks something else.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('sku_style', 20)->default('category_name')->after('enable_gadget_fields');
            $table->string('sku_prefix', 8)->nullable()->after('sku_style');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['sku_style', 'sku_prefix']);
        });
    }
};
