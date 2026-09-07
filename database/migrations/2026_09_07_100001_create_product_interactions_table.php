<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Likes and shares on a feed post.
//
// Deliberately NOT saves. Saving already exists as the wishlists table, it is
// already login-gated, and there is already a page reading it — a second table
// claiming the same meaning would leave two answers to "did I save this".
//
// Presence IS the state for a like: the row exists or it does not, and
// unliking deletes. A share is append-only — each one is an event that
// happened, not a state that can be undone.
//
// Two identities, never both: a signed-in visitor by user_id, a guest by the
// device token cookie. The pair of unique indexes stops either identity
// liking the same product twice; a guest who later signs in has their rows
// reassigned by the login listener rather than duplicated.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('device_token', 64)->nullable();
            $table->string('type', 12);

            // What the uniqueness below actually applies to.
            //
            // Set for a like ('like'), NULL for a share. A like is a state and
            // must not exist twice; a share is an event and sharing the same
            // product again is genuinely a second share. Putting the constraint
            // on a column that is NULL for shares exempts them under standard
            // SQL NULL semantics — the same trick supplier_payable_entries uses
            // to let payments repeat while charges stay idempotent.
            $table->string('unique_key', 12)->nullable();

            $table->timestamps();

            // Named explicitly: the generated names would run past MySQL's
            // 64-character identifier limit, which SQLite does not enforce and
            // the test suite therefore cannot catch.
            $table->unique(['product_id', 'user_id', 'unique_key'], 'pi_product_user_unique');
            $table->unique(['product_id', 'device_token', 'unique_key'], 'pi_product_device_unique');

            // Counting and reconciling by product.
            $table->index(['product_id', 'type'], 'pi_product_type_index');
            // Finding everything one device did, for the merge at login.
            $table->index(['device_token', 'type'], 'pi_device_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_interactions');
    }
};
