<?php

use App\Filament\Resources\Affiliates\Pages\ViewAffiliate;
use App\Filament\Resources\Affiliates\RelationManagers\TaskSubmissionsRelationManager;
use App\Models\Affiliate;
use App\Models\AffiliateTask;
use App\Models\User;
use App\Services\Affiliate\AffiliateTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('an affiliate\'s task submission history is visible on their detail page', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actingAs($admin);

    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $task = AffiliateTask::create([
        'name'               => 'Post to Instagram',
        'verification_type'  => 'manual',
        'reward_amount'      => 300,
        'is_active'          => true,
    ]);

    app(AffiliateTaskService::class)->submit($task, $affiliate);

    Livewire::test(TaskSubmissionsRelationManager::class, [
        'ownerRecord' => $affiliate,
        'pageClass'   => ViewAffiliate::class,
    ])->assertSee('Post to Instagram');
});
