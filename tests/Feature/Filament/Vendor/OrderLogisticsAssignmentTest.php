<?php

use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\DeliveryPerson;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function setUpOrderLogisticsVendor(): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Order Logistics Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Order Logistics Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Order Logistics Product',
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'ORD-LOG-' . uniqid(),
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => 5000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $vendor->id,
        'quantity'   => 1,
        'unit_price' => 5000,
    ]);

    return compact('owner', 'vendor', 'order');
}

function actAsOrderVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('the order view page shows "not assigned yet" when no logistics is set', function () {
    $data = setUpOrderLogisticsVendor();
    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertSeeText('Not assigned yet');
});

test('vendor owner can assign a logistics company and rider to an order', function () {
    $data    = setUpOrderLogisticsVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider   = DeliveryPerson::create([
        'vendor_id'            => $data['vendor']->id,
        'logistics_company_id' => $company->id,
        'name'                 => 'John Rider',
        'phone'                => '08020000000',
    ]);

    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData([
            'logistics_company_id' => $company->id,
            'delivery_person_id'   => $rider->id,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $data['order']->refresh();

    expect($data['order']->logistics_company_id)->toBe($company->id)
        ->and($data['order']->delivery_person_id)->toBe($rider->id);
});

test('assigning logistics to an order through the page writes an activity log entry', function () {
    $data    = setUpOrderLogisticsVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);

    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData(['logistics_company_id' => $company->id, 'delivery_person_id' => null])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $activity = Activity::where('subject_id', $data['order']->id)
        ->where('subject_type', Order::class)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->vendor_id)->toBe($data['vendor']->id)
        ->and($activity->changes()['attributes']['logistics_company_id'] ?? null)->toBe($company->id);
});

test('a freelance rider (no company) can be assigned to an order without picking a company', function () {
    $data       = setUpOrderLogisticsVendor();
    $freelancer = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'name' => 'Freelance Femi', 'phone' => '08030000000']);

    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData(['logistics_company_id' => null, 'delivery_person_id' => $freelancer->id])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $data['order']->refresh();

    expect($data['order']->delivery_person_id)->toBe($freelancer->id)
        ->and($data['order']->logistics_company_id)->toBeNull();
});

test('the assignment rejects a logistics company belonging to a different vendor', function () {
    $data      = setUpOrderLogisticsVendor();
    $otherData = setUpOrderLogisticsVendor();

    $rivalCompany = LogisticsCompany::create(['vendor_id' => $otherData['vendor']->id, 'name' => 'Rival Dispatch', 'phone' => '08050000000']);

    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData(['logistics_company_id' => $rivalCompany->id, 'delivery_person_id' => null])
        ->callMountedAction()
        ->assertHasActionErrors(['logistics_company_id']);

    expect($data['order']->fresh()->logistics_company_id)->toBeNull();
});

test('the assignment rejects a rider whose company does not match the selected company', function () {
    $data     = setUpOrderLogisticsVendor();
    $companyA = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Company A', 'phone' => '08060000000']);
    $companyB = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Company B', 'phone' => '08070000000']);
    $riderB   = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $companyB->id, 'name' => 'Rider B', 'phone' => '08080000000']);

    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData(['logistics_company_id' => $companyA->id, 'delivery_person_id' => $riderB->id])
        ->callMountedAction()
        ->assertHasActionErrors(['delivery_person_id']);
});

test('the assign logistics modal is prefilled with the order\'s current assignment', function () {
    $data    = setUpOrderLogisticsVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider   = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $company->id, 'name' => 'John Rider', 'phone' => '08020000000']);

    $data['order']->update(['logistics_company_id' => $company->id, 'delivery_person_id' => $rider->id]);

    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->assertActionDataSet([
            'logistics_company_id' => $company->id,
            'delivery_person_id'   => $rider->id,
        ]);
});
