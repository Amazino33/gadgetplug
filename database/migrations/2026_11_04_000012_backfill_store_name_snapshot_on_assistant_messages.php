<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $messages = DB::table('assistant_messages')
            ->whereNotNull('store_id')
            ->whereNull('store_name_snapshot')
            ->get(['id', 'store_id']);

        foreach ($messages as $message) {
            $storeName = DB::table('stores')->where('id', $message->store_id)->value('name');
            if ($storeName !== null) {
                DB::table('assistant_messages')
                    ->where('id', $message->id)
                    ->update(['store_name_snapshot' => $storeName]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down needed for data backfill
    }
};
