<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_applications', function (Blueprint $table) {
            $table->string('category')->nullable()->after('whatsapp');
            $table->string('school')->nullable()->after('category');
            $table->json('platforms')->nullable()->after('school');
            $table->string('audience_size')->nullable()->after('platforms');
            $table->boolean('consent')->default(false)->after('admin_notes');
            $table->timestamp('consented_at')->nullable()->after('consent');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_applications', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'school',
                'platforms',
                'audience_size',
                'consent',
                'consented_at',
            ]);
        });
    }
};
