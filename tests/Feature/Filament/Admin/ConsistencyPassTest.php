<?php

use App\Filament\Pages\MessagingSettings;
use App\Filament\Resources\LogisticsCompanies\Pages\ManageLogisticsCompanies;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\LogisticsCompany;
use App\Models\PlatformMessagingSetting;
use App\Services\Orders\DispatchMessages;
use App\Services\Orders\DispatchService;
use App\Support\Naira;
use App\Support\OrderBadges;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Orders/Helpers.php';

// The other new admin pages, made consistent: one status colour map, money as
// ₦1,500, cards on a phone, empty states and toasts.

function actAsSuperAdminOnAdminPanel(array $c): void
{
    test()->actingAs($c['admin']);
    setPermissionsTeamId(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

test('money reads ₦1,500 — kobo only when there are kobo', function () {
    expect(Naira::format(1500))->toBe('₦1,500')
        ->and(Naira::format('281000.00'))->toBe('₦281,000')
        ->and(Naira::format(1250.5))->toBe('₦1,250.50')
        ->and(Naira::format(-700))->toBe('−₦700')
        ->and(Naira::format(null))->toBe('—');
});

test('every screen colours a status from the one shared map', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    actAsSuperAdminOnAdminPanel($c);

    expect(OrderBadges::statusColor('shipped'))->toBe('warning')
        ->and(OrderBadges::statusLabel('shipped'))->toBe('Handed to rider');

    Livewire::test(ListOrders::class)
        ->assertTableColumnFormattedStateSet('status', 'Handed to rider', $order);

    // The board's own column draws from the same map.
    $column = Livewire::test(ListOrders::class)->instance()->getTable()->getColumn('status');
    $column->record($order);

    expect($column->getColor('shipped'))->toBe(OrderBadges::statusColor('shipped'));
});

test('on a phone each dispatch order is a card with its next step as a button', function () {
    $c = ossVendor();
    $confirmed = ossOrder($c, 'pay_on_delivery', 'confirmed');
    actAsSuperAdminOnAdminPanel($c);

    Livewire::test(ListOrders::class)
        ->assertSeeHtml('gp-card-table')
        ->assertSee('#'.$confirmed->reference)
        ->assertSee('Collect ₦10,000')
        ->assertSee('Assign company')
        ->assertSee('Open order');
});

test('logistics companies show a status badge, an empty state when there are none, and a toast on save', function () {
    $c = ossVendor();
    actAsSuperAdminOnAdminPanel($c);

    Livewire::test(ManageLogisticsCompanies::class)
        ->assertSee('No logistics companies yet');

    $company = LogisticsCompany::create(['vendor_id' => null, 'name' => 'Swift Riders', 'phone' => '08031112222', 'is_active' => false]);

    Livewire::test(ManageLogisticsCompanies::class)
        ->assertTableColumnFormattedStateSet('is_active', 'Inactive', $company)
        ->callTableAction('edit', $company, ['name' => 'Swift Riders', 'phone' => '08031112222', 'is_active' => true])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Saved');

    expect($company->fresh()->is_active)->toBeTrue();
});

test('the WhatsApp page shows the health cards and confirms a save', function () {
    $c = ossVendor();
    actAsSuperAdminOnAdminPanel($c);

    Livewire::test(MessagingSettings::class)
        ->assertSuccessful()
        ->assertSee('Fallback storekeeper number')
        ->fillForm(['fallback_storekeeper_whatsapp' => '08012345678'])
        ->call('save')
        ->assertNotified('Platform WhatsApp settings saved');

    expect(PlatformMessagingSetting::current()->fallback_storekeeper_whatsapp)->toBe('08012345678');

    $this->get(MessagingSettings::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('Sent today')
        ->assertSee('Save settings');
});

test('the rider link shows the shared status badge, ₦ to collect, and big call buttons', function () {
    $c = ossVendor();
    $c['main']->update(['phone' => '08031110000']);
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    app(DispatchService::class)->assign($order, $c['admin'], LogisticsCompany::create([
        'vendor_id' => null, 'name' => 'Swift', 'phone' => '08033330000', 'is_active' => true,
    ])->id, cost: 1500, notify: false);

    $this->get(DispatchMessages::link($order->fresh()))
        ->assertOk()
        ->assertSee('Handed to rider')
        ->assertSee('Collect from the customer: ₦10,000')
        ->assertSee('Call the customer')
        ->assertSee('Call the store')
        ->assertDontSee('10,000.00');
});
