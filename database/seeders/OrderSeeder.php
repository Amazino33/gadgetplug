<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = Vendor::where('slug', 'techhaven')->firstOrFail();
        $products = Product::where('vendor_id', $vendor->id)->get();

        if ($products->isEmpty()) {
            return;
        }

        $customers = [
            ['id' => User::where('email', 'customer1@example.com')->value('id'), 'name' => 'Bola Adesanya',  'email' => 'customer1@example.com', 'phone' => '07011112222'],
            ['id' => User::where('email', 'customer2@example.com')->value('id'), 'name' => 'Funke Oladele',  'email' => 'customer2@example.com', 'phone' => '07022223333'],
            ['id' => User::where('email', 'customer3@example.com')->value('id'), 'name' => 'Danladi Musa',   'email' => 'customer3@example.com', 'phone' => '07033334444'],
            ['id' => null, 'name' => 'Guest Buyer',     'email' => 'guest@example.com',     'phone' => '08099887766'],
            ['id' => null, 'name' => 'Ify Chukwu',      'email' => 'ify@example.com',        'phone' => '09011223344'],
        ];

        $statuses  = ['pending', 'paid', 'paid', 'shipped', 'delivered', 'delivered', 'delivered', 'cancelled'];
        $methods   = ['paystack', 'paystack', 'paystack', 'pod'];
        $addresses = [
            '12 Adeola Odeku Street, Victoria Island, Lagos',
            '45 Wuse Zone 3, Abuja',
            '7 Aba Road, Port Harcourt, Rivers State',
            '23 Obafemi Awolowo Way, Ikeja, Lagos',
            '89 Nnamdi Azikiwe Street, Enugu',
            '3 Kano Road, Kaduna',
            '55 Allen Avenue, Ikeja, Lagos',
            '10 Isaac John Street, GRA Ikeja, Lagos',
        ];

        $orderData = [
            ['customer' => 0, 'items' => [['sku' => 'APL-IP15P-128', 'qty' => 1], ['sku' => 'APL-APP2-USB', 'qty' => 1]]],
            ['customer' => 1, 'items' => [['sku' => 'SAM-GS24-256', 'qty' => 1]]],
            ['customer' => 2, 'items' => [['sku' => 'APL-MBA-M2-256', 'qty' => 1], ['sku' => 'ANK-USBC-HUB7', 'qty' => 1]]],
            ['customer' => 0, 'items' => [['sku' => 'SON-WH1000XM5', 'qty' => 1]]],
            ['customer' => 3, 'items' => [['sku' => 'TEC-C30P-256', 'qty' => 2]]],
            ['customer' => 1, 'items' => [['sku' => 'APL-IPAD-AIR5', 'qty' => 1], ['sku' => 'SPG-TA-IP15-BLK', 'qty' => 1]]],
            ['customer' => 4, 'items' => [['sku' => 'SON-DS5-WHT', 'qty' => 1], ['sku' => 'RZR-BSV2X-BLK', 'qty' => 1]]],
            ['customer' => 2, 'items' => [['sku' => 'DJI-OP3-STD', 'qty' => 1]]],
            ['customer' => 3, 'items' => [['sku' => 'XIA-SB8-BLK', 'qty' => 2], ['sku' => 'BAS-GAN65-BLK', 'qty' => 1]]],
            ['customer' => 4, 'items' => [['sku' => 'APL-AW9-41MM', 'qty' => 1]]],
        ];

        foreach ($orderData as $index => $def) {
            $customer  = $customers[$def['customer']];
            $status    = $statuses[$index % count($statuses)];
            $method    = $methods[$index % count($methods)];
            $address   = $addresses[$index % count($addresses)];

            $lineItems = [];
            $total     = 0;

            foreach ($def['items'] as $line) {
                $product = $products->firstWhere('sku', $line['sku']);
                if (! $product) {
                    continue;
                }
                $lineItems[] = ['product' => $product, 'qty' => $line['qty']];
                $total += $product->price * $line['qty'];
            }

            if (empty($lineItems)) {
                continue;
            }

            $order = Order::create([
                'user_id'          => $customer['id'],
                'reference'        => 'ORD-' . strtoupper(Str::random(8)),
                'customer_name'    => $customer['name'],
                'customer_email'   => $customer['email'],
                'customer_phone'   => $customer['phone'],
                'shipping_address' => $address,
                'total_amount'     => $total,
                'status'           => $status,
                'payment_method'   => $method,
                'created_at'       => now()->subDays(rand(1, 60)),
            ]);

            foreach ($lineItems as $line) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $line['product']->id,
                    'vendor_id'  => $vendor->id,
                    'quantity'   => $line['qty'],
                    'unit_price' => $line['product']->price,
                ]);
            }
        }
    }
}
