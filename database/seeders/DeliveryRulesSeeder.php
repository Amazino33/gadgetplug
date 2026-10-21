<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Day-one delivery rules, chosen so checkout behaves exactly as before:
 * Uyo, Mkpat Enin and Eket (the three areas checkout allowed) are free, and
 * every state is not delivered. The owner opens more areas from Delivery
 * settings.
 *
 * Only ever ADDS missing rules — it never overwrites one, so re-running it
 * after the owner has changed prices changes nothing.
 */
class DeliveryRulesSeeder extends Seeder
{
    public const FREE_LGAS = [
        'Akwa Ibom' => ['Uyo', 'Mkpat Enin', 'Eket'],
    ];

    public function run(): void
    {
        $now = now();

        foreach (DB::table('states')->pluck('id') as $stateId) {
            $exists = DB::table('delivery_rules')->where('state_id', $stateId)->whereNull('lga_id')->exists();

            if (! $exists) {
                DB::table('delivery_rules')->insert([
                    'state_id' => $stateId, 'lga_id' => null, 'mode' => 'not_delivered',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        foreach (self::FREE_LGAS as $stateName => $lgaNames) {
            $stateId = DB::table('states')->where('name', $stateName)->value('id');

            foreach (DB::table('lgas')->where('state_id', $stateId)->whereIn('name', $lgaNames)->pluck('id') as $lgaId) {
                $exists = DB::table('delivery_rules')->where('lga_id', $lgaId)->exists();

                if (! $exists) {
                    DB::table('delivery_rules')->insert([
                        'state_id' => $stateId, 'lga_id' => $lgaId, 'mode' => 'free', 'fee' => 0,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }
}
