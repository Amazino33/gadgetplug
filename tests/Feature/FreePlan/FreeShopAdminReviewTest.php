<?php

use App\Filament\Pages\FreePlanSettings;
use App\Filament\Resources\FreeShopApplications\FreeShopApplicationResource;
use App\Filament\Resources\FreeShopApplications\Pages\ListFreeShopApplications;
use App\Filament\Resources\FreeShopApplications\Pages\ViewFreeShopApplication;
use App\Mail\FreeShopMail;
use App\Models\FreePlanSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorStatusLog;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    (new Database\Seeders\VendorPermissionsSeeder())->run();
    Mail::fake();
});

function actingAsPlatformAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

it('lists only free shops, under the tab for their status, with a pending badge', function () {
    actingAsPlatformAdmin();

    $pending  = freeShop()['vendor'];
    $approved = freeShop(Vendor::STATUS_APPROVED)['vendor'];
    $paid     = paidShop()['vendor'];

    Livewire::test(ListFreeShopApplications::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$approved, $paid])
        ->set('activeTab', Vendor::STATUS_APPROVED)
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$pending, $paid]);

    expect(FreeShopApplicationResource::getNavigationBadge())->toBe('1');
});

it('searches by business name, WhatsApp and city', function () {
    actingAsPlatformAdmin();

    $joy  = freeShop(attributes: ['name' => 'Joy Provisions', 'whatsapp' => '2348031112222', 'city' => 'Ikeja'])['vendor'];
    $mama = freeShop(attributes: ['name' => 'Mama Put', 'whatsapp' => '2348059998888', 'city' => 'Aba'])['vendor'];

    Livewire::test(ListFreeShopApplications::class)
        ->searchTable('Joy')->assertCanSeeTableRecords([$joy])->assertCanNotSeeTableRecords([$mama])
        ->searchTable('0805 999')->assertCanSeeTableRecords([$mama])->assertCanNotSeeTableRecords([$joy])
        ->searchTable('Ikeja')->assertCanSeeTableRecords([$joy])->assertCanNotSeeTableRecords([$mama]);
});

it('keeps everyone but the platform admin out', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop();

    // The app's 403 handler answers a refused GET with a redirect elsewhere,
    // so the check is that the page itself never renders.
    foreach ([
        FreeShopApplicationResource::getUrl('index', panel: 'admin'),
        FreeShopApplicationResource::getUrl('view', ['record' => $vendor->id], panel: 'admin'),
        FreePlanSettings::getUrl(panel: 'admin'),
    ] as $url) {
        $response = $this->actingAs($owner)->get($url);

        expect($response->status())->toBeIn([302, 403])
            ->and($response->headers->get('Location'))->not->toBe($url);
        $response->assertDontSee('Free Shop Applications');
    }
});

it('shows the admin everything that was submitted', function () {
    actingAsPlatformAdmin();
    $vendor = freeShop(attributes: ['social_link' => 'https://instagram.com/joy', 'verification_photo' => 'vendor-verification/x.jpg'])['vendor'];

    $this->get(FreeShopApplicationResource::getUrl('view', ['record' => $vendor->id]))
        ->assertOk()
        ->assertSee($vendor->name)
        ->assertSee('https://instagram.com/joy')
        ->assertSee('https://wa.me/'.$vendor->whatsapp, false)
        ->assertSee(route('free-shop.verification-photo', ['vendor' => $vendor->id]), false)
        ->assertSee('Status history');
});

it('approves a shop, records who and when, and emails it', function () {
    $admin  = actingAsPlatformAdmin();
    ['owner' => $owner, 'vendor' => $vendor] = freeShop();

    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->callAction('approve')
        ->assertNotified('Shop approved');

    $vendor->refresh();
    expect($vendor->status)->toBe('approved')
        ->and($vendor->approved_by)->toBe($admin->id)
        ->and($vendor->approved_at)->not->toBeNull();

    $log = VendorStatusLog::where('vendor_id', $vendor->id)->latest('id')->first();
    expect($log->from_status)->toBe('pending')->and($log->to_status)->toBe('approved')->and($log->changed_by)->toBe($admin->id);

    Mail::assertQueued(FreeShopMail::class, fn ($m) => $m->hasTo($owner->email));
});

