<?php

declare(strict_types=1);

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders a QR as an inline SVG data URI.
 *
 * SVG rather than PNG on purpose: bacon/bacon-qr-code is already installed (no
 * new dependency), and its SVG backend needs no imagick or GD, which shared
 * hosting often lacks. A vector code also stays crisp at whatever size the
 * thermal head prints it, where a scaled bitmap would blur and fail to scan.
 */
class QrCode
{
    /**
     * The raw SVG markup, for embedding directly in the page.
     *
     * Preferred over svgDataUri() anywhere the result is printed. An <img>
     * pointing at a data URI is rasterised by the browser at the element's CSS
     * size and then scaled up by the printer — a 30mm code rendered at 96dpi and
     * printed at a thermal head's 203dpi is upscaled roughly two-fold, which is
     * what turns the pattern to mush and stops phones reading it. Inline SVG
     * stays vector all the way to the print raster.
     */
    public static function svg(string $text, int $size = 240, int $margin = 1): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd()
        );

        $svg = (new Writer($renderer))->writeString($text);

        // Drop the XML prolog — invalid partway through an HTML document.
        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }

    /**
     * @param  int  $size  Pixel size hint; the SVG scales, this sets its viewBox.
     * @param  int  $margin Quiet zone in modules. Scanners need some; 1 is the
     *                      practical minimum on thermal paper, where every
     *                      millimetre of width matters.
     */
    public static function svgDataUri(string $text, int $size = 120, int $margin = 1): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd()
        );

        $svg = (new Writer($renderer))->writeString($text);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
