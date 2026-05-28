<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cached permissions so role assignment works cleanly
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        // Super admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@gadgetplug.com'],
            [
                'name'              => 'Super Admin',
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
                'phone'             => '08000000000',
            ]
        );
        $admin->assignRole('super_admin');

        // Vendor owner
        User::firstOrCreate(
            ['email' => 'vendor@gadgetplug.com'],
            [
                'name'              => 'Chidi Okonkwo',
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
                'phone'             => '08012345678',
                'pos_pin'           => Hash::make('1234'),
            ]
        );

        // Team members
        $teamMembers = [
            [
                'email'    => 'pm@gadgetplug.com',
                'name'     => 'Amaka Eze',
                'phone'    => '08023456789',
                'pos_pin'  => Hash::make('1111'),
            ],
            [
                'email'    => 'om@gadgetplug.com',
                'name'     => 'Emeka Nwosu',
                'phone'    => '08034567890',
                'pos_pin'  => Hash::make('2222'),
            ],
            [
                'email'    => 'im@gadgetplug.com',
                'name'     => 'Tunde Adeyemi',
                'phone'    => '08045678901',
                'pos_pin'  => Hash::make('3333'),
            ],
            [
                'email'    => 'storekeeper@gadgetplug.com',
                'name'     => 'Ngozi Obi',
                'phone'    => '08056789012',
                'pos_pin'  => Hash::make('4444'),
            ],
        ];

        foreach ($teamMembers as $data) {
            User::firstOrCreate(
                ['email' => $data['email']],
                array_merge($data, [
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                ])
            );
        }

        // Customers (storefront users)
        $customers = [
            ['email' => 'customer1@example.com', 'name' => 'Bola Adesanya',  'phone' => '07011112222'],
            ['email' => 'customer2@example.com', 'name' => 'Funke Oladele',  'phone' => '07022223333'],
            ['email' => 'customer3@example.com', 'name' => 'Danladi Musa',   'phone' => '07033334444'],
        ];

        foreach ($customers as $data) {
            User::firstOrCreate(
                ['email' => $data['email']],
                array_merge($data, [
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                ])
            );
        }
    }
}
