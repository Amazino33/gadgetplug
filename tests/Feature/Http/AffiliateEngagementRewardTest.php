<?php

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\Affiliate\ClickRewardService;
use App\Services\Affiliate\WalletService;

// A believable desktop UA — the bot filter rejects blank and crawler agents,
// so every request that is meant to earn must carry one.
const HUMAN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

function makeEngagementProduct(): Product
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create([
        'user_id'              => $owner->id,
        'name'                 => 'Engagement Store ' . uniqid(),
        'online_sales_enabled' => true,
    ]);
    $category = Category::create(['name' => 'Engagement Category ' . uniqid()]);

    // show_online + the vendor's online_sales_enabled are what scopeVisibleOnline
    // gates on — without both the storefront product page 404s.
    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Engagement Product',
        'price'          => 1000,
        'stock_quantity' => 10,
        'status'         => 'published',
        'show_online'    => true,
    ]);
}

function makeEngagementAffiliate(): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $affiliate->update(['is_active' => true]);

    return $affiliate;
}

/** Walks the real browser path: click the link, land, then browse on. */
function browseAs(array $urls): void
{
    foreach ($urls as $url) {
        test()->withHeaders(['User-Agent' => HUMAN_UA])->get($url);
    }
}

test('landing on one page only pays nothing — the click stays unresolved', function () {
    $affiliate = makeEngagementAffiliate();

    browseAs(["/r/{$affiliate->code}", '/']);

    $click = AffiliateClick::where('affiliate_id', $affiliate->id)->first();

    expect($click->page_views)->toBe(1)
        ->and($click->qualified_at)->toBeNull()
        ->and($click->reward_amount)->toBeNull()
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);
});

test('a second page load qualifies the click and credits the reward', function () {
    $affiliate = makeEngagementAffiliate();
    $product   = makeEngagementProduct();

    browseAs(["/r/{$affiliate->code}", '/', "/product/{$product->slug}"]);

    $click = AffiliateClick::where('affiliate_id', $affiliate->id)->first();

    expect($click->page_views)->toBe(2)
        ->and($click->qualified_at)->not->toBeNull()
        ->and((float) $click->reward_amount)->toBe(2.0)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(2.0);

    $credit = WalletTransaction::where('affiliate_click_id', $click->id)->first();

    expect($credit)->not->toBeNull()
        ->and($credit->type)->toBe('credit')
        ->and((float) $credit->amount)->toBe(2.0);
});

test('browsing on past the second page never pays twice', function () {
    $affiliate = makeEngagementAffiliate();
    $product   = makeEngagementProduct();

    browseAs(["/r/{$affiliate->code}", '/', "/product/{$product->slug}", '/', '/cart', '/']);

    expect(WalletTransaction::where('affiliate_id', $affiliate->id)->count())->toBe(1)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(2.0);
});

test('the reward amount is whatever the admin set', function () {
    AffiliateSetting::current()->update(['click_reward_amount' => 5.00]);

    $affiliate = makeEngagementAffiliate();

    browseAs(["/r/{$affiliate->code}", '/', '/cart']);

    expect(app(WalletService::class)->availableBalance($affiliate->id))->toBe(5.0);
});

test('turning engaged visit rewards off still records engagement but pays nothing', function () {
    AffiliateSetting::current()->update(['click_rewards_enabled' => false]);

    $affiliate = makeEngagementAffiliate();

    browseAs(["/r/{$affiliate->code}", '/', '/cart']);

    $click = AffiliateClick::where('affiliate_id', $affiliate->id)->first();

    expect($click->qualified_at)->not->toBeNull()
        ->and((float) $click->reward_amount)->toBe(0.0)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);
});

test('an affiliate browsing under their own link earns nothing', function () {
    $affiliate = makeEngagementAffiliate();

    $this->actingAs($affiliate->user);

    browseAs(["/r/{$affiliate->code}", '/', '/cart']);

    expect(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);
});

test('an inactive affiliate earns nothing — the link does not even log a click', function () {
    $affiliate = makeEngagementAffiliate();
    $affiliate->update(['is_active' => false]);

    browseAs(["/r/{$affiliate->code}", '/', '/cart']);

    expect(AffiliateClick::where('affiliate_id', $affiliate->id)->count())->toBe(0)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);
});

test('a crawler user agent is recorded as engaged but never paid', function () {
    $affiliate = makeEngagementAffiliate();

    foreach (["/r/{$affiliate->code}", '/', '/cart'] as $url) {
        $this->withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])->get($url);
    }

    $click = AffiliateClick::where('affiliate_id', $affiliate->id)->first();

    expect($click->qualified_at)->not->toBeNull()
        ->and((float) $click->reward_amount)->toBe(0.0)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);
});

test('the per-IP daily limit stops the same visitor farming the reward', function () {
    $affiliate = makeEngagementAffiliate();

    // Same IP, two separate sessions — a second run of the whole funnel.
    browseAs(["/r/{$affiliate->code}", '/', '/cart']);

    $this->flushSession();

    browseAs(["/r/{$affiliate->code}", '/', '/cart']);

    expect(AffiliateClick::where('affiliate_id', $affiliate->id)->count())->toBe(2)
        ->and(AffiliateClick::where('affiliate_id', $affiliate->id)->rewarded()->count())->toBe(1)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(2.0);
});

test('the daily cap bounds what traffic can pay in one day', function () {
    AffiliateSetting::current()->update([
        'click_reward_daily_cap'      => 3.00,
        'click_reward_daily_ip_limit' => 10,
    ]);

    $affiliate = makeEngagementAffiliate();

    // Two funnels at ₦2 each would be ₦4 — the cap pays the ₦1 remainder
    // rather than overshooting.
    browseAs(["/r/{$affiliate->code}", '/', '/cart']);
    $this->flushSession();
    browseAs(["/r/{$affiliate->code}", '/', '/cart']);
    $this->flushSession();
    browseAs(["/r/{$affiliate->code}", '/', '/cart']);

    expect(app(WalletService::class)->availableBalance($affiliate->id))->toBe(3.0)
        ->and(app(ClickRewardService::class)->paidToday($affiliate->id))->toBe(3.0);
});

test('a failed page load is not a landing and does not count', function () {
    $affiliate = makeEngagementAffiliate();

    browseAs(["/r/{$affiliate->code}", '/', '/product/no-such-product-slug']);

    $click = AffiliateClick::where('affiliate_id', $affiliate->id)->first();

    expect($click->page_views)->toBe(1)
        ->and($click->qualified_at)->toBeNull()
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);
});

test('a visitor with no referral click in session costs nothing and earns nothing', function () {
    browseAs(['/', '/cart']);

    expect(AffiliateClick::count())->toBe(0)
        ->and(WalletTransaction::count())->toBe(0);
});
