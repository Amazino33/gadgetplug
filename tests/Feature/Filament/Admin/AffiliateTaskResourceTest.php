<?php

use App\Filament\Resources\AffiliateTasks\AffiliateTaskResource;
use App\Filament\Resources\AffiliateTasks\Pages\CreateAffiliateTask;
use App\Filament\Resources\AffiliateTasks\Pages\ListAffiliateTasks;
use App\Models\AffiliateTask;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsSuperAdminForTasks(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

test('a super admin can access the affiliate tasks resource', function () {
    $this->actingAs(actingAsSuperAdminForTasks());

    expect(AffiliateTaskResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the affiliate tasks resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Task Vendor']);

    $this->actingAs($owner);

    expect(AffiliateTaskResource::canAccess())->toBeFalse();
});

test('creating a manual task from the admin UI persists it correctly', function () {
    $this->actingAs(actingAsSuperAdminForTasks());

    Livewire::test(CreateAffiliateTask::class)
        ->fillForm([
            'name'               => 'Post to Facebook',
            'verification_type'  => 'manual',
            'reward_amount'      => 500,
            'is_active'          => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $task = AffiliateTask::where('name', 'Post to Facebook')->first();

    expect($task)->not->toBeNull()
        ->and($task->verification_type)->toBe('manual')
        ->and((float) $task->reward_amount)->toBe(500.0);
});

test('creating an auto task persists the metric and target correctly', function () {
    $this->actingAs(actingAsSuperAdminForTasks());

    Livewire::test(CreateAffiliateTask::class)
        ->fillForm([
            'name'               => 'Reach ₦50,000',
            'verification_type'  => 'auto',
            'auto_metric'        => 'cleared_sales_value',
            'auto_target'        => 50000,
            'reward_amount'      => 1000,
            'is_active'          => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $task = AffiliateTask::where('name', 'Reach ₦50,000')->first();

    expect($task)->not->toBeNull()
        ->and($task->auto_metric)->toBe('cleared_sales_value')
        ->and((float) $task->auto_target)->toBe(50000.0);
});

test('the tasks list shows name, type, reward and submission count', function () {
    $this->actingAs(actingAsSuperAdminForTasks());

    AffiliateTask::create([
        'name'               => 'Refer a friend',
        'verification_type'  => 'manual',
        'reward_amount'      => 250,
        'is_active'          => true,
    ]);

    Livewire::test(ListAffiliateTasks::class)
        ->assertSee('Refer a friend')
        ->assertSee('Manual');
});
