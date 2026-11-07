<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->timestamp('flagged_at')->nullable()->after('content');
            $table->foreignId('flagged_by')->nullable()->after('flagged_at')->constrained('users')->nullOnDelete();
            $table->string('flag_note', 280)->nullable()->after('flagged_by');

            $table->index('flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropIndex(['flagged_at']);
            $table->dropConstrainedForeignId('flagged_by');
            $table->dropColumn(['flagged_at', 'flag_note']);
        });
    }
};
