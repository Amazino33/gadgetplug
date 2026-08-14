<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stops one checkout from becoming two orders.
//
// The Place Order button was never disabled while its request was in flight, so
// a customer on a slow connection who tapped twice got two orders — two
// references, two sets of items, and on pay-on-delivery the same stock reserved
// twice. Disabling the button fixes the ordinary double-tap; this column is what
// holds when the button is not the problem: a dropped connection the browser
// retries, a second tab, or two requests genuinely in flight at once.
//
// Nullable because every existing order predates it, and because the key is
// derived from cart contents that a manually-created order does not have.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // sha256 hex is exactly 64 characters.
            $table->string('idempotency_key', 64)->nullable()->unique()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
