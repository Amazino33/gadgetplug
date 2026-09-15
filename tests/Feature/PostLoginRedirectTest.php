<?php

use App\Models\User;
use App\Models\Vendor;
use Spatie\Permission\Models\Role;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('redirects customers to the storefront after login', function () {
    $user = User::factory()->create();

    post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/');
});

it('redirects super admins to the admin panel after login', function () {
    $user = User::factory()->create();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user->assignRole('super_admin');

    post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/admin');
});

it('redirects vendors to the vendor dashboard after login', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create();
    // Attach user to vendor
    \Illuminate\Support\Facades\DB::table('vendor_users')->insert([
        'vendor_id' => $vendor->id,
        'user_id' => $user->id,
    ]);

    post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/plug/' . $vendor->slug);
});

it('bounces logged-in vendors off the storefront root', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create();
    \Illuminate\Support\Facades\DB::table('vendor_users')->insert([
        'vendor_id' => $vendor->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->get('/')
        ->assertRedirect('/plug/' . $vendor->slug);
});

it('lets logged-in customers visit the storefront root', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/')
        ->assertOk(); // The storefront should load successfully
});

it('bounces logged-in vendors off the login page', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create();
    \Illuminate\Support\Facades\DB::table('vendor_users')->insert([
        'vendor_id' => $vendor->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->get('/login')
        ->assertRedirect('/plug/' . $vendor->slug);
});