it('needs a reason to reject, and shows it to the shop', function () {
    actingAsPlatformAdmin();
    $vendor = freeShop()['vendor'];

    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->callAction('reject', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($vendor->fresh()->status)->toBe('pending');

    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->callAction('reject', data: ['reason' => 'We could not verify the business.']);

    expect($vendor->fresh()->status)->toBe('rejected')
        ->and($vendor->fresh()->review_note)->toBe('We could not verify the business.');
});

it('asks for more info while keeping the shop pending', function () {
    actingAsPlatformAdmin();
    $vendor = freeShop()['vendor'];

    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->callAction('requestInfo', data: ['message' => ''])
        ->assertHasActionErrors(['message' => 'required']);

    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->callAction('requestInfo', data: ['message' => 'Send a photo of the shop front.']);

    $vendor->refresh();
    expect($vendor->status)->toBe('pending')
        ->and($vendor->info_requested)->toBeTrue()
        ->and($vendor->review_note)->toBe('Send a photo of the shop front.')
        ->and(VendorStatusLog::where('vendor_id', $vendor->id)->latest('id')->first()->note)->toContain('More info requested');
});

it('suspends an approved shop with a reason and reinstates it', function () {
    actingAsPlatformAdmin();
    $vendor = freeShop(Vendor::STATUS_APPROVED)['vendor'];

    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->assertActionHidden('approve')
        ->callAction('suspend', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->callAction('suspend', data: ['reason' => 'Selling counterfeit goods.']);

    expect($vendor->fresh()->status)->toBe('suspended');

    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->assertActionHidden('suspend')
        ->callAction('reinstate');

    expect($vendor->fresh()->status)->toBe('approved')
        ->and(VendorStatusLog::where('vendor_id', $vendor->id)->pluck('to_status')->all())
        ->toBe(['suspended', 'approved']);
});

it('refuses an action the shop has already moved past', function () {
    $admin  = actingAsPlatformAdmin();
    $vendor = freeShop()['vendor'];

    $service = app(App\Services\FreePlan\FreeShopApplications::class);

    expect($service->approve($vendor, $admin))->toBeTrue()
        ->and($service->reject($vendor->fresh(), 'late', $admin))->toBeFalse()
        ->and($service->suspend(paidShop()['vendor'], 'x', $admin))->toBeFalse();
});

it('saves the free plan settings', function () {
    actingAsPlatformAdmin();

    Livewire::test(FreePlanSettings::class)
        ->assertSet('data.free_plan_product_limit', 50)
        ->assertSet('data.free_plan_photos_per_product', 3)
        ->set('data.free_plan_product_limit', 80)
        ->set('data.free_plan_photos_per_product', 5)
        ->set('data.admin_notification_email', 'reviews@gadgetplug.com.ng')
        ->call('save')
        ->assertHasNoErrors();

    expect(FreePlanSetting::productLimit())->toBe(80)
        ->and(FreePlanSetting::photosPerProduct())->toBe(5)
        ->and(FreePlanSetting::adminEmail())->toBe('reviews@gadgetplug.com.ng');
});

it('opens the review page from the Review link, which carries the id and not the link name', function () {
    actingAsPlatformAdmin();

    $vendor = freeShop(attributes: ['slug' => 'thrift-urban'])['vendor'];

    $url = FreeShopApplicationResource::getUrl('view', ['record' => $vendor]);

    expect($url)->toEndWith('/free-shop-applications/'.$vendor->id)
        ->and($url)->not->toContain('thrift-urban');

    // The table's Review button, as the admin clicks it.
    Livewire::test(ListFreeShopApplications::class)
        ->assertTableActionHasUrl('view', $url, $vendor);

    $this->get($url)->assertOk()->assertSee($vendor->name);
});
