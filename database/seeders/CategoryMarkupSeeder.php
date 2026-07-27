<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

// Applies config('pricing.category_markups') to matching categories by
// slug. Categories with no matching key are left with markup = null and
// fall back to config('pricing.fallback_markup') at price-calculation time.
class CategoryMarkupSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('pricing.category_markups', []) as $slug => $markup) {
            Category::where('slug', $slug)->update(['markup' => $markup]);
        }
    }
}
