<?php

use Database\Seeders\DeliveryRulesSeeder;
use Database\Seeders\NigeriaLocationsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// Data migration, so production gets the locations, the day-one rules and the
// permission on deploy without anyone remembering to seed:
//
//  1. 37 states / 774 LGAs (NigeriaLocationsSeeder — idempotent).
//  2. Day-one rules: Uyo, Mkpat Enin, Eket free; every state not delivered
//     (DeliveryRulesSeeder — only adds, never overwrites).
//  3. The global manage_delivery_settings permission (super_admin passes via
//     Gate::before; grant it to anyone else who runs delivery pricing).
//  4. Legacy checkouts: map the free-text local_government ("Uyo", …) to lga_id
//     where it names exactly one LGA, and report anything left unmapped. The old
//     column is kept for history.
return new class extends Migration
{
    public function up(): void
    {
        (new NigeriaLocationsSeeder)->run();
        (new DeliveryRulesSeeder)->run();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'manage_delivery_settings', 'guard_name' => 'web']);

        $mapped = 0;
        $unmapped = [];

        $names = DB::table('checkouts')->whereNull('lga_id')->whereNotNull('local_government')
            ->distinct()->pluck('local_government');

        foreach ($names as $name) {
            // Prefer Akwa Ibom — every legacy checkout was there — then any
            // LGA of that name anywhere, but only if the name is unambiguous.
            $akwaIbom = DB::table('states')->where('name', 'Akwa Ibom')->value('id');
            $match = DB::table('lgas')->where('state_id', $akwaIbom)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();

            if (! $match) {
                $candidates = DB::table('lgas')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->get();
                $match = $candidates->count() === 1 ? $candidates->first() : null;
            }

            if (! $match) {
                $unmapped[$name] = DB::table('checkouts')->whereNull('lga_id')->where('local_government', $name)->count();
                continue;
            }

            $mapped += DB::table('checkouts')->whereNull('lga_id')->where('local_government', $name)
                ->update(['lga_id' => $match->id, 'state_id' => $match->state_id]);
        }

        $line = sprintf(
            'Delivery locations: %d states, %d LGAs, %d rules. Legacy checkouts mapped to an LGA: %d. Unmapped: %s',
            DB::table('states')->count(),
            DB::table('lgas')->count(),
            DB::table('delivery_rules')->count(),
            $mapped,
            $unmapped === [] ? 'none' : collect($unmapped)->map(fn ($n, $name) => "\"{$name}\" ×{$n}")->implode(', '),
        );

        Log::info($line);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDOUT, '  '.$line.PHP_EOL);
        }
    }

    public function down(): void
    {
        DB::table('checkouts')->update(['lga_id' => null, 'state_id' => null]);
        DB::table('delivery_rules')->delete();
        DB::table('lgas')->delete();
        DB::table('states')->delete();
        Permission::where('name', 'manage_delivery_settings')->delete();
    }
};
