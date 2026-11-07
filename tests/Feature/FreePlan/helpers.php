<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;

// Shared by the FreePlan tests. Guarded because Pest loads every test file
// into one process.

if (! function_exists('freeShop')) {
    /** A free shop and its owner, created the way signup creates them. */
    function freeShop(string $status = Vendor::STATUS_PENDING, array $attributes = []): array
    {
        $owner  = User::factory()->create();
        $vendor = Vendor::create(array_merge([
            'user_id'    => $owner->id,
            'name'       => 'Joy Provisions '.uniqid(),
            'whatsapp'   => '23480'.random_int(10000000, 99999999),
            'category'   => 'Phones & Tablets',
            'state'      => 'Lagos',
            'city'       => 'Ikeja',
            'plan'       => Vendor::PLAN_FREE,
            'status'     => $status,
            'applied_at' => now(),
        ], $attributes));
        $vendor->users()->syncWithoutDetaching([$owner->id]);

        return ['owner' => $owner, 'vendor' => $vendor->fresh()];
    }
}

if (! function_exists('paidShop')) {
    /** A vendor created the old way — by the admin, no plan given. */
    function paidShop(): array
    {
        $owner  = User::factory()->create();
        $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Big Supermarket '.uniqid()]);
        $vendor->users()->syncWithoutDetaching([$owner->id]);

        return ['owner' => $owner, 'vendor' => $vendor->fresh()];
    }
}

if (! function_exists('signupPayload')) {
    function signupPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name'         => 'Joy Provisions',
            'owner_name'            => 'Joy Okafor',
            'whatsapp'              => '0803 123 4567',
            'email'                 => 'joy@example.com',
            'password'              => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'category'              => 'Phones & Tablets',
            'state'                 => 'Lagos',
            'city_area'             => 'Ikeja',
            'slug'                  => 'joy-provisions',
            'description'           => 'Foodstuff and drinks.',
            'verification_photo'    => UploadedFile::fake()->image('shop.jpg', 2400, 1800),
            'terms'                 => '1',
        ], $overrides);
    }
}
