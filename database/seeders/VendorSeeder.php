<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'vendor@gadgetplug.com')->firstOrFail();

        $vendor = Vendor::firstOrCreate(
            ['slug' => 'techhaven'],
            [
                'user_id'        => $owner->id,
                'name'           => 'TechHaven',
                'logo'           => null,
                'is_verified'    => true,
                'description'    => 'Your one-stop shop for the latest gadgets, electronics and tech accessories.',
                'whatsapp'       => '2348012345678',
                'bank_name'      => 'Guaranty Trust Bank',
                'account_number' => '0123456789',
                'account_name'   => 'Chidi Okonkwo',
            ]
        );

        // Attach team members (skip if already attached)
        $teamRoles = [
            'pm@gadgetplug.com'         => 'product_manager',
            'om@gadgetplug.com'         => 'order_manager',
            'im@gadgetplug.com'         => 'inventory_manager',
            'storekeeper@gadgetplug.com' => 'storekeeper',
        ];

        foreach ($teamRoles as $email => $role) {
            $user = User::where('email', $email)->first();
            if ($user && ! $vendor->users()->where('user_id', $user->id)->exists()) {
                $vendor->users()->attach($user->id, ['role' => $role]);
            }
        }
    }
}
