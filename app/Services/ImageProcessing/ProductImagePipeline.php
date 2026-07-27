<?php

namespace App\Services\ImageProcessing;

use Illuminate\Support\Facades\Log;
use Throwable;

// Orchestrates background removal, optimization, and AI metadata for one
// uploaded product photo. Each stage degrades independently — a failure in
// background removal or the vision call never blocks the vendor from
// proceeding with the original photo (see result flags below).
class ProductImagePipeline
{
    public function __construct(
        private readonly BackgroundRemover $backgroundRemover,
        private readonly ProductImageOptimizer $optimizer,
        private readonly ProductImageAiAnalyzer $analyzer,
    ) {}

    public function process(string $imageBinary, string $mimeType): ProductImagePipelineResult
    {
        $optimized = null;
        $analysis = null;
        $backgroundRemovalFailed = false;
        $analysisFailed = false;

        try {
            $clean = $this->backgroundRemover->removeBackground($imageBinary, $mimeType);
            $optimized = $this->optimizer->optimize($clean);
        } catch (Throwable $e) {
            $backgroundRemovalFailed = true;
            Log::warning('Product image background removal failed, using original photo.', [
                'exception' => $e->getMessage(),
            ]);
        }

        // Analyze whichever image is currently our best candidate — the
        // optimized white-background version if we have one, else the
        // original upload — so metadata suggestions still work even when
        // background removal failed.
        $imageForAnalysis = $optimized['webp'] ?? $imageBinary;
        $analysisMimeType = $optimized ? 'image/webp' : $mimeType;

        try {
            $analysis = $this->analyzer->analyze($imageForAnalysis, $analysisMimeType);
        } catch (Throwable $e) {
            $analysisFailed = true;
            Log::warning('Product image AI metadata generation failed.', [
                'exception' => $e->getMessage(),
            ]);
        }

        return new ProductImagePipelineResult(
            optimizedImages: $optimized,
            analysis: $analysis,
            backgroundRemovalFailed: $backgroundRemovalFailed,
            analysisFailed: $analysisFailed,
        );
    }
}
