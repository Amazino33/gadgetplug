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
        Schema::create('audit_sessions', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            
            // Storekeeper A (Required)
            $table->unsignedBigInteger('storekeeper_a_id');
            $table->foreign('storekeeper_a_id')->references('id')->on('users');
            $table->integer('count_a');
            
            // Storekeeper B (Explicitly Nullable)
            $table->unsignedBigInteger('storekeeper_b_id')->nullable();
            $table->foreign('storekeeper_b_id')->references('id')->on('users')->nullOnDelete();
            $table->integer('count_b')->nullable();
            
            // Manager (Explicitly Nullable)
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
            $table->integer('manager_override_count')->nullable();
            
            // The State of the Audit
            $table->enum('status', [
                'pending',
                'verified',
                'discrepancy',
                'resolved_by_override'
            ])->default('pending');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_sessions');
    }
};
