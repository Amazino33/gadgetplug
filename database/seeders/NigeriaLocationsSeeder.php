<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every Nigerian state (36 + FCT) and all 774 LGAs.
 *
 * Source: github.com/xosasx/nigerian-local-government-areas
 *         (MIT License, Copyright (c) 2022 Osas Obazee);
 *         coordinates from Wikidata (one approximate reference point per LGA).
 * Supplied, already validated, at database/data/nigeria-locations.json — do not
 * re-fetch or regenerate it; edit the file only if an LGA genuinely changes.
 *
 * Idempotent: keyed on state name and (state, LGA name), so re-running updates
 * coordinates in place and never duplicates a row or breaks a reference to one.
 */
class NigeriaLocationsSeeder extends Seeder
{
    public const DATA_FILE = 'data/nigeria-locations.json';

    public function run(): void
    {
        $data = json_decode((string) file_get_contents(database_path(self::DATA_FILE)), true);

        if (! is_array($data['states'] ?? null)) {
            throw new RuntimeException('nigeria-locations.json is missing its "states" list.');
        }

        $now = now();

        DB::transaction(function () use ($data, $now) {
            foreach ($data['states'] as $state) {
                $lgas = $state['lgas'] ?? [];

                $stateValues = [
                    'code'       => $state['code'],
                    'latitude'   => $lgas ? round(array_sum(array_column($lgas, 'lat')) / count($lgas), 6) : null,
                    'longitude'  => $lgas ? round(array_sum(array_column($lgas, 'lng')) / count($lgas), 6) : null,
                    'updated_at' => $now,
                ];

                $stateId = DB::table('states')->where('name', $state['name'])->value('id');

                if ($stateId) {
                    DB::table('states')->where('id', $stateId)->update($stateValues);
                } else {
                    $stateId = DB::table('states')->insertGetId(['name' => $state['name'], 'created_at' => $now] + $stateValues);
                }

                foreach ($lgas as $lga) {
                    $values = [
                        'latitude'    => $lga['lat'],
                        'longitude'   => $lga['lng'],
                        'wikidata_id' => $lga['wikidata'] ?? null,
                        'updated_at'  => $now,
                    ];

                    $exists = DB::table('lgas')->where('state_id', $stateId)->where('name', $lga['name'])->exists();

                    $exists
                        ? DB::table('lgas')->where('state_id', $stateId)->where('name', $lga['name'])->update($values)
                        : DB::table('lgas')->insert(['state_id' => $stateId, 'name' => $lga['name'], 'created_at' => $now] + $values);
                }
            }
        });
    }
}
