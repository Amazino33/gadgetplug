<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('target_group')->default('all'); // all, users, vendors, specific_roles
            $table->json('target_roles')->nullable();
            $table->string('target_path')->nullable();
            $table->string('action_text')->nullable();
            $table->string('action_url')->nullable();
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('requires_action')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('guest_views')->default(0); // for tracking unauthenticated views if needed
            $table->unsignedInteger('guest_clicks')->default(0); // for tracking unauthenticated clicks if needed
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_announcements');
    }
};
