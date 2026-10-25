<?php

use App\Models\LegalPolicy;
use Database\Seeders\LegalPolicySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (LegalPolicy::count() === 0) {
            (new LegalPolicySeeder())->run();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't delete policies on rollback to preserve user edits
    }
};
