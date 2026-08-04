<?php

use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\DeliveryPerson;
use App\Models\LogisticsCompany;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Messaging\TemplateRenderer;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setUpContentOrder(string $status = 'pending', string $paymentMethod = 'paystack'): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Content Test Store']);
    MessageTemplateSeeder::forVendor($vendor);

    $category = Category::create(['name' => 'Content Category']);

    $phone = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'iPhone 13 Pro', 'price' => 450000, 'stock_quantity' => 10, 'status' => 'published',
    ]);
    $buds = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'AirPods Pro', 'price' => 85000, 'stock_quantity' => 10, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'GP-CONTENT01',
        'customer_name' => 'Jane Customer',
        'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000',
        'shipping_address' => 'Uyo, Akwa Ibom State — 12 Aka Road',
        'total_amount' => 985000,
        'status' => $status,
        'payment_method' => $paymentMethod,
    ]);

    OrderItem::create(['order_id' => $order->id, 'product_id' => $phone->id, 'vendor_id' => $vendor->id, 'quantity' => 2, 'unit_price' => 450000]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $buds->id, 'vendor_id' => $vendor->id, 'quantity' => 1, 'unit_price' => 85000]);

    return compact('owner', 'vendor', 'order');
}

// --- Placeholder content ------------------------------------------------

test('order_items lists every line with quantity, name and line total', function () {
    $data = setUpContentOrder();

    $context = TemplateRenderer::contextForOrder($data['order']);

    expect($context['order_items'])
        ->toBe("• 2 x iPhone 13 Pro — ₦900,000.00\n• 1 x AirPods Pro — ₦85,000.00")
        ->and($context['item_count'])->toBe('3')
        ->and($context['total'])->toBe('₦985,000.00')
        ->and($context['delivery_address'])->toBe('Uyo, Akwa Ibom State — 12 Aka Road');
});

test('order_items uses the price stored on the line, not the product current price', function () {
    $data = setUpContentOrder();

    Product::where('name', 'AirPods Pro')->update(['price' => 999999]);

    $context = TemplateRenderer::contextForOrder($data['order']->fresh());

    expect($context['order_items'])->toContain('1 x AirPods Pro — ₦85,000.00');
});

// order_items.product_id is cascadeOnDelete, so deleting a product removes the
// line from order history outright rather than orphaning it. Pinned here because
// the message body silently changes when a vendor deletes a product.
test('deleting a product drops its line from the message entirely', function () {
    $data = setUpContentOrder();

    Product::where('name', 'AirPods Pro')->delete();

    $context = TemplateRenderer::contextForOrder($data['order']->fresh());

    expect($context['order_items'])
        ->toContain('2 x iPhone 13 Pro — ₦900,000.00')
        ->not->toContain('AirPods Pro');
});

test('an order with no items renders an empty list rather than erroring', function () {
    $data = setUpContentOrder();
    OrderItem::where('order_id', $data['order']->id)->delete();

    $context = TemplateRenderer::contextForOrder($data['order']->fresh());

    expect($context['order_items'])->toBe('')
        ->and($context['item_count'])->toBe('0');
});

test('payment_method reads in plain language', function () {
    expect(TemplateRenderer::contextForOrder(setUpContentOrder('pending', 'pay_on_delivery')['order'])['payment_method'])
        ->toBe('Pay on delivery');
});

// --- rider_line ---------------------------------------------------------

test('rider_line falls back to a generic sentence when no rider is assigned', function () {
    $data = setUpContentOrder();

    expect(TemplateRenderer::contextForOrder($data['order'])['rider_line'])
        ->toBe('Our dispatch rider will call you soon to arrange delivery.');
});

test('rider_line names the rider and company once assigned', function () {
    $data = setUpContentOrder();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $company->id, 'name' => 'John Rider', 'phone' => '08020000000']);

    $data['order']->update(['logistics_company_id' => $company->id, 'delivery_person_id' => $rider->id]);

    expect(TemplateRenderer::contextForOrder($data['order']->fresh())['rider_line'])
        ->toBe('Your dispatch rider John Rider (08020000000) will call you soon — delivered by Speedy Dispatch.');
});

// --- Rendered messages --------------------------------------------------

test('the order-received message contains items, location, total, greeting and appreciation', function () {
    $data = setUpContentOrder();

    $template = MessageTemplate::where('vendor_id', $data['vendor']->id)->where('key', 'customer_received')->firstOrFail();
    $rendered = app(TemplateRenderer::class)->render($template->body, TemplateRenderer::contextForOrder($data['order']));

    expect($rendered)
        ->toContain('Hello Jane Customer')                      // greeting
        ->toContain('GP-CONTENT01')                             // order number
        ->toContain('2 x iPhone 13 Pro — ₦900,000.00')          // items
        ->toContain('1 x AirPods Pro — ₦85,000.00')
        ->toContain('₦985,000.00')                              // price to be paid
        ->toContain('Uyo, Akwa Ibom State — 12 Aka Road')       // location
        ->toContain('dispatch rider will call you')             // rider promise
        ->toContain('appreciate')                               // appreciation
        ->toContain('GadgetPlug')
        ->and($rendered)->not->toContain('{{');                 // nothing left unsubstituted
});

test('no default customer template leaves an unsubstituted placeholder', function () {
    $data = setUpContentOrder();
    $renderer = app(TemplateRenderer::class);
    $context = TemplateRenderer::contextForOrder($data['order']);

    $templates = MessageTemplate::where('vendor_id', $data['vendor']->id)->get();

    expect($templates)->not->toBeEmpty();

    foreach ($templates as $template) {
        expect($renderer->render($template->body, $context))
            ->not->toContain('{{', "Template [{$template->key}] left a placeholder unrendered.");
    }
});

// --- Observer firing ----------------------------------------------------

test('paying an order sends the order-received message automatically', function () {
    $data = setUpContentOrder('pending');

    $data['order']->update(['status' => 'paid']);

    $message = DeliveryMessage::where('order_id', $data['order']->id)->first();

    expect($message)->not->toBeNull()
        ->and($message->recipient_type)->toBe('customer')
        ->and($message->to_number)->toBe('2348040000000')
        ->and($message->body)->toContain('We have received your order')
        ->and($message->body)->toContain('2 x iPhone 13 Pro')
        ->and($message->status)->toBe('sent');
});

test('a pay-on-delivery order confirming at checkout also sends the order-received message', function () {
    $data = setUpContentOrder('pending', 'pay_on_delivery');

    $data['order']->update(['status' => 'confirmed']);

    $message = DeliveryMessage::where('order_id', $data['order']->id)->first();

    expect($message)->not->toBeNull()
        ->and($message->body)->toContain('We have received your order')
        ->and($message->body)->toContain('Pay on delivery');
});

// A Paystack order sits at 'pending' between form submit and payment clearing.
test('an order left pending sends nothing', function () {
    $data = setUpContentOrder('pending');

    $data['order']->update(['shipping_address' => 'Uyo, Akwa Ibom State — 99 Other Road']);

    expect(DeliveryMessage::where('order_id', $data['order']->id)->count())->toBe(0);
});

test('the order-received message is not sent twice when a paid order is updated again', function () {
    $data = setUpContentOrder('pending');

    $data['order']->update(['status' => 'paid']);
    $data['order']->fresh()->update(['shipping_address' => 'Uyo, Akwa Ibom State — 99 Other Road']);

    expect(DeliveryMessage::where('order_id', $data['order']->id)->count())->toBe(1);
});
