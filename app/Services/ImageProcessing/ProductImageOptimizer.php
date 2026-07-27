<?php

namespace App\Services\ImageProcessing;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

// Resizes and re-encodes the background-removed product photo — pure PHP/GD,
// no external optimizer binaries (cwebp/pngquant/etc.) required, so this
// works unmodified on shared hosting.
class ProductImageOptimizer
{
    // Long-edge cap for the web-facing image — scaleDown() never upscales,
    // so smaller source photos pass through untouched.
    private const MAX_DIMENSION = 1600;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * @return array{transparent: string, webp: string, fallback: string}
     */
    public function optimize(string $transparentPngBinary): array
    {
        $transparent = $this->manager->decodeBinary($transparentPngBinary)
            ->scaleDown(self::MAX_DIMENSION, self::MAX_DIMENSION);

        $whiteBackground = (clone $transparent)->fillTransparentAreas('ffffff');

        return [
            'transparent' => (string) $transparent->encode(new PngEncoder),
            'webp' => (string) $whiteBackground->encode(new WebpEncoder(quality: 82)),
            'fallback' => (string) $whiteBackground->encode(new JpegEncoder(quality: 85)),
        ];
    }
}
