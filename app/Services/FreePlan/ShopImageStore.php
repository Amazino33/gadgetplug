<?php

namespace App\Services\FreePlan;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Saves the two pictures a free shop uploads at signup.
 *
 * Both are shrunk to at most 1600px on the long side and re-saved as a ~75%
 * JPEG, so a 5MB phone photo lands as a few hundred KB.
 *
 * The logo is public — it will show on the shop page. The verification photo
 * is not: it can show someone's home or stall and is only ever for the
 * reviewer, so it goes on the private `local` disk and is read back through
 * an admin-only route (FreeShopVerificationPhotoController).
 */
class ShopImageStore
{
    public const PUBLIC_DISK  = 'public';
    public const PRIVATE_DISK = 'local';

    private const MAX_DIMENSION = 1600;
    private const QUALITY       = 75;

    public function storeLogo(UploadedFile $file): string
    {
        return $this->store($file, self::PUBLIC_DISK, 'vendor-logos');
    }

    public function storeVerificationPhoto(UploadedFile $file): string
    {
        return $this->store($file, self::PRIVATE_DISK, 'vendor-verification');
    }

    public function delete(?string $path, string $disk): void
    {
        if (filled($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function store(UploadedFile $file, string $disk, string $folder): string
    {
        $manager = new ImageManager(new Driver);

        // orient() first: phone photos carry their rotation in EXIF, which the
        // JPEG re-encode drops — without it a portrait shop photo lands sideways.
        $jpeg = (string) $manager->decodePath($file->getRealPath())
            ->orient()
            ->scaleDown(self::MAX_DIMENSION, self::MAX_DIMENSION)
            ->encode(new JpegEncoder(quality: self::QUALITY));

        $path = $folder.'/'.Str::uuid().'.jpg';

        Storage::disk($disk)->put($path, $jpeg);

        return $path;
    }
}
