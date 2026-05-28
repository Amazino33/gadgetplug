<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Smartphones',             'description' => 'Latest mobile phones and smartphones from top brands'],
            ['name' => 'Laptops & Computers',      'description' => 'Laptops, desktops, and computing devices'],
            ['name' => 'Tablets',                  'description' => 'Tablets and e-readers'],
            ['name' => 'Audio & Headphones',        'description' => 'Headphones, earbuds, speakers and audio accessories'],
            ['name' => 'Smartwatches & Wearables', 'description' => 'Smartwatches, fitness trackers and wearable tech'],
            ['name' => 'Accessories & Cables',     'description' => 'Phone cases, charging cables, hubs and adapters'],
            ['name' => 'Gaming',                   'description' => 'Gaming controllers, headsets and accessories'],
            ['name' => 'Cameras & Photography',    'description' => 'Cameras, lenses and photography equipment'],
        ];

        foreach ($categories as $data) {
            Category::firstOrCreate(['name' => $data['name']], array_merge($data, ['is_active' => true]));
        }
    }
}
