<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// What each batch of stock actually cost, kept per branch.
//
// Until now a product carried one cost_price, overwritten by whatever the last
// procurement paid, and inventory was valued by multiplying every unit by that
// single figure. Restocking at a higher price therefore revalued stock bought
// cheaply: 10 units at N1,000 plus 10 at N1,500 reported N30,000 held rather
// than the N25,000 actually spent. The gain was invented by the arithmetic.
//
// A layer is one receipt: how many units arrived, what they cost, and how many
// of those units are still on the shelf. Sales draw down the oldest layer
// first, so what remains is always the cost of the units actually held.
//
// unit_cost is nullable on purpose. Goods can legitimately arrive before anyone
// records what they cost, and a layer that admits it does not know is worth
// more than one that quietly claims zero — the report counts those units
// separately and says the total is incomplete.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_cost_layers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->decimal('unit_cost', 12, 2)->nullable();

            // Kept alongside what remains so a layer can still say what it
            // originally was after being partly or wholly consumed.
            $table->integer('quantity_received');
            $table->integer('quantity_remaining');

            // What brought these units in — a procurement item, a return, an
            // audit correction. Nullable for the opening layers this migration
            // writes, which have no document behind them.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // The ordering key for consumption. Its own column rather than
            // created_at so a backdated receipt can be placed correctly.
            $table->timestamp('received_at');

            $table->timestamps();

            // The one read this table exists for: the oldest unspent layer of a
            // product in a branch.
            $table->index(['product_id', 'store_id', 'received_at'], 'scl_product_store_received_idx');
            $table->index(['source_type', 'source_id']);
        });

        $this->seedOpeningLayers();
    }

    /**
     * One opening layer per branch that currently holds stock, at whatever cost
     * the product carries today.
     *
     * This is the only figure that exists — there is no purchase history to
     * rebuild from — so FIFO is accurate from here forward rather than
     * retroactively. Crucially it is also exactly what the old valuation would
     * have reported, so the number on screen does not jump on deploy; it simply
     * stops drifting from this point on.
     */
    private function seedOpeningLayers(): void
    {
        $now = now();

        DB::table('product_store_stock')
            ->join('products', 'products.id', '=', 'product_store_stock.product_id')
            ->where('product_store_stock.quantity', '>', 0)
            ->select([
                'product_store_stock.product_id',
                'product_store_stock.store_id',
                'product_store_stock.quantity',
                'products.cost_price',
            ])
            ->orderBy('product_store_stock.id')
            ->chunk(500, function ($rows) use ($now) {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        'product_id'         => $row->product_id,
                        'store_id'           => $row->store_id,
                        'unit_cost'          => $row->cost_price,
                        'quantity_received'  => $row->quantity,
                        'quantity_remaining' => $row->quantity,
                        'source_type'        => null,
                        'source_id'          => null,
                        'received_at'        => $now,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }

                if ($payload !== []) {
                    DB::table('stock_cost_layers')->insert($payload);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cost_layers');
    }
};
