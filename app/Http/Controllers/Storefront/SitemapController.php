<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

// /sitemap.xml — every page a search engine should index on the storefront.
//
// Exactly what a customer can see: products that pass visibleOnline() (so no
// photo-less, unpublished, hidden or switched-off ones, and resold listings
// only while their supplier side resolves), the category shelves and store
// pages that have something visible on them, and the two fixed pages. Every
// URL is on the storefront host (config app.storefront_url), whichever domain
// served the request. Cached for an hour; it changes about as often as the
// catalogue does.
class SitemapController extends Controller
{
    public const CACHE_KEY = 'storefront.sitemap.v1';

    public function __invoke(): Response
    {
        $xml = Cache::remember(self::CACHE_KEY, now()->addHour(), fn () => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function build(): string
    {
        $base = config('app.storefront_url');
        $urls = [];

        $latest = Product::query()->visibleOnline()->max('products.updated_at');

        $urls[] = [$base.'/', $latest];
        $urls[] = [$base.'/stores', $latest];

        Category::query()
            ->whereHas('products', fn ($q) => $q->visibleOnline())
            ->orderBy('id')
            ->get(['id', 'slug', 'updated_at'])
            ->each(function (Category $category) use (&$urls, $base) {
                if (filled($category->slug)) {
                    $urls[] = [$base.'/?category='.rawurlencode($category->slug), $category->updated_at];
                }
            });

        Vendor::query()
            ->whereHas('products', fn ($q) => $q->visibleOnline())
            ->orderBy('id')
            ->get(['id', 'slug', 'updated_at'])
            ->each(function (Vendor $vendor) use (&$urls, $base) {
                if (filled($vendor->slug)) {
                    $urls[] = [$base.'/store/'.rawurlencode($vendor->slug), $vendor->updated_at];
                }
            });

        Product::query()
            ->visibleOnline()
            ->orderBy('products.id')
            ->select(['products.id', 'products.slug', 'products.updated_at'])
            ->cursor()
            ->each(function (Product $product) use (&$urls, $base) {
                $urls[] = [$base.'/product/'.rawurlencode($product->slug), $product->updated_at];
            });

        $body = collect($urls)->map(function (array $entry) {
            [$loc, $lastmod] = $entry;

            $line = '  <url><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>';

            if ($lastmod) {
                $line .= '<lastmod>'.\Illuminate\Support\Carbon::parse($lastmod)->toAtomString().'</lastmod>';
            }

            return $line.'</url>';
        })->implode("\n");

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$body."\n"
            .'</urlset>'."\n";
    }
}
