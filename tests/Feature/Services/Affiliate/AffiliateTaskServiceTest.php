<?php

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateLevel;
use App\Models\AffiliateTask;
use App\Models\AffiliateTaskSubmission;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\PlugPointTransaction;
use App\Services\Affiliate\AffiliateLevelProgressionService;
use App\Services\Affiliate\AffiliateTaskService;
use Spatie\Permission\Models\Role;

function makeTaskAffiliate(): Affiliate
{
    return Affiliate::findOrCreateForUser(User::factory()->create());
}

function makeReviewer(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $user;
}

function makeManualTask(array $attrs = []): AffiliateTask
{
    return AffiliateTask::create(array_merge([
        'name'                           => 'Post to Facebook',
        'task_type'                      => 'manual',
        'verification_type'              => 'manual',
        'points_reward'                  => 500,
        'counts_toward_level'            => false,
        'max_completions_per_affiliate'  => 1,
        'is_active'                      => true,
    ], $attrs));
}

function makeAutoTask(array $attrs = []): AffiliateTask
{
    return AffiliateTask::create(array_merge([
        'name'                           => 'Reach ₦50,000 in cleared sales',
        'task_type'                      => 'auto',
        'verification_type'              => 'auto',
        'auto_metric'                    => 'cleared_sales_value',
        'auto_target'                    => 50000,
        'points_reward'                  => 1000,
        'counts_toward_level'            => false,
        'max_completions_per_affiliate'  => 1,
        'is_active'                      => true,
    ], $attrs));
}

// Creates one 'available' commission worth $baseAmount of cleared sales value
// for the affiliate, for auto-task-threshold and progression tests.
function makeTaskEngineAvailableSale(Affiliate $affiliate, float $baseAmount): AffiliateCommission
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Task Engine Store ' . uniqid()]);
    $category = Category::create(['name' => 'Task Engine Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Task Engine Product',
        'price'          => $baseAmount,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'customer_name'    => 'Test Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => $baseAmount,
        'status'           => 'pending',
        'payment_method'   => 'paystack',
    ]);

    $orderItem = OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => $baseAmount,
    ]);

    $commission = AffiliateCommission::create([
        'affiliate_id' => $affiliate->id,
        'order_id'     => $order->id,
        'amount'       => 0,
        'status'       => 'available',
        'available_at' => now(),
    ]);

    $commission->items()->create([
        'order_item_id' => $orderItem->id,
        'rate'           => 10,
        'base_amount'    => $baseAmount,
        'amount'         => $baseAmount * 0.1,
    ]);

    return $commission;
}

// --- Manual submission: approve / reject ---

test('approving a manual submission credits Plug Points exactly once', function () {
    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask(['points_reward' => 750]);
    $reviewer  = makeReviewer();

    $submission = app(AffiliateTaskService::class)->submit($task, $affiliate, 'Here is my post: link.example');
    app(AffiliateTaskService::class)->approve($submission, $reviewer);

    expect($submission->fresh()->status)->toBe('approved')
        ->and($submission->fresh()->reviewed_by)->toBe($reviewer->id)
        ->and(PlugPointTransaction::where('affiliate_task_submission_id', $submission->id)->count())->toBe(1)
        ->and(PlugPointTransaction::where('affiliate_task_submission_id', $submission->id)->first()->points)->toBe(750);
});

test('approving the same submission twice never double-credits', function () {
    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask();
    $reviewer  = makeReviewer();

    $submission = app(AffiliateTaskService::class)->submit($task, $affiliate);
    $service    = app(AffiliateTaskService::class);

    $service->approve($submission, $reviewer);
    $service->approve($submission->fresh(), $reviewer);

    expect(PlugPointTransaction::where('affiliate_task_submission_id', $submission->id)->count())->toBe(1);
});

