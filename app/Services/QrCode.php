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
