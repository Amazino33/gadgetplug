<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Branded creative the affiliate shares. v1 surfaces the affiliate's
        // code/link as a caption alongside our image — a rendered watermark/QR
        // burned into the artwork is Prompt 5 and deliberately not built here.
        Schema::create('marketing_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Caption template shown to the affiliate to copy alongside the
            // image. Supports :link and :code placeholders, resolved per
            // affiliate at read time so one row serves everyone.
            $table->text('caption_template')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_materials');
    }
};