test('rejecting a submission credits nothing and records the reason', function () {
    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask();
    $reviewer  = makeReviewer();

    $submission = app(AffiliateTaskService::class)->submit($task, $affiliate);
    app(AffiliateTaskService::class)->reject($submission, $reviewer, 'Screenshot does not show the post.');

    expect($submission->fresh()->status)->toBe('rejected')
        ->and($submission->fresh()->rejected_reason)->toBe('Screenshot does not show the post.')
        ->and(PlugPointTransaction::where('affiliate_task_submission_id', $submission->id)->count())->toBe(0);
});

// --- Eligibility guards ---

test('a second submission is blocked while one is already pending review for the same task', function () {
    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask(['max_completions_per_affiliate' => null]);

    app(AffiliateTaskService::class)->submit($task, $affiliate);

    expect(fn () => app(AffiliateTaskService::class)->submit($task, $affiliate))
        ->toThrow(RuntimeException::class);
});

test('max_completions_per_affiliate blocks a further submission once reached', function () {
    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask(['max_completions_per_affiliate' => 1]);
    $reviewer  = makeReviewer();

    $first = app(AffiliateTaskService::class)->submit($task, $affiliate);
    app(AffiliateTaskService::class)->approve($first, $reviewer);

    expect(fn () => app(AffiliateTaskService::class)->submit($task, $affiliate))
        ->toThrow(RuntimeException::class);
});

test('cooldown_days blocks a resubmission until the cooldown has elapsed', function () {
    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask(['max_completions_per_affiliate' => null, 'cooldown_days' => 7]);
    $reviewer  = makeReviewer();

    $first = app(AffiliateTaskService::class)->submit($task, $affiliate);
    app(AffiliateTaskService::class)->approve($first, $reviewer);

    expect(fn () => app(AffiliateTaskService::class)->submit($task, $affiliate))
        ->toThrow(RuntimeException::class);

    // Backdate the approval past the cooldown window.
    $first->fresh()->update(['reviewed_at' => now()->subDays(8)]);

    $second = app(AffiliateTaskService::class)->submit($task, $affiliate);
    expect($second)->not->toBeNull();
});

test('a rejected submission does not count against the completion cap', function () {
    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask(['max_completions_per_affiliate' => 1]);
    $reviewer  = makeReviewer();

    $first = app(AffiliateTaskService::class)->submit($task, $affiliate);
    app(AffiliateTaskService::class)->reject($first, $reviewer, 'Not clear enough.');

    $second = app(AffiliateTaskService::class)->submit($task, $affiliate);
    expect($second)->not->toBeNull();
});

// --- Auto tasks ---

test('an auto task completes exactly once when the affiliate crosses the threshold', function () {
    $affiliate = makeTaskAffiliate();
    makeAutoTask(['auto_target' => 50000, 'points_reward' => 1000]);

    makeTaskEngineAvailableSale($affiliate, 60000);

    app(AffiliateTaskService::class)->evaluateAuto($affiliate);

    expect(AffiliateTaskSubmission::where('affiliate_id', $affiliate->id)->where('status', 'approved')->count())->toBe(1)
        ->and(PlugPointTransaction::where('affiliate_id', $affiliate->id)->sum('points'))->toEqual(1000);
});

test('re-evaluating an auto task after completion does not re-credit', function () {
    $affiliate = makeTaskAffiliate();
    makeAutoTask(['auto_target' => 50000, 'points_reward' => 1000]);

    makeTaskEngineAvailableSale($affiliate, 60000);

    $service = app(AffiliateTaskService::class);
    $service->evaluateAuto($affiliate);
    $service->evaluateAuto($affiliate);
    $service->evaluateAuto($affiliate);

    expect(AffiliateTaskSubmission::where('affiliate_id', $affiliate->id)->count())->toBe(1)
        ->and(PlugPointTransaction::where('affiliate_id', $affiliate->id)->sum('points'))->toEqual(1000);
});

