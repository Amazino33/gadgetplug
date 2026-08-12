<?php

use App\Filament\Resources\AffiliateTaskSubmissions\AffiliateTaskSubmissionResource;
use App\Filament\Resources\AffiliateTaskSubmissions\Pages\ListAffiliateTaskSubmissions;
use App\Models\Affiliate;
use App\Models\AffiliateTask;
use App\Models\AffiliateTaskSubmission;
use App\Models\User;
use App\Models\Vendor;
use App\Models\PlugPointTransaction;
use App\Services\Affiliate\AffiliateTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsSuperAdminForSubmissions(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

function makeReviewQueueSubmission(): AffiliateTaskSubmission
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $task = AffiliateTask::create([
        'name'               => 'Post to Facebook',
        'task_type'          => 'manual',
        'verification_type'  => 'manual',
        'points_reward'      => 500,
        'is_active'          => true,
    ]);

    return app(AffiliateTaskService::class)->submit($task, $affiliate, 'Check my post');
}

test('a super admin can access the task submissions resource', function () {
    $this->actingAs(actingAsSuperAdminForSubmissions());

    expect(AffiliateTaskSubmissionResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the task submissions resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Submission Vendor']);

    $this->actingAs($owner);

    expect(AffiliateTaskSubmissionResource::canAccess())->toBeFalse();
});

test('the resource has no create, edit, or delete capability — only approve/reject actions', function () {
    expect(AffiliateTaskSubmissionResource::canCreate())->toBeFalse()
        ->and(AffiliateTaskSubmissionResource::canEdit(new AffiliateTaskSubmission()))->toBeFalse()
        ->and(AffiliateTaskSubmissionResource::canDelete(new AffiliateTaskSubmission()))->toBeFalse();
});

test('the navigation badge shows the count of pending submissions', function () {
    makeReviewQueueSubmission();
    makeReviewQueueSubmission();

    expect(AffiliateTaskSubmissionResource::getNavigationBadge())->toBe('2');
});

test('approving from the review queue table action credits Plug Points through the service', function () {
    $admin = actingAsSuperAdminForSubmissions();
    $this->actingAs($admin);

    $submission = makeReviewQueueSubmission();

    Livewire::test(ListAffiliateTaskSubmissions::class)
        ->callTableAction('approve', $submission);

    expect($submission->fresh()->status)->toBe('approved')
        ->and($submission->fresh()->reviewed_by)->toBe($admin->id)
        ->and(PlugPointTransaction::where('affiliate_task_submission_id', $submission->id)->count())->toBe(1);
});

test('rejecting from the review queue table action records the reason and credits nothing', function () {
    $admin = actingAsSuperAdminForSubmissions();
    $this->actingAs($admin);

    $submission = makeReviewQueueSubmission();

    Livewire::test(ListAffiliateTaskSubmissions::class)
        ->callTableAction('reject', $submission, data: ['reason' => 'Screenshot unclear']);

    expect($submission->fresh()->status)->toBe('rejected')
        ->and($submission->fresh()->rejected_reason)->toBe('Screenshot unclear')
        ->and(PlugPointTransaction::where('affiliate_task_submission_id', $submission->id)->count())->toBe(0);
});

test('the default pending tab hides already-reviewed submissions', function () {
    $admin = actingAsSuperAdminForSubmissions();
    $this->actingAs($admin);

    $pending = makeReviewQueueSubmission();
    $reviewed = makeReviewQueueSubmission();
    app(AffiliateTaskService::class)->reject($reviewed, $admin, 'no');

    Livewire::test(ListAffiliateTaskSubmissions::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$reviewed]);
});
