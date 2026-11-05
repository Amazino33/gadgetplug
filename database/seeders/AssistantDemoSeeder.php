<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AssistantGlobalSetting;
use App\Models\AssistantSetting;
use App\Models\Order;
use App\Models\OrderItemStoreAllocation;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSession;
use App\Models\PosZReport;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayout;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AssistantDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Vendor User & Stores
        $user = User::firstOrCreate(
            ['email' => 'vendor@gadgetplug.com'],
            [
                'name' => 'Chidi Okonkwo',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'phone' => '08012345678',
                'pos_pin' => Hash::make('1234'),
            ]
        );

        $vendor = Vendor::firstOrCreate(
            ['slug' => 'techhaven'],
            [
                'user_id' => $user->id,
                'name' => 'TechHaven',
                'email' => 'vendor@gadgetplug.com',
                'phone' => '08012345678',
                'status' => 'approved',
                'business_type' => 'registered_business',
            ]
        );

        // Ensure at least two branches with perfectly aligned IDs, names, and slugs:
        // Store 1: Name = "Abuja Branch", Slug = "abuja-branch"
        // Store 2: Name = "Lagos Flagship", Slug = "lagos-flagship"
        $abujaStore = Store::where('vendor_id', $vendor->id)->orderBy('id')->first();
        if (! $abujaStore) {
            $abujaStore = Store::create([
                'vendor_id' => $vendor->id,
                'name' => 'Abuja Branch',
                'slug' => 'abuja-branch',
                'phone' => '08098765432',
                'address' => '45 Adetokunbo Ademola Crescent, Wuse 2, Abuja',
                'is_active' => true,
                'created_at' => now()->subDays(90),
            ]);
        } else {
            $abujaStore->update([
                'name' => 'Abuja Branch',
                'slug' => 'abuja-branch',
                'phone' => '08098765432',
                'address' => '45 Adetokunbo Ademola Crescent, Wuse 2, Abuja',
                'created_at' => now()->subDays(90),
                'is_active' => true,
            ]);
        }

        $lagosStore = Store::where('vendor_id', $vendor->id)->where('id', '!=', $abujaStore->id)->orderBy('id')->first();
        if (! $lagosStore) {
            $lagosStore = Store::create([
                'vendor_id' => $vendor->id,
                'name' => 'Lagos Flagship',
                'slug' => 'lagos-flagship',
                'phone' => '08012345678',
                'address' => '12 Adeola Odeku, Victoria Island, Lagos',
                'is_active' => true,
                'created_at' => now()->subDays(90),
            ]);
        } else {
            $lagosStore->update([
                'name' => 'Lagos Flagship',
                'slug' => 'lagos-flagship',
                'phone' => '08012345678',
                'address' => '12 Adeola Odeku, Victoria Island, Lagos',
                'created_at' => now()->subDays(90),
                'is_active' => true,
            ]);
        }

        // Attach stores to vendor user
        $user->stores()->syncWithoutDetaching([$lagosStore->id, $abujaStore->id]);

        // 2. Configure Assistant Settings
        AssistantSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'active_store_id' => $lagosStore->id,
                'admin_disabled' => false,
                'vendor_disabled' => false,
            ]
        );

        $globalSettings = AssistantGlobalSetting::current();
        $globalSettings->update([
            'is_enabled' => true,
            'daily_message_cap' => 100,
            'daily_cost_cap_micros' => 200000,
            'model_name' => 'openai/gpt-4o-mini',
            'max_tokens' => 800,
            'history_window' => 20,
        ]);

        // 3. Ensure Products & Stock Allocation
        $products = Product::where('vendor_id', $vendor->id)->get();

        if ($products->isEmpty()) {
            // Seed a starter product set if none exist
            $sampleProducts = [
                ['name' => 'Apple iPhone 15 Pro Max 256GB', 'sku' => 'APL-IP15PM-256', 'price' => 1350000, 'cost_price' => 1100000, 'stock' => 12],
                ['name' => 'Apple MacBook Air 13" M2', 'sku' => 'APL-MBA-M2-256', 'price' => 1150000, 'cost_price' => 950000, 'stock' => 8],
                ['name' => 'Apple AirPods Pro 2 (USB-C)', 'sku' => 'APL-APP2-USB', 'price' => 290000, 'cost_price' => 220000, 'stock' => 25],
                ['name' => 'Samsung Galaxy S24 Ultra', 'sku' => 'SAM-GS24-256', 'price' => 890000, 'cost_price' => 750000, 'stock' => 10],
                ['name' => 'Sony WH-1000XM5 Wireless Headphones', 'sku' => 'SON-WH1000XM5', 'price' => 380000, 'cost_price' => 310000, 'stock' => 14],
                ['name' => 'Anker 65W GaN Fast Charger', 'sku' => 'ANK-GAN65-BLK', 'price' => 35000, 'cost_price' => 24000, 'stock' => 2], // Low stock
                ['name' => 'Spigen Tough Armor Case for iPhone 15', 'sku' => 'SPG-TA-IP15', 'price' => 18000, 'cost_price' => 11000, 'stock' => 3], // Low stock
                ['name' => 'Razer BlackShark V2 X Gaming Headset', 'sku' => 'RZR-BSV2X-BLK', 'price' => 65000, 'cost_price' => 45000, 'stock' => 0], // Out of stock
                ['name' => 'DJI Osmo Pocket 3 Creator Combo', 'sku' => 'DJI-OP3-STD', 'price' => 580000, 'cost_price' => 480000, 'stock' => 6],
            ];

            foreach ($sampleProducts as $pData) {
                $p = Product::create([
                    'vendor_id' => $vendor->id,
                    'name' => $pData['name'],
                    'slug' => Str::slug($pData['name']),
                    'sku' => $pData['sku'],
                    'price' => $pData['price'],
                    'cost_price' => $pData['cost_price'],
                    'is_active' => true,
                    'is_published' => true,
                ]);
                $products->push($p);
            }
        }

        // Allocate stocks to Lagos Flagship and Abuja Branch
        foreach ($products as $index => $product) {
            $stockQty = match ($product->sku) {
                'ANK-GAN65-BLK', 'ANK-USBC-HUB7' => 2, // Low stock test
                'SPG-TA-IP15', 'SPG-TA-IP15-BLK' => 3, // Low stock test
                'RZR-BSV2X-BLK' => 0,                  // Out of stock test
                default => 8 + ($index % 12),
            };

            ProductStoreStock::updateOrCreate(
                [
                    'store_id' => $lagosStore->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $stockQty,
                    'reserved' => 0,
                ]
            );

            // Stock in Abuja Branch
            ProductStoreStock::updateOrCreate(
                [
                    'store_id' => $abujaStore->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => max(1, (int) round($stockQty * 0.6)),
                    'reserved' => 0,
                ]
            );
        }

        // 4. Seed Completed POS Sales (Today, Yesterday, Last 7 Days)
        PosSale::where('store_id', $lagosStore->id)->delete();

        $saleDefs = [
            ['days_ago' => 0, 'product' => $products->first(), 'qty' => 1, 'payment' => 'bank_transfer'],
            ['days_ago' => 1, 'product' => $products->get(1) ?? $products->first(), 'qty' => 1, 'payment' => 'card'],
            ['days_ago' => 1, 'product' => $products->get(2) ?? $products->first(), 'qty' => 2, 'payment' => 'cash'],
            ['days_ago' => 2, 'product' => $products->get(3) ?? $products->first(), 'qty' => 1, 'payment' => 'card'],
            ['days_ago' => 3, 'product' => $products->get(4) ?? $products->first(), 'qty' => 1, 'payment' => 'cash'],
            ['days_ago' => 4, 'product' => $products->get(0) ?? $products->first(), 'qty' => 1, 'payment' => 'bank_transfer'],
            ['days_ago' => 6, 'product' => $products->get(2) ?? $products->first(), 'qty' => 1, 'payment' => 'cash'],
            ['days_ago' => 12, 'product' => $products->get(1) ?? $products->first(), 'qty' => 1, 'payment' => 'card'],
        ];

        foreach ($saleDefs as $sIndex => $sDef) {
            $p = $sDef['product'];
            $qty = $sDef['qty'];
            $subtotal = (float) ($p->price * $qty);
            $completedAt = now()->subDays($sDef['days_ago'])->setHour(14)->setMinute($sIndex * 7);

            $sale = PosSale::create([
                'reference' => 'POS-SALE-'.strtoupper(Str::random(8)),
                'vendor_id' => $vendor->id,
                'store_id' => $lagosStore->id,
                'cashier_id' => $user->id,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'vat_amount' => 0,
                'total' => $subtotal,
                'payment_method' => $sDef['payment'],
                'amount_tendered' => $subtotal,
                'change_given' => 0,
                'status' => 'completed',
                'synced' => true,
                'synced_at' => $completedAt,
                'completed_at' => $completedAt,
                'created_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

            PosSaleItem::create([
                'pos_sale_id' => $sale->id,
                'product_id' => $p->id,
                'product_name' => $p->name,
                'product_sku' => $p->sku,
                'unit_price' => $p->price,
                'unit_cost' => $p->cost_price ?: round($p->price * 0.8),
                'cost_total' => ($p->cost_price ?: round($p->price * 0.8)) * $qty,
                'quantity' => $qty,
                'discount_amount' => 0,
                'total' => $subtotal,
                'created_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
        }

        // 5. Connect Online Orders to Lagos Flagship
        $orders = Order::where('status', '!=', 'cancelled')->limit(5)->get();
        foreach ($orders as $order) {
            $order->update(['revenue_recognized_at' => now()->subDays(2)]);
            foreach ($order->items as $item) {
                OrderItemStoreAllocation::updateOrCreate(
                    [
                        'order_item_id' => $item->id,
                        'store_id' => $lagosStore->id,
                    ],
                    [
                        'quantity' => $item->quantity,
                    ]
                );
            }
        }

        // 6. Seed POS Sessions & Cash-Ups (PosZReport)
        PosZReport::where('vendor_id', $vendor->id)->delete();
        PosSession::where('vendor_id', $vendor->id)->delete();

        $sessionYesterday = PosSession::create([
            'vendor_id' => $vendor->id,
            'store_id' => $lagosStore->id,
            'cashier_id' => $user->id,
            'business_date' => now()->subDay()->toDateString(),
            'opening_float' => 20000.00,
            'closing_float' => 20000.00,
            'counted_cash' => 250000.00,
            'expected_cash' => 250000.00,
            'cash_variance' => 0.00,
            'status' => 'closed',
            'opened_at' => now()->subDay()->setHour(9)->setMinute(0),
            'closed_at' => now()->subDay()->setHour(19)->setMinute(0),
            'created_at' => now()->subDay()->setHour(19)->setMinute(0),
        ]);

        PosZReport::create([
            'vendor_id' => $vendor->id,
            'pos_session_id' => $sessionYesterday->id,
            'cashier_id' => $user->id,
            'report_date' => now()->subDay()->toDateString(),
            'cash_sales' => 250000.00,
            'card_sales' => 500000.00,
            'bank_transfer_sales' => 350000.00,
            'total_sales' => 1100000.00,
            'transaction_count' => 5,
            'opening_float' => 20000.00,
            'cash_expected' => 250000.00,
            'cash_counted' => 250000.00,
            'cash_variance' => 0.00,
            'generated_at' => now()->subDay()->setHour(19)->setMinute(5),
            'created_at' => now()->subDay()->setHour(19)->setMinute(5),
        ]);

        $session3DaysAgo = PosSession::create([
            'vendor_id' => $vendor->id,
            'store_id' => $lagosStore->id,
            'cashier_id' => $user->id,
            'business_date' => now()->subDays(3)->toDateString(),
            'opening_float' => 20000.00,
            'closing_float' => 20000.00,
            'counted_cash' => 179500.00,
            'expected_cash' => 180000.00,
            'cash_variance' => -500.00,
            'status' => 'closed',
            'opened_at' => now()->subDays(3)->setHour(9)->setMinute(0),
            'closed_at' => now()->subDays(3)->setHour(19)->setMinute(0),
            'created_at' => now()->subDays(3)->setHour(19)->setMinute(0),
        ]);

        PosZReport::create([
            'vendor_id' => $vendor->id,
            'pos_session_id' => $session3DaysAgo->id,
            'cashier_id' => $user->id,
            'report_date' => now()->subDays(3)->toDateString(),
            'cash_sales' => 180000.00,
            'card_sales' => 420000.00,
            'bank_transfer_sales' => 200000.00,
            'total_sales' => 800000.00,
            'transaction_count' => 4,
            'opening_float' => 20000.00,
            'cash_expected' => 180000.00,
            'cash_counted' => 179500.00,
            'cash_variance' => -500.00,
            'generated_at' => now()->subDays(3)->setHour(19)->setMinute(5),
            'created_at' => now()->subDays(3)->setHour(19)->setMinute(5),
        ]);

        // 7. Seed Customer Debts
        PosCustomerLedgerEntry::where('vendor_id', $vendor->id)->delete();
        $customer = PosCustomer::firstOrCreate(
            ['phone' => '08055554433', 'vendor_id' => $vendor->id],
            [
                'name' => 'Emeka Eze',
                'email' => 'emeka.eze@example.com',
                'address' => '24 Awolowo Road, Ikoyi, Lagos',
            ]
        );

        PosCustomerLedgerEntry::create([
            'pos_customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'store_id' => $lagosStore->id,
            'direction' => 'charge',
            'amount' => 45000.00,
            'source_type' => 'pos_sale',
            'source_id' => 1,
            'description' => 'Unpaid balance on phone accessories',
            'occurred_at' => now()->subDays(4),
            'created_at' => now()->subDays(4),
        ]);

        // 8. Seed Supplier & Procurements (Supplier Oweings)
        Procurement::where('vendor_id', $vendor->id)->delete();
        $supplier = Supplier::firstOrCreate(
            ['email' => 'sales@globaltechdist.com', 'vendor_id' => $vendor->id],
            [
                'name' => 'Global Tech Distribution Ltd',
                'phone' => '08088889999',
                'address' => '5 Alaba International Market, Ojo, Lagos',
            ]
        );

        Procurement::create([
            'reference' => 'PROC-'.strtoupper(Str::random(6)),
            'vendor_id' => $vendor->id,
            'store_id' => $lagosStore->id,
            'supplier_id' => $supplier->id,
            'total_cost' => 750000.00,
            'amount_paid' => 450000.00,
            'payment_status' => 'part_payment',
            'payment_method' => 'bank_transfer',
            'status' => 'approved',
            'notes' => 'Batch of Anker accessories and cables',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now()->subDays(7),
            'created_at' => now()->subDays(7),
        ]);

        // 9. Seed Vendor Payouts Due
        VendorPayout::where('vendor_id', $vendor->id)->delete();
        VendorPayout::create([
            'vendor_id' => $vendor->id,
            'amount' => 250000.00,
            'bank_name' => 'Access Bank',
            'account_number' => '0123456789',
            'account_name' => 'TechHaven Electronics Ltd',
            'status' => 'pending',
            'admin_notes' => 'Weekly marketplace disbursement pending approval',
            'created_at' => now()->subDays(2),
        ]);

        $this->command?->info("Assistant demo data seeded successfully for vendor@gadgetplug.com (Stores: {$lagosStore->name}, {$abujaStore->name})");
    }
}
