<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateFavicons extends Command
{
    protected $signature   = 'gadgetplug:favicons {--source=public/images/logo.png}';
    protected $description = 'Generate favicon.ico, apple-touch-icon.png and PNG favicons from the logo';

    public function handle(): int
    {
        $source = base_path($this->option('source'));

        if (! file_exists($source)) {
            $this->error("Logo not found at: {$source}");
            $this->line('Place the logo PNG at public/images/logo.png and re-run.');
            return self::FAILURE;
        }

        [$origW, $origH, $type] = getimagesize($source);

        $src = match ($type) {
            IMAGETYPE_PNG  => imagecreatefrompng($source),
            IMAGETYPE_JPEG => imagecreatefromjpeg($source),
            IMAGETYPE_WEBP => imagecreatefromwebp($source),
            default        => null,
        };

        if (! $src) {
            $this->error('Unsupported image type. Use PNG, JPEG, or WebP.');
            return self::FAILURE;
        }

        // Center-square crop as the icon base (logos are usually wider than tall)
        $square = min($origW, $origH);
        $x      = (int) (($origW - $square) / 2);
        $y      = (int) (($origH - $square) / 2);

        $this->writeResized($src, $x, $y, $square, 180, public_path('apple-touch-icon.png'));
        $this->info('✓ public/apple-touch-icon.png  (180×180)');

        $this->writeResized($src, $x, $y, $square, 32, public_path('favicon-32.png'));
        $this->info('✓ public/favicon-32.png         (32×32)');

        $this->writeResized($src, $x, $y, $square, 16, public_path('favicon-16.png'));
        $this->info('✓ public/favicon-16.png         (16×16)');

        $img16 = $this->makeResized($src, $x, $y, $square, 16);
        $img32 = $this->makeResized($src, $x, $y, $square, 32);
        $this->writeIco([$img16, $img32], [16, 32], public_path('favicon.ico'));
        imagedestroy($img16);
        imagedestroy($img32);
        $this->info('✓ public/favicon.ico            (16×16 + 32×32)');

        imagedestroy($src);

        $this->newLine();
        $this->line('Drop the generated files into your repo and you\'re done.');
        return self::SUCCESS;
    }

    private function makeResized($src, int $x, int $y, int $square, int $size)
    {
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $size, $size, $square, $square);
        return $dst;
    }

    private function writeResized($src, int $x, int $y, int $square, int $size, string $path): void
    {
        $dst = $this->makeResized($src, $x, $y, $square, $size);
        imagepng($dst, $path, 9);
        imagedestroy($dst);
    }

    private function writeIco(array $images, array $sizes, string $path): void
    {
        $count  = count($images);
        $offset = 6 + 16 * $count;
        $entries = '';
        $data    = '';

        foreach ($images as $i => $img) {
            $bmp  = $this->gdToBmp24($img, $sizes[$i]);
            $len  = strlen($bmp);
            $entries .= pack('CCCCvvVV', $sizes[$i], $sizes[$i], 0, 0, 1, 32, $len, $offset);
            $offset  += $len;
            $data    .= $bmp;
        }

        file_put_contents($path, pack('vvv', 0, 1, $count) . $entries . $data);
    }

    private function gdToBmp24($img, int $size): string
    {
        $padded  = (int) ceil($size * 3 / 4) * 4;
        $header  = pack('VVVvvVVVVVV', 40, $size, $size * 2, 1, 24, 0, $padded * $size * 2, 0, 0, 0, 0);

        $pixels = '';
        for ($y = $size - 1; $y >= 0; $y--) {
            $row = '';
            for ($x = 0; $x < $size; $x++) {
                $rgb  = imagecolorat($img, $x, $y);
                $row .= chr($rgb & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr(($rgb >> 16) & 0xFF);
            }
            $pixels .= str_pad($row, $padded, "\0");
        }

        $maskPadded = (int) ceil(ceil($size / 8) / 4) * 4;
        return $header . $pixels . str_repeat("\0", $maskPadded * $size);
    }
}
