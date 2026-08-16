<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which team members may operate which store. Membership of the vendor
// (vendor_users) stays what it is — this is a narrower grant inside it, and it
// carries no role: role lives only in Spatie's model_has_roles, keyed by the
// vendor team id, exactly as it did after the role column was dropped from
// vendor_users.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_user');
    }
};
