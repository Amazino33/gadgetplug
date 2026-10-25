<?php

use App\Filament\Resources\LegalPolicies\LegalPolicyResource;
use App\Models\LegalPolicy;
use App\Models\User;
use Database\Seeders\LegalPolicySeeder;
use Spatie\Permission\Models\Role;

test('visiting privacy policy auto-seeds if table is empty', function () {
    LegalPolicy::query()->delete();
    expect(LegalPolicy::count())->toBe(0);

    $this->get(route('privacy-policy'))
        ->assertOk()
        ->assertSee('Privacy Policy');

    expect(LegalPolicy::count())->toBe(4);
});

test('public legal routes return 200 and display policy content', function () {
    $this->seed(LegalPolicySeeder::class);

    $this->get(route('privacy-policy'))
        ->assertOk()
        ->assertSee('Privacy Policy');

    $this->get(route('terms'))
        ->assertOk()
        ->assertSee('Terms of Service');

    $this->get(route('affiliate-terms'))
        ->assertOk()
        ->assertSee('Affiliate Program Agreement');

    $this->get(route('refund-policy'))
        ->assertOk()
        ->assertSee('Return, Replacement & Refund Policy');

    $this->get(route('legal.show', 'privacy-policy'))
        ->assertOk()
        ->assertSee('Privacy Policy');
});

test('an unpublished policy returns 404', function () {
    LegalPolicy::create([
        'title'        => 'Draft Policy',
        'slug'         => 'draft-policy',
        'content'      => '<p>Draft</p>',
        'is_published' => false,
    ]);

    $this->get(route('legal.show', 'draft-policy'))
        ->assertNotFound();
});

test('a super admin can access the legal policies resource in filament', function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);

    expect(LegalPolicyResource::canAccess())->toBeTrue();
});

test('a non-admin user cannot access the legal policies resource in filament', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(LegalPolicyResource::canAccess())->toBeFalse();
});
