<?php

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a super admin can set a reseller discount on a category', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    $category = Category::create(['name' => 'Discount Category Test ' . uniqid()]);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['reseller_discount' => 12.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $category->fresh()->reseller_discount)->toBe(12.5);
});
