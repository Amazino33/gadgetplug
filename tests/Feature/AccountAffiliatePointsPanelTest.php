<?php

use App\Models\Affiliate;
use App\Models\AffiliatePointConversion;
use App\Models\AffiliateSetting;
use App\Models\AffiliateTask;
use App\Models\AffiliateTaskSubmission;
use App\Models\MarketingMaterial;
use App\Models\User;
use App\Services\Affiliate\PlugPointService;
use App\Services\Affiliate\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function panelAffiliate(int $points = 0): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    if ($points > 0) {
        $affiliate->plugPointTransactions()->create([
            'type' => 'credit', 'points' => $points, 'source' => 'adjustment', 'description' => 'Seed',
        ]);
    }

    return $affiliate;
}

function panelShareTask(): AffiliateTask
{
    return AffiliateTask::create([
        'name' => 'Share of the day', 'task_type' => 'daily_social_share',
        'verification_type' => 'manual', 'points_reward' => 0, 'is_active' => true,
    ]);
}

test('the panel shows the points balance and the streak', function () {
    $affiliate = panelAffiliate(450);

    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->assertSee('Plug Points')
        ->assertSee('450');
});

test('an affiliate can convert points to wallet cash from the panel', function () {
    AffiliateSetting::current()->update(['naira_per_point' => 2.0, 'min_points_conversion' => 100]);

    $affiliate = panelAffiliate(500);
    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->set('pointsToConvert', '300')
        ->call('convertPoints')
        ->assertHasNoErrors();

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(200)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(600.0)
        ->and(AffiliatePointConversion::count())->toBe(1);
});

test('converting below the minimum surfaces an error and moves nothing', function () {
    AffiliateSetting::current()->update(['min_points_conversion' => 1000]);

    $affiliate = panelAffiliate(500);
    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->set('pointsToConvert', '200')
        ->call('convertPoints')
        ->assertHasErrors('pointsToConvert');

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(500)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);
});

test('a replayed convert request carrying the same nonce converts once', function () {
    AffiliateSetting::current()->update(['naira_per_point' => 1.0, 'min_points_conversion' => 10]);

    $affiliate = panelAffiliate(500);
    $this->actingAs($affiliate->user);

    $component = Volt::test('pages.account.affiliate');
    $nonce     = $component->get('conversionNonce');

    $component->set('pointsToConvert', '100')->call('convertPoints');

    // A double-click or retried request arrives still carrying the pre-rotation
    // nonce — the same intent, not a new one.
    $component->set('conversionNonce', $nonce)->set('pointsToConvert', '100')->call('convertPoints');

    expect(AffiliatePointConversion::count())->toBe(1)
        ->and(app(PlugPointService::class)->balance($affiliate->id))->toBe(400)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(100.0);
});

test('the nonce rotates so a deliberate second conversion still goes through', function () {
    AffiliateSetting::current()->update(['naira_per_point' => 1.0, 'min_points_conversion' => 10]);

    $affiliate = panelAffiliate(500);
    $this->actingAs($affiliate->user);

    $component = Volt::test('pages.account.affiliate');

    $component->set('pointsToConvert', '100')->call('convertPoints');
    $component->set('pointsToConvert', '150')->call('convertPoints');

    expect(AffiliatePointConversion::count())->toBe(2)
        ->and(app(PlugPointService::class)->balance($affiliate->id))->toBe(250)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(250.0);
});

test('an affiliate can submit the daily share with a screenshot inside the window', function () {
    Storage::fake('public');
    Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', 'Africa/Lagos')->utc());

    $affiliate = panelAffiliate();
    panelShareTask();

    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->set('shareReach', '640')
        ->set('shareProof', UploadedFile::fake()->image('share.jpg'))
        ->call('submitShare')
        ->assertHasNoErrors();

    $submission = AffiliateTaskSubmission::where('affiliate_id', $affiliate->id)->first();

    expect($submission)->not->toBeNull()
        ->and($submission->reported_reach)->toBe(640)
        ->and($submission->status)->toBe('submitted')
        ->and($submission->getMedia('proof'))->toHaveCount(1)
        // Still nothing credited — points only land on approval.
        ->and(app(PlugPointService::class)->balance($affiliate->id))->toBe(0);
});

test('the share form is hidden outside the submission window', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-10 05:00:00', 'Africa/Lagos')->utc());

    $affiliate = panelAffiliate();
    panelShareTask();

    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->assertSee('The window is closed right now');
});

test('a share submitted outside the window is refused even if the call is forced', function () {
    Storage::fake('public');
    Carbon::setTestNow(Carbon::parse('2026-06-10 05:00:00', 'Africa/Lagos')->utc());

    $affiliate = panelAffiliate();
    panelShareTask();

    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->set('shareReach', '640')
        ->set('shareProof', UploadedFile::fake()->image('share.jpg'))
        ->call('submitShare')
        ->assertHasErrors('shareProof');

    expect(AffiliateTaskSubmission::count())->toBe(0);
});

test('marketing material is shown with the affiliate\'s own code in the caption', function () {
    $affiliate = panelAffiliate();

    MarketingMaterial::create([
        'name'             => 'June Flyer',
        'caption_template' => 'Get yours at :link — code :code',
        'is_active'        => true,
    ]);

    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')
        ->assertSee('June Flyer')
        ->assertSee($affiliate->code);
});

test('inactive material is not shown', function () {
    $affiliate = panelAffiliate();

    MarketingMaterial::create(['name' => 'Retired Flyer', 'is_active' => false]);

    $this->actingAs($affiliate->user);

    Volt::test('pages.account.affiliate')->assertDontSee('Retired Flyer');
});
