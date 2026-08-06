<?php

use App\Jobs\DemoteInactiveAffiliatesJob;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateLevel;
use App\Models\AffiliateSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\AffiliateLevelProgressionService;
use Carbon\CarbonInterface;
use Spatie\Activitylog\Models\Activity;

function makeDemotionLevels(): array
{
    return [
        'bronze' => AffiliateLevel::create(['name' => 'Bronze', 'target' => 0,      'rate_value' => 1.0, 'sort_order' => 0]),
        'silver' => AffiliateLevel::create(['name' => 'Silver', 'target' => 50000,  'rate_value' => 1.1, 'sort_order' => 1]),
        'gold'   => AffiliateLevel::create(['name' => 'Gold',   'target' => 200000, 'rate_value' => 1.2, 'sort_order' => 2]),
    ];
}

function makeDemotionAffiliate(AffiliateLevel $level, CarbonInterface $levelAchievedAt): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    $affiliate->update([
        'affiliate_level_id' => $level->id,
        'level_achieved_at'  => $levelAchievedAt,
    ]);

    // Backdate signup too — with no sale on record at all, the job falls back
    // to created_at as "last known activity", and a freshly-made test
    // affiliate would otherwise look active as of right now.
    $affiliate->timestamps = false;
    $affiliate->created_at = now()->subDays(365);
    $affiliate->save();

    return $affiliate->fresh();
}

function makeDemotionAvailableCommission(Affiliate $affiliate, CarbonInterface $availableAt): AffiliateCommission
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Demotion Store ' . uniqid()]);
    $category = Category::create(['name' => 'Demotion Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Demotion Product',
        'price'          => 1000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'customer_name'    => 'Test Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => 1000,
        'status'           => 'pending',
        'payment_method'   => 'paystack',
    ]);

    return AffiliateCommission::create([
        'affiliate_id' => $affiliate->id,
        'order_id'     => $order->id,
        'amount'       => 100,
        'status'       => 'available',
        'available_at' => $availableAt,
    ]);
}

test('an affiliate inactive past the window is dropped exactly one level', function () {
    AffiliateSetting::current()->update(['inactivity_demotion_days' => 21]);
    $levels = makeDemotionLevels();

    $affiliate = makeDemotionAffiliate($levels['gold'], now()->subDays(30));

    (new DemoteInactiveAffiliatesJob())->handle(app(AffiliateLevelProgressionService::class));

    expect($affiliate->fresh('level')->level->name)->toBe('Silver');
});

test('an affiliate with a recent qualifying sale is not demoted', function () {
    AffiliateSetting::current()->update(['inactivity_demotion_days' => 21]);
    $levels = makeDemotionLevels();

    $affiliate = makeDemotionAffiliate($levels['gold'], now()->subDays(30));
    makeDemotionAvailableCommission($affiliate, now()->subDays(2));

    (new DemoteInactiveAffiliatesJob())->handle(app(AffiliateLevelProgressionService::class));

    expect($affiliate->fresh('level')->level->name)->toBe('Gold');
});

test('an affiliate already at the lowest level is never demoted further', function () {
    AffiliateSetting::current()->update(['inactivity_demotion_days' => 21]);
    $levels = makeDemotionLevels();

    $affiliate = makeDemotionAffiliate($levels['bronze'], now()->subDays(365));

    (new DemoteInactiveAffiliatesJob())->handle(app(AffiliateLevelProgressionService::class));

    expect($affiliate->fresh('level')->level->name)->toBe('Bronze')
        ->and(Activity::where('description', 'Affiliate demoted for inactivity')->count())->toBe(0);
});

test('running the demotion job twice in a row does not cascade twice on the same stale inactivity', function () {
    AffiliateSetting::current()->update(['inactivity_demotion_days' => 21]);
    $levels = makeDemotionLevels();

    $affiliate = makeDemotionAffiliate($levels['gold'], now()->subDays(30));
    $progression = app(AffiliateLevelProgressionService::class);

    (new DemoteInactiveAffiliatesJob())->handle($progression);
    expect($affiliate->fresh('level')->level->name)->toBe('Silver');

    (new DemoteInactiveAffiliatesJob())->handle($progression);
    expect($affiliate->fresh('level')->level->name)->toBe('Silver');
});

test('continued inactivity across another full window cascades down one more level', function () {
    AffiliateSetting::current()->update(['inactivity_demotion_days' => 21]);
    $levels = makeDemotionLevels();

    $affiliate = makeDemotionAffiliate($levels['gold'], now()->subDays(30));
    $progression = app(AffiliateLevelProgressionService::class);

    (new DemoteInactiveAffiliatesJob())->handle($progression);
    expect($affiliate->fresh('level')->level->name)->toBe('Silver');

    // Simulate another full inactivity window having elapsed since that demotion.
    $affiliate->fresh()->update(['level_achieved_at' => now()->subDays(25)]);

    (new DemoteInactiveAffiliatesJob())->handle($progression);
    expect($affiliate->fresh('level')->level->name)->toBe('Bronze');
});
