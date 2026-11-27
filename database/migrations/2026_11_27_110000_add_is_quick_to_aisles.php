<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Quick count: a count task with no aisles to set up. The system makes a private
// area for the task (named after the person and the day) so two people can
// count at once without sharing an aisle. These areas are not real aisles:
// they stay out of the Aisles screen, mapping and every aisle picker.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aisles', function (Blueprint $table) {
            $table->boolean('is_quick')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('aisles', function (Blueprint $table) {
            $table->dropColumn('is_quick');
        });
    }
};
