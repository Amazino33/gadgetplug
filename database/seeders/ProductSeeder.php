<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = Vendor::where('slug', 'techhaven')->firstOrFail();

        $cat = fn (string $name) => Category::where('name', $name)->firstOrFail()->id;

        $products = [
            // Smartphones
            [
                'category' => 'Smartphones',
                'name'     => 'iPhone 15 Pro',
                'brand'    => 'Apple',
                'price'    => 850000,
                'cost'     => 700000,
                'stock'    => 15,
                'sku'      => 'APL-IP15P-128',
                'barcode'  => '194253728023',
                'desc'     => 'Apple iPhone 15 Pro with A17 Pro chip, 48MP camera system and titanium design. 128GB.',
                'specs'    => ['Storage' => '128GB', 'RAM' => '8GB', 'Display' => '6.1" Super Retina XDR', 'Battery' => '3274mAh'],
            ],
            [
                'category' => 'Smartphones',
                'name'     => 'Samsung Galaxy S24',
                'brand'    => 'Samsung',
                'price'    => 650000,
                'cost'     => 520000,
                'stock'    => 20,
                'sku'      => 'SAM-GS24-256',
                'barcode'  => '887276788050',
                'desc'     => 'Samsung Galaxy S24 with Snapdragon 8 Gen 3, 50MP triple camera and 6.2" AMOLED display. 256GB.',
                'specs'    => ['Storage' => '256GB', 'RAM' => '8GB', 'Display' => '6.2" Dynamic AMOLED 2X', 'Battery' => '4000mAh'],
            ],
            [
                'category' => 'Smartphones',
                'name'     => 'Tecno Camon 30 Pro',
                'brand'    => 'Tecno',
                'price'    => 180000,
                'cost'     => 140000,
                'stock'    => 40,
                'sku'      => 'TEC-C30P-256',
                'barcode'  => '617258897654',
                'desc'     => 'Tecno Camon 30 Pro with 108MP RGBW camera, 5000mAh battery and 6.78" AMOLED display. 256GB.',
                'specs'    => ['Storage' => '256GB', 'RAM' => '12GB', 'Display' => '6.78" AMOLED', 'Battery' => '5000mAh'],
            ],

            // Laptops & Computers
            [
                'category' => 'Laptops & Computers',
                'name'     => 'MacBook Air M2',
                'brand'    => 'Apple',
                'price'    => 1100000,
                'cost'     => 900000,
                'stock'    => 8,
                'sku'      => 'APL-MBA-M2-256',
                'barcode'  => '194253489023',
                'desc'     => 'Apple MacBook Air with M2 chip, 13.6" Liquid Retina display, 8GB RAM and 256GB SSD.',
                'specs'    => ['Processor' => 'Apple M2', 'RAM' => '8GB', 'Storage' => '256GB SSD', 'Display' => '13.6" Liquid Retina'],
            ],
            [
                'category' => 'Laptops & Computers',
                'name'     => 'Dell XPS 15',
                'brand'    => 'Dell',
                'price'    => 980000,
                'cost'     => 800000,
                'stock'    => 5,
                'sku'      => 'DEL-XPS15-512',
                'barcode'  => '884116415169',
                'desc'     => 'Dell XPS 15 with Intel Core i7-13700H, 16GB RAM, 512GB SSD and 15.6" OLED display.',
                'specs'    => ['Processor' => 'Intel Core i7-13700H', 'RAM' => '16GB', 'Storage' => '512GB SSD', 'Display' => '15.6" OLED'],
            ],
            [
                'category' => 'Laptops & Computers',
                'name'     => 'HP Pavilion 15',
                'brand'    => 'HP',
                'price'    => 420000,
                'cost'     => 340000,
                'stock'    => 12,
                'sku'      => 'HP-PAV15-512',
                'barcode'  => '197029540292',
                'desc'     => 'HP Pavilion 15 with AMD Ryzen 5 7530U, 8GB RAM, 512GB SSD and Full HD display.',
                'specs'    => ['Processor' => 'AMD Ryzen 5 7530U', 'RAM' => '8GB', 'Storage' => '512GB SSD', 'Display' => '15.6" FHD IPS'],
            ],

            // Tablets
            [
                'category' => 'Tablets',
                'name'     => 'iPad Air (5th Gen)',
                'brand'    => 'Apple',
                'price'    => 600000,
                'cost'     => 490000,
                'stock'    => 10,
                'sku'      => 'APL-IPAD-AIR5',
                'barcode'  => '194252790014',
                'desc'     => 'Apple iPad Air with M1 chip, 10.9" Liquid Retina display and USB-C. 64GB Wi-Fi.',
                'specs'    => ['Processor' => 'Apple M1', 'Storage' => '64GB', 'Display' => '10.9" Liquid Retina', 'Connectivity' => 'Wi-Fi'],
            ],
            [
                'category' => 'Tablets',
                'name'     => 'Samsung Galaxy Tab S9',
                'brand'    => 'Samsung',
                'price'    => 480000,
                'cost'     => 390000,
                'stock'    => 8,
                'sku'      => 'SAM-TABS9-128',
                'barcode'  => '887276773940',
                'desc'     => 'Samsung Galaxy Tab S9 with Snapdragon 8 Gen 2, 11" Dynamic AMOLED and S Pen included. 128GB.',
                'specs'    => ['Processor' => 'Snapdragon 8 Gen 2', 'RAM' => '8GB', 'Storage' => '128GB', 'Display' => '11" Dynamic AMOLED 2X'],
            ],
            [
                'category' => 'Tablets',
                'name'     => 'Xiaomi Pad 6',
                'brand'    => 'Xiaomi',
                'price'    => 220000,
                'cost'     => 175000,
                'stock'    => 15,
                'sku'      => 'XIA-PAD6-128',
                'barcode'  => '617258745231',
                'desc'     => 'Xiaomi Pad 6 with Snapdragon 870, 11" 144Hz display and 8840mAh battery. 128GB.',
                'specs'    => ['Processor' => 'Snapdragon 870', 'RAM' => '6GB', 'Storage' => '128GB', 'Display' => '11" IPS 144Hz'],
            ],

            // Audio & Headphones
            [
                'category' => 'Audio & Headphones',
                'name'     => 'AirPods Pro (2nd Gen)',
                'brand'    => 'Apple',
                'price'    => 160000,
                'cost'     => 125000,
                'stock'    => 25,
                'sku'      => 'APL-APP2-USB',
                'barcode'  => '195949208867',
                'desc'     => 'Apple AirPods Pro with Active Noise Cancellation, Adaptive Transparency and USB-C charging case.',
                'specs'    => ['Driver' => 'Apple H2 chip', 'ANC' => 'Yes', 'Battery' => '6hrs (30hrs with case)', 'Charging' => 'USB-C / MagSafe'],
            ],
            [
                'category' => 'Audio & Headphones',
                'name'     => 'Sony WH-1000XM5',
                'brand'    => 'Sony',
                'price'    => 180000,
                'cost'     => 145000,
                'stock'    => 18,
                'sku'      => 'SON-WH1000XM5',
                'barcode'  => '027242925809',
                'desc'     => 'Sony WH-1000XM5 wireless headphones with industry-leading noise cancellation and 30-hour battery.',
                'specs'    => ['Type' => 'Over-ear', 'ANC' => 'Yes', 'Battery' => '30 hours', 'Connectivity' => 'Bluetooth 5.2'],
            ],
            [
                'category' => 'Audio & Headphones',
                'name'     => 'JBL Tune 770NC',
                'brand'    => 'JBL',
                'price'    => 55000,
                'cost'     => 42000,
                'stock'    => 30,
                'sku'      => 'JBL-T770NC-BLK',
                'barcode'  => '050036386249',
                'desc'     => 'JBL Tune 770NC with Adaptive Noise Cancelling, 70-hour battery and foldable design.',
                'specs'    => ['Type' => 'Over-ear', 'ANC' => 'Adaptive', 'Battery' => '70 hours', 'Connectivity' => 'Bluetooth 5.3'],
            ],

            // Smartwatches & Wearables
            [
                'category' => 'Smartwatches & Wearables',
                'name'     => 'Apple Watch Series 9',
                'brand'    => 'Apple',
                'price'    => 280000,
                'cost'     => 225000,
                'stock'    => 12,
                'sku'      => 'APL-AW9-41MM',
                'barcode'  => '195949021428',
                'desc'     => 'Apple Watch Series 9 with S9 SiP chip, Double Tap gesture, Always-On Retina display. 41mm.',
                'specs'    => ['Size' => '41mm', 'Display' => 'Always-On Retina LTPO OLED', 'Battery' => '18 hours', 'OS' => 'watchOS 10'],
            ],
            [
                'category' => 'Smartwatches & Wearables',
                'name'     => 'Samsung Galaxy Watch 6',
                'brand'    => 'Samsung',
                'price'    => 160000,
                'cost'     => 128000,
                'stock'    => 15,
                'sku'      => 'SAM-GW6-40MM',
                'barcode'  => '887276764863',
                'desc'     => 'Samsung Galaxy Watch 6 with advanced sleep tracking, BioActive Sensor and Sapphire crystal. 40mm.',
                'specs'    => ['Size' => '40mm', 'Display' => '1.3" Super AMOLED', 'Battery' => '40 hours', 'OS' => 'Wear OS 4'],
            ],
            [
                'category' => 'Smartwatches & Wearables',
                'name'     => 'Xiaomi Smart Band 8',
                'brand'    => 'Xiaomi',
                'price'    => 25000,
                'cost'     => 18000,
                'stock'    => 50,
                'sku'      => 'XIA-SB8-BLK',
                'barcode'  => '617258789541',
                'desc'     => 'Xiaomi Smart Band 8 with 1.62" AMOLED, 16-day battery life and 150+ fitness modes.',
                'specs'    => ['Display' => '1.62" AMOLED', 'Battery' => '16 days', 'Water Resistance' => '5ATM', 'Sensors' => 'Heart rate, SpO2, Stress'],
            ],

            // Accessories & Cables
            [
                'category' => 'Accessories & Cables',
                'name'     => 'Anker 7-in-1 USB-C Hub',
                'brand'    => 'Anker',
                'price'    => 22000,
                'cost'     => 16000,
                'stock'    => 35,
                'sku'      => 'ANK-USBC-HUB7',
                'barcode'  => '194644126217',
                'desc'     => 'Anker 7-in-1 USB-C hub with 4K HDMI, 100W PD, USB-A 3.0, SD and microSD card readers.',
                'specs'    => ['Ports' => '7-in-1', 'HDMI' => '4K@30Hz', 'Power Delivery' => '100W', 'USB-A' => '3x USB 3.0'],
            ],
            [
                'category' => 'Accessories & Cables',
                'name'     => 'Baseus 65W GaN Charger',
                'brand'    => 'Baseus',
                'price'    => 18000,
                'cost'     => 12000,
                'stock'    => 60,
                'sku'      => 'BAS-GAN65-BLK',
                'barcode'  => '617258963214',
                'desc'     => 'Baseus 65W GaN fast charger with 2x USB-C and 1x USB-A ports. Charges MacBook, phone, and tablet simultaneously.',
                'specs'    => ['Total Power' => '65W', 'Ports' => '2x USB-C, 1x USB-A', 'Technology' => 'GaN III', 'Compatibility' => 'Universal'],
            ],
            [
                'category' => 'Accessories & Cables',
                'name'     => 'Spigen Tough Armor iPhone 15 Case',
                'brand'    => 'Spigen',
                'price'    => 8500,
                'cost'     => 5500,
                'stock'    => 80,
                'sku'      => 'SPG-TA-IP15-BLK',
                'barcode'  => '617258852147',
                'desc'     => 'Spigen Tough Armor case for iPhone 15 with Air Cushion Technology and built-in kickstand.',
                'specs'    => ['Material' => 'TPU + PC', 'MagSafe' => 'Compatible', 'Drop Protection' => 'Military Grade', 'Kickstand' => 'Yes'],
            ],

            // Gaming
            [
                'category' => 'Gaming',
                'name'     => 'Xbox Wireless Controller',
                'brand'    => 'Microsoft',
                'price'    => 45000,
                'cost'     => 35000,
                'stock'    => 22,
                'sku'      => 'MS-XBC-CARB',
                'barcode'  => '889842364781',
                'desc'     => 'Xbox Wireless Controller in Carbon Black with textured grip, USB-C and Bluetooth connectivity.',
                'specs'    => ['Platform' => 'Xbox / PC / Mobile', 'Connectivity' => 'Bluetooth 5.2 / USB-C', 'Battery' => 'AA x2', 'Trigger' => 'Textured'],
            ],
            [
                'category' => 'Gaming',
                'name'     => 'PlayStation DualSense Controller',
                'brand'    => 'Sony',
                'price'    => 52000,
                'cost'     => 42000,
                'stock'    => 18,
                'sku'      => 'SON-DS5-WHT',
                'barcode'  => '711719399506',
                'desc'     => 'Sony DualSense wireless controller for PS5 with haptic feedback and adaptive triggers. White.',
                'specs'    => ['Platform' => 'PS5 / PC', 'Connectivity' => 'Bluetooth / USB-C', 'Battery' => '12 hours', 'Rumble' => 'Haptic Feedback'],
            ],
            [
                'category' => 'Gaming',
                'name'     => 'Razer BlackShark V2 X',
                'brand'    => 'Razer',
                'price'    => 35000,
                'cost'     => 27000,
                'stock'    => 14,
                'sku'      => 'RZR-BSV2X-BLK',
                'barcode'  => '811659032997',
                'desc'     => 'Razer BlackShark V2 X gaming headset with 7.1 Surround Sound, 50mm drivers and cardioid mic.',
                'specs'    => ['Drivers' => '50mm', 'Surround' => '7.1 Virtual', 'Microphone' => 'Cardioid', 'Connectivity' => '3.5mm'],
            ],

            // Cameras & Photography
            [
                'category' => 'Cameras & Photography',
                'name'     => 'Sony ZV-E10 Mirrorless Camera',
                'brand'    => 'Sony',
                'price'    => 380000,
                'cost'     => 310000,
                'stock'    => 6,
                'sku'      => 'SON-ZVE10-BLK',
                'barcode'  => '027242935907',
                'desc'     => 'Sony ZV-E10 APS-C mirrorless camera with interchangeable lens mount, ideal for vloggers. Body only.',
                'specs'    => ['Sensor' => '24.2MP APS-C', 'Video' => '4K 30fps', 'Stabilization' => 'Digital', 'Mount' => 'Sony E-mount'],
            ],
            [
                'category' => 'Cameras & Photography',
                'name'     => 'DJI Osmo Pocket 3',
                'brand'    => 'DJI',
                'price'    => 280000,
                'cost'     => 225000,
                'stock'    => 7,
                'sku'      => 'DJI-OP3-STD',
                'barcode'  => '190021064637',
                'desc'     => 'DJI Osmo Pocket 3 handheld camera with 1-inch CMOS sensor, 4K/120fps video and 3-axis gimbal.',
                'specs'    => ['Sensor' => '1-inch CMOS', 'Video' => '4K/120fps', 'Gimbal' => '3-axis', 'Battery' => '166 min'],
            ],
            [
                'category' => 'Cameras & Photography',
                'name'     => 'GoPro HERO12 Black',
                'brand'    => 'GoPro',
                'price'    => 210000,
                'cost'     => 168000,
                'stock'    => 9,
                'sku'      => 'GPR-H12-BLK',
                'barcode'  => '818279028533',
                'desc'     => 'GoPro HERO12 Black action camera with 5.3K video, HyperSmooth 6.0 stabilization and waterproof to 10m.',
                'specs'    => ['Video' => '5.3K60 / 4K120', 'Photo' => '27MP', 'Stabilization' => 'HyperSmooth 6.0', 'Waterproof' => '10m'],
            ],
        ];

        foreach ($products as $data) {
            $exists = Product::where('vendor_id', $vendor->id)
                ->where('sku', $data['sku'])
                ->exists();

            if ($exists) {
                continue;
            }

            Product::create([
                'vendor_id'      => $vendor->id,
                'category_id'    => $cat($data['category']),
                'name'           => $data['name'],
                'brand'          => $data['brand'],
                'price'          => $data['price'],
                'cost_price'     => $data['cost'],
                'stock_quantity' => $data['stock'],
                'reserved_stock' => 0,
                'sku'            => $data['sku'],
                'barcode'        => $data['barcode'],
                'description'    => $data['desc'],
                'specifications' => $data['specs'],
                'status'         => 'published',
                'published_at'   => now(),
            ]);
        }
    }
}
