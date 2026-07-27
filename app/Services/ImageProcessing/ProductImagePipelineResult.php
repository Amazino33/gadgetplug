<?php

namespace App\Services\ImageProcessing;

readonly class ProductImagePipelineResult
{
    /**
     * @param  array{transparent: string, webp: string, fallback: string}|null  $optimizedImages
     */
    public function __construct(
        public ?array $optimizedImages,
        public ?ProductImageAnalysis $analysis,
        public bool $backgroundRemovalFailed,
        public bool $analysisFailed,
    ) {}

    public function hasOptimizedImage(): bool
    {
        return $this->optimizedImages !== null;
    }

    public function hasAnalysis(): bool
    {
        return $this->analysis !== null;
    }
}
