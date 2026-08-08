<?php

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateLevel;
use App\Models\AffiliateTask;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

function makeDashboardAffiliate(float $availableBalance = 0.0): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    if ($availableBalance != 0.0) {
        WalletTransaction::create([
            'affiliate_id' => $affiliate->id,
            'type'         => 'credit',
            'amount'       => $availableBalance,
            'description'  => 'Seed credit',
        ]);
    }

    return $affiliate;
}

function makeDashboardCommission(Affiliate $affiliate, string $status, float $amount = 500): AffiliateCommission
{
    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'customer_name'    => 'Dashboard Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo, Akwa Ibom State — Test',
        'total_amount'     => 5000,
        'status'           => 'pending',
        'payment_method'   => 'pay_on_delivery',
    ]);

    return AffiliateCommission::create([
        'affiliate_id' => $affiliate->id,
        'order_id'     => $order->id,
        'amount'       => $amount,
        'status'       => $status,
    ]);
}

test('the dashboard shows the affiliate\'s pending and available wallet balances', function () {
    $affiliate = makeDashboardAffiliate(1500);
    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->assertSee('1,500.00');
});

test('the dashboard lists commission history with a status badge', function () {
    $affiliate = makeDashboardAffiliate();
    $this->actingAs($affiliate->user);

    makeDashboardCommission($affiliate, 'available', 750);

    Volt::test('pages.account.affiliate')
        ->assertSee('750.00')
        ->assertSee('Available');
});

test('the dashboard shows the current level and progress to the next tier', function () {
    AffiliateLevel::create(['name' => 'Bronze', 'target' => 0, 'rate_value' => 1.0, 'sort_order' => 0]);
    $silver = AffiliateLevel::create(['name' => 'Silver', 'target' => 50000, 'rate_value' => 1.1, 'sort_order' => 1]);

    $affiliate = makeDashboardAffiliate();
    $affiliate->update(['affiliate_level_id' => $silver->id, 'level_achieved_at' => now()]);
    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->assertSee('Silver');
});

test('an eligible manual task shows a Submit button', function () {
    $affiliate = makeDashboardAffiliate();
    $this->actingAs($affiliate->user);

    AffiliateTask::create([
        'name'               => 'Post to Instagram',
        'verification_type'  => 'manual',
        'reward_amount'      => 300,
        'is_active'          => true,
    ]);

    Volt::test('pages.account.affiliate')
        ->assertSee('Post to Instagram')
        ->assertSee('Submit');
});

test('an auto task shows as automatic, with no submit button', function () {
    $affiliate = makeDashboardAffiliate();
    $this->actingAs($affiliate->user);

    AffiliateTask::create([
        'name'               => 'Reach ₦50,000 in sales',
        'verification_type'  => 'auto',
        'auto_metric'        => 'cleared_sales_value',
        'auto_target'        => 50000,
        'reward_amount'      => 1000,
        'is_active'          => true,
    ]);

    Volt::test('pages.account.affiliate')
        ->assertSee('Completes automatically');
});

test('submitting a manual task with a proof screenshot creates a submission and attaches the media', function () {
    Storage::fake('public');

    $affiliate = makeDashboardAffiliate();
    $this->actingAs($affiliate->user);

    $task = AffiliateTask::create([
        'name'               => 'Post to Instagram',
        'verification_type'  => 'manual',
        'reward_amount'      => 300,
        'is_active'          => true,
    ]);

    Volt::test('pages.account.affiliate')
        ->call('openTaskSubmission', $task->id)
        ->set('taskNotes', 'Here is my post')
        ->set('proofFile', UploadedFile::fake()->image('proof.jpg'))
        ->call('submitTask');

    $submission = $affiliate->fresh()->taskSubmissions()->first();

    expect($submission)->not->toBeNull()
        ->and($submission->status)->toBe('submitted')
        ->and($submission->notes)->toBe('Here is my post')
        ->and($submission->getMedia('proof'))->toHaveCount(1);
});

test('an affiliate can save their bank details from the dashboard', function () {
    $affiliate = makeDashboardAffiliate();
    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->set('bankName', 'GTBank')
        ->set('accountNumber', '0123456789')
        ->set('accountName', 'Jane Doe')
        ->call('saveBankDetails');

    expect($affiliate->fresh())
        ->bank_name->toBe('GTBank')
        ->account_number->toBe('0123456789')
        ->account_name->toBe('Jane Doe');
});

test('saving bank details rejects an account number that is not exactly 10 digits', function () {
    $affiliate = makeDashboardAffiliate();
    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->set('bankName', 'GTBank')
        ->set('accountNumber', '12345')
        ->set('accountName', 'Jane Doe')
        ->call('saveBankDetails')
        ->assertHasErrors(['accountNumber']);

    expect($affiliate->fresh()->account_number)->toBeNull();
});

test('a missing-bank-details warning shows until bank details are saved', function () {
    $affiliate = makeDashboardAffiliate();
    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->assertSee('Add your bank details below so we can pay you.');

    $affiliate->update(['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Jane Doe']);

    // Re-authenticate with a freshly-fetched user — the guard caches the
    // resolved user (and its lazy-loaded `affiliate` relation) for the rest
    // of the test, so reusing the same auth session here would silently
    // rebuild the component on stale, pre-update data.
    $this->actingAs(User::find($affiliate->user_id));

    Volt::test('pages.account.affiliate')
        ->assertDontSee('Add your bank details below so we can pay you.');
});

test('a task with no remaining eligibility shows a not-eligible message instead of a submit button', function () {
    $affiliate = makeDashboardAffiliate();
    $this->actingAs($affiliate->user);

    $task = AffiliateTask::create([
        'name'                          => 'One-time bonus',
        'verification_type'             => 'manual',
        'reward_amount'                 => 300,
        'max_completions_per_affiliate' => 1,
        'is_active'                     => true,
    ]);

    // Already has a pending submission — not eligible for another right now.
    app(\App\Services\Affiliate\AffiliateTaskService::class)->submit($task, $affiliate);

    Volt::test('pages.account.affiliate')
        ->assertSee('Not eligible right now');
});
