<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\Product;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

// SVG only, via bacon/bacon-qr-code (already installed — it powers Fortify's
// two-factor QR codes elsewhere in this app) — this environment has no
// Imagick, which bacon's PNG output requires, so PNG isn't offered. Same
// rendering call Fortify's own TwoFactorAuthenticatable::twoFactorQrCodeSvg()
// uses. Rendered SVGs are cached to the affiliate's own media library so a
// repeat page view never regenerates one.
class QrCodeService
{
    public function referralLinkUrl(Affiliate $affiliate): string
    {
        return route('affiliate.click', ['code' => $affiliate->code]);
    }

    public function productLinkUrl(Affiliate $affiliate, Product $product): string
    {
        return route('affiliate.click', ['code' => $affiliate->code, 'to' => $product->slug]);
    }

    public function referralQrSvg(Affiliate $affiliate): string
    {
        return $this->cachedSvg($affiliate, 'referral', $this->referralLinkUrl($affiliate));
    }

    public function productQrSvg(Affiliate $affiliate, Product $product): string
    {
        return $this->cachedSvg($affiliate, "product-{$product->id}", $this->productLinkUrl($affiliate, $product));
    }

    private function cachedSvg(Affiliate $affiliate, string $key, string $url): string
    {
        $existing = $affiliate->getMedia('qr-codes')->firstWhere('custom_properties.qr_key', $key);

        if ($existing && $existing->getCustomProperty('url') === $url) {
            return file_get_contents($existing->getPath());
        }

        // Stale (e.g. the product's slug changed since this was cached) or
        // missing — regenerate.
        $existing?->delete();

        $svg = $this->renderSvg($url);

        $affiliate->addMediaFromString($svg)
            ->usingFileName($key . '.svg')
            ->withCustomProperties(['qr_key' => $key, 'url' => $url])
            ->toMediaCollection('qr-codes');

        return $svg;
    }

    private function renderSvg(string $url): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(300, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(5, 80, 2))),
                new SvgImageBackEnd,
            )
        ))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
