<?php

use App\Filament\Vendor\Resources\DeliveryPersons\DeliveryPersonResource;
use App\Filament\Vendor\Resources\DeliveryPersons\Pages\ManageDeliveryPersons;
use App\Filament\Vendor\Resources\LogisticsCompanies\LogisticsCompanyResource;
use App\Filament\Vendor\Resources\LogisticsCompanies\Pages\ManageLogisticsCompanies;
use App\Filament\Vendor\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Vendor\Resources\MessageTemplates\Pages\ManageMessageTemplates;
use App\Models\DeliveryPerson;
use App\Models\LogisticsCompany;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function setUpLogisticsResourceVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Logistics Resource Store']);

    return compact('owner', 'vendor');
}

test('vendor owner can access all three logistics resources', function () {
    $data = setUpLogisticsResourceVendor();

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);

    expect(LogisticsCompanyResource::canAccess())->toBeTrue()
        ->and(DeliveryPersonResource::canAccess())->toBeTrue()
        ->and(MessageTemplateResource::canAccess())->toBeTrue();
});

test('a team member without manage_logistics permission cannot access the logistics resources', function () {
    $data   = setUpLogisticsResourceVendor();
    $member = User::factory()->create();
    $data['vendor']->users()->attach($member->id);

    $this->actingAs($member);
    Filament::setTenant($data['vendor']);

    expect(LogisticsCompanyResource::canAccess())->toBeFalse()
        ->and(DeliveryPersonResource::canAccess())->toBeFalse()
        ->and(MessageTemplateResource::canAccess())->toBeFalse();
});

test('a team member with the manage_logistics permission can access the logistics resources', function () {
    $data   = setUpLogisticsResourceVendor();
    $member = User::factory()->create();
    $data['vendor']->users()->attach($member->id);

    setPermissionsTeamId($data['vendor']->id);
    $role = Role::firstOrCreate(['name' => 'dispatcher', 'guard_name' => 'web', 'team_id' => $data['vendor']->id]);
    $role->givePermissionTo('manage_logistics');
    $member->assignRole($role);

    $this->actingAs($member);
    Filament::setTenant($data['vendor']);

    expect(LogisticsCompanyResource::canAccess())->toBeTrue()
        ->and(DeliveryPersonResource::canAccess())->toBeTrue()
        ->and(MessageTemplateResource::canAccess())->toBeTrue();
});

test('vendor owner can create a logistics company through the resource form', function () {
    $data = setUpLogisticsResourceVendor();

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);

    Livewire::test(ManageLogisticsCompanies::class)
        ->mountAction('create')
        ->setActionData([
            'name'  => 'Speedy Dispatch',
            'phone' => '08010000000',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(LogisticsCompany::where('vendor_id', $data['vendor']->id)->where('name', 'Speedy Dispatch')->exists())->toBeTrue();
});

test('the delivery persons table only shows riders belonging to the current tenant', function () {
    $dataA = setUpLogisticsResourceVendor();
    $dataB = setUpLogisticsResourceVendor();

    $riderA = DeliveryPerson::create(['vendor_id' => $dataA['vendor']->id, 'name' => 'Rider Alpha', 'phone' => '08010000001']);
    DeliveryPerson::create(['vendor_id' => $dataB['vendor']->id, 'name' => 'Rider Beta', 'phone' => '08010000002']);

    $this->actingAs($dataA['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($dataA['vendor']);

    Livewire::test(ManageDeliveryPersons::class)
        ->assertSee('Rider Alpha')
        ->assertDontSee('Rider Beta');

    expect($riderA->vendor_id)->toBe($dataA['vendor']->id);
});

test('the rider form rejects a logistics company belonging to a different vendor', function () {
    $dataA = setUpLogisticsResourceVendor();
    $dataB = setUpLogisticsResourceVendor();

    $companyB = LogisticsCompany::create(['vendor_id' => $dataB['vendor']->id, 'name' => 'Rival Dispatch', 'phone' => '08020000000']);

    $this->actingAs($dataA['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($dataA['vendor']);

    Livewire::test(ManageDeliveryPersons::class)
        ->mountAction('create')
        ->setActionData([
            'name'                  => 'Cross Tenant Rider',
            'phone'                 => '08030000000',
            'logistics_company_id'  => $companyB->id,
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['logistics_company_id']);

    expect(DeliveryPerson::where('name', 'Cross Tenant Rider')->exists())->toBeFalse();
});

test('the rider form rejects an inactive logistics company for the same vendor', function () {
    $data = setUpLogisticsResourceVendor();

    $inactiveCompany = LogisticsCompany::create([
        'vendor_id' => $data['vendor']->id,
        'name'      => 'Retired Dispatch',
        'phone'     => '08040000000',
        'is_active' => false,
    ]);

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);

    Livewire::test(ManageDeliveryPersons::class)
        ->mountAction('create')
        ->setActionData([
            'name'                 => 'Rider For Retired Co',
            'phone'                => '08050000000',
            'logistics_company_id' => $inactiveCompany->id,
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['logistics_company_id']);
});

test('the rider form accepts an active logistics company belonging to the same vendor', function () {
    $data = setUpLogisticsResourceVendor();

    $company = LogisticsCompany::create([
        'vendor_id' => $data['vendor']->id,
        'name'      => 'Home Team Dispatch',
        'phone'     => '08060000000',
    ]);

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);

    Livewire::test(ManageDeliveryPersons::class)
        ->mountAction('create')
        ->setActionData([
            'name'                 => 'Rider For Home Team',
            'phone'                => '08070000000',
            'logistics_company_id' => $company->id,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryPerson::where('name', 'Rider For Home Team')->where('logistics_company_id', $company->id)->exists())->toBeTrue();
});

test('message template seeder creates the expected default templates for a vendor', function () {
    $data = setUpLogisticsResourceVendor();

    MessageTemplateSeeder::forVendor($data['vendor']);

    $keys = MessageTemplate::where('vendor_id', $data['vendor']->id)->pluck('key')->sort()->values()->all();

    expect($keys)->toBe([
        'customer_confirmed',
        'customer_delivered',
        'customer_dispatched',
        'customer_out_for_delivery',
        'customer_received',
        'rider_assignment',
        'storekeeper_cancelled',
        'storekeeper_low_stock',
        'storekeeper_new_order',
        'storekeeper_undispatched',
        'vendor_daily_summary',
    ]);
});

test('message template seeder is idempotent and never duplicates existing keys', function () {
    $data = setUpLogisticsResourceVendor();

    MessageTemplateSeeder::forVendor($data['vendor']);
    MessageTemplateSeeder::forVendor($data['vendor']);

    expect(MessageTemplate::where('vendor_id', $data['vendor']->id)->count())
        ->toBe(count(MessageTemplateSeeder::defaults()));
});

test('the seed defaults action is only visible when the vendor has no templates yet', function () {
    $data = setUpLogisticsResourceVendor();

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);

    Livewire::test(ManageMessageTemplates::class)
        ->assertActionVisible('seedDefaults');

    MessageTemplateSeeder::forVendor($data['vendor']);

    Livewire::test(ManageMessageTemplates::class)
        ->assertActionHidden('seedDefaults');
});
