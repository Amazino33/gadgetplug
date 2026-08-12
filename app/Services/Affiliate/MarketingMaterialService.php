<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\MarketingMaterial;
use Illuminate\Support\Collection;

/**
 * Serves admin-uploaded branded creative to affiliates, each copy carrying the
 * affiliate's own code/link in its caption.
 *
 * v1 deliberately does not render the code into the artwork — that watermark/QR
 * work is Prompt 5. What matters for review here is that the code is visible in
 * what gets posted, and a caption satisfies that while keeping one stored image
 * serving every affiliate.
 */
class MarketingMaterialService
{
    public function __construct(private QrCodeService $qrCodes) {}

    /**
     * @return Collection<int, array{material: MarketingMaterial, caption: string, image_url: ?string, thumb_url: ?string}>
     */
    public function forAffiliate(Affiliate $affiliate): Collection
    {
        $link = $this->qrCodes->referralLinkUrl($affiliate);

        return MarketingMaterial::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (MarketingMaterial $material) => $this->present($material, $affiliate, $link));
    }

    /**
     * @return array{material: MarketingMaterial, caption: string, image_url: ?string, thumb_url: ?string}
     */
    public function present(MarketingMaterial $material, Affiliate $affiliate, ?string $link = null): array
    {
        $link ??= $this->qrCodes->referralLinkUrl($affiliate);
        $media = $material->getFirstMedia('artwork');

        return [
            'material'  => $material,
            'caption'   => $material->captionFor($affiliate, $link),
            'image_url' => $media?->getUrl(),
            'thumb_url' => $media ? $media->getUrl('thumb') : null,
        ];
    }
}
