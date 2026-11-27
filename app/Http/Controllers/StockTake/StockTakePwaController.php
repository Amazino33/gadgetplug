<?php

namespace App\Http\Controllers\StockTake;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Stock Take as an app on the phone (release 2, A8).
 *
 * The service worker is served from /stock-take/sw.js, so the most it can
 * ever control is /stock-take/: never the till, Purchases or the panels. The
 * till keeps its own worker at /sw.js.
 */
class StockTakePwaController extends Controller
{
    /** The app's entry in the build manifest. */
    private const ENTRY = 'resources/js/stock-take/main.jsx';

    public function worker(): Response
    {
        $files   = $this->buildFiles();
        $source  = file_get_contents(resource_path('stock-take/sw.js'));
        $version = substr(sha1($source . json_encode($files)), 0, 12);

        $script = str_replace(
            ["'__VERSION__'", '__PRECACHE__'],
            [json_encode($version), json_encode($files, JSON_UNESCAPED_SLASHES)],
            $source,
        );

        return response($script, 200, [
            'Content-Type'           => 'application/javascript; charset=utf-8',
            // Checked for a new version on every open; a deploy reaches the phone at once.
            'Cache-Control'          => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => '/stock-take/',
        ]);
    }

    /** "Add to Home Screen": full screen, portrait, named GadgetPlug Count. */
    public function manifest(Vendor $vendor): JsonResponse
    {
        abort_unless($vendor->stock_take_enabled, 404);

        return response()->json([
            'name'             => 'GadgetPlug Count',
            'short_name'       => 'GadgetPlug Count',
            'description'      => 'Stock Take: count the shelves, aisle by aisle.',
            'id'               => "/stock-take/{$vendor->slug}",
            'start_url'        => "/stock-take/{$vendor->slug}",
            'scope'            => '/stock-take/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#F9FAFB',
            'theme_color'      => '#0F766E',
            'icons'            => [
                ['src' => '/android-chrome-192x192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/android-chrome-512x512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/android-chrome-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * The built JS and CSS the app loads at start, from Vite's manifest, so
     * the worker stores them when it installs. ZXing (iPhones only) is left
     * out: it is stored the first time an iPhone loads it.
     *
     * @return array<int, string>
     */
    private function buildFiles(): array
    {
        $path = public_path('build/manifest.json');
        if (! is_file($path)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($path), true) ?: [];
        $files = [];
        $walk = function (string $key) use (&$walk, &$files, $manifest): void {
            $chunk = $manifest[$key] ?? null;
            if (! $chunk || in_array('/build/' . $chunk['file'], $files, true)) {
                return;
            }
            $files[] = '/build/' . $chunk['file'];
            foreach ($chunk['css'] ?? [] as $css) {
                $files[] = '/build/' . $css;
            }
            foreach ($chunk['imports'] ?? [] as $import) {
                $walk($import);
            }
        };
        $walk(self::ENTRY);

        return array_values(array_unique($files));
    }
}
