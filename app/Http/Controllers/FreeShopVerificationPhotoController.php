<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\FreePlan\ShopImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way to see a free shop's verification photo: platform admins
 * only. The file sits on the private disk, so there is no public URL to
 * guess or share.
 */
class FreeShopVerificationPhotoController extends Controller
{
    public function __invoke(Request $request, Vendor $vendor): Response
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $path = $vendor->verification_photo;
        $disk = Storage::disk(ShopImageStore::PRIVATE_DISK);

        abort_unless(filled($path) && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag'  => 'noindex',
        ]);
    }
}