test('an auto task below its threshold does not complete', function () {
    $affiliate = makeTaskAffiliate();
    makeAutoTask(['auto_target' => 50000]);

    makeTaskEngineAvailableSale($affiliate, 10000);

    app(AffiliateTaskService::class)->evaluateAuto($affiliate);

    expect(AffiliateTaskSubmission::where('affiliate_id', $affiliate->id)->count())->toBe(0);
});

// --- Level-progress hook ---

test('a task flagged to count toward level promotes the affiliate on its own, with zero sales', function () {
    AffiliateLevel::create(['name' => 'Bronze', 'target' => 0,     'rate_value' => 1.0, 'sort_order' => 0]);
    AffiliateLevel::create(['name' => 'Silver', 'target' => 50000, 'rate_value' => 1.1, 'sort_order' => 1]);

    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask(['counts_toward_level' => true, 'level_progress_value' => 60000]);
    $reviewer  = makeReviewer();

    $submission = app(AffiliateTaskService::class)->submit($task, $affiliate);
    app(AffiliateTaskService::class)->approve($submission, $reviewer);

    expect($affiliate->fresh('level')->level->name)->toBe('Silver')
        ->and((float) $submission->fresh()->level_progress_value)->toBe(60000.0);
});

test('a task not flagged to count toward level contributes nothing to progression', function () {
    AffiliateLevel::create(['name' => 'Bronze', 'target' => 0,     'rate_value' => 1.0, 'sort_order' => 0]);
    AffiliateLevel::create(['name' => 'Silver', 'target' => 50000, 'rate_value' => 1.1, 'sort_order' => 1]);

    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask(['counts_toward_level' => false]);
    $reviewer  = makeReviewer();

    $submission = app(AffiliateTaskService::class)->submit($task, $affiliate);
    app(AffiliateTaskService::class)->approve($submission, $reviewer);

    // recompute() is only ever called when a task counts toward level —
    // an affiliate with zero sales and only a non-counting task completed
    // is never even evaluated for a level, same as an affiliate who has
    // never had a commission clear (Prompt 2's existing behavior).
    expect($affiliate->fresh('level')->level)->toBeNull()
        ->and($submission->fresh()->level_progress_value)->toBeNull();
});

// --- Demotion + task activity ---

test('an approved task completion counts as activity for the inactivity-demotion clock', function () {
    $affiliate = makeTaskAffiliate();
    $task      = makeManualTask();
    $reviewer  = makeReviewer();

    $submission = app(AffiliateTaskService::class)->submit($task, $affiliate);
    app(AffiliateTaskService::class)->approve($submission, $reviewer);

    $lastActivity = app(AffiliateLevelProgressionService::class)->lastQualifyingActivityAt($affiliate->id);

    expect($lastActivity)->not->toBeNull()
        ->and($lastActivity->diffInMinutes(now()))->toBeLessThan(1);
});

test('an affiliate with zero sales but a recent approved task is not demoted by the inactivity job', function () {
    \App\Models\AffiliateSetting::current()->update(['inactivity_demotion_days' => 21]);
    $gold = AffiliateLevel::create(['name' => 'Gold', 'target' => 0, 'rate_value' => 1.2, 'sort_order' => 0]);

    $affiliate = makeTaskAffiliate();
    $affiliate->update(['affiliate_level_id' => $gold->id, 'level_achieved_at' => now()->subDays(30)]);
    $affiliate->timestamps = false;
    $affiliate->created_at = now()->subDays(365);
    $affiliate->save();

    $task     = makeManualTask();
    $reviewer = makeReviewer();

    $submission = app(AffiliateTaskService::class)->submit($task, $affiliate);
    app(AffiliateTaskService::class)->approve($submission, $reviewer);

    (new \App\Jobs\DemoteInactiveAffiliatesJob())->handle(app(AffiliateLevelProgressionService::class));

    expect($affiliate->fresh('level')->level->name)->toBe('Gold');
});
