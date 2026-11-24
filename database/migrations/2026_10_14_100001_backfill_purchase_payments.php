<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every existing purchase that recorded money paid gets that money as one
 * payment row, so its paid and balance read the same after this as before.
 *
 * Drafts are skipped: nothing was received against them, and the payment is
 * taken when they are. Idempotent — a purchase that already has payment rows
 * is left alone, so running this twice adds nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('procurements')
            ->where('amount_paid', '>', 0)
            ->where('status', '!=', 'draft')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('purchase_payments')
                ->whereColumn('purchase_payments.purchase_id', 'procurements.id'))
            ->orderBy('id')
            ->select(['id', 'vendor_id', 'amount_paid', 'payment_method', 'approved_at', 'created_at', 'created_by'])
            ->chunkById(500, function ($rows) {
                DB::table('purchase_payments')->insert($rows->map(fn ($p) => [
                    'purchase_id' => $p->id,
                    'vendor_id'   => $p->vendor_id,
                    'amount'      => $p->amount_paid,
                    'method'      => match ($p->payment_method) {
                        'cash'          => 'cash',
                        'bank_transfer' => 'transfer',
                        'pos'           => 'pos',
                        default         => 'other',
                    },
                    'paid_at'     => $p->approved_at ?? $p->created_at,
                    'recorded_by' => $p->created_by,
                    'note'        => 'Amount paid as recorded before payment tracking.',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ])->all());
            });
    }

    public function down(): void
    {
        DB::table('purchase_payments')
            ->where('note', 'Amount paid as recorded before payment tracking.')
            ->delete();
    }
};
