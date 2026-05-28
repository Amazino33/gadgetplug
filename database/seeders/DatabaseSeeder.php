<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            VendorPermissionsSeeder::class,
            CategorySeeder::class,
            UserSeeder::class,
            VendorSeeder::class,
            ProductSeeder::class,
            OrderSeeder::class,
        ]);
    }
}
