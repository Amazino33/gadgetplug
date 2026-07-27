<?php

use App\Services\ImageProcessing\BackgroundRemover;
use App\Services\ImageProcessing\ProductImageAiAnalyzer;
use App\Services\ImageProcessing\ProductImageAnalysis;
use App\Services\ImageProcessing\ProductImageOptimizer;
use App\Services\ImageProcessing\ProductImagePipeline;

test('falls back to the original photo when background removal fails, but still generates AI metadata', function () {
    $backgroundRemover = Mockery::mock(BackgroundRemover::class);
    $backgroundRemover->shouldReceive('removeBackground')->once()->andThrow(new RuntimeException('replicate down'));

    $optimizer = Mockery::mock(ProductImageOptimizer::class);
    $optimizer->shouldNotReceive('optimize');

    $analysis = new ProductImageAnalysis('red-kettle', 'A red electric kettle', 'Red Kettle', 'A sleek red kettle.', ['kettle', 'kitchen']);
    $analyzer = Mockery::mock(ProductImageAiAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with('original-bytes', 'image/jpeg')->andReturn($analysis);

    $result = (new ProductImagePipeline($backgroundRemover, $optimizer, $analyzer))
        ->process('original-bytes', 'image/jpeg');

    expect($result->backgroundRemovalFailed)->toBeTrue()
        ->and($result->hasOptimizedImage())->toBeFalse()
        ->and($result->analysisFailed)->toBeFalse()
        ->and($result->hasAnalysis())->toBeTrue()
        ->and($result->analysis->title)->toBe('Red Kettle');
});

test('still returns the optimized image when AI metadata generation fails', function () {
    $backgroundRemover = Mockery::mock(BackgroundRemover::class);
    $backgroundRemover->shouldReceive('removeBackground')->once()->andReturn('clean-png-bytes');

    $optimized = ['transparent' => 'png-bytes', 'webp' => 'webp-bytes', 'fallback' => 'jpeg-bytes'];
    $optimizer = Mockery::mock(ProductImageOptimizer::class);
    $optimizer->shouldReceive('optimize')->once()->with('clean-png-bytes')->andReturn($optimized);

    $analyzer = Mockery::mock(ProductImageAiAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with('webp-bytes', 'image/webp')->andThrow(new RuntimeException('anthropic down'));

    $result = (new ProductImagePipeline($backgroundRemover, $optimizer, $analyzer))
        ->process('original-bytes', 'image/jpeg');

    expect($result->backgroundRemovalFailed)->toBeFalse()
        ->and($result->hasOptimizedImage())->toBeTrue()
        ->and($result->optimizedImages['fallback'])->toBe('jpeg-bytes')
        ->and($result->analysisFailed)->toBeTrue()
        ->and($result->hasAnalysis())->toBeFalse();
});

test('degrades fully to the original photo with no metadata when both stages fail, without throwing', function () {
    $backgroundRemover = Mockery::mock(BackgroundRemover::class);
    $backgroundRemover->shouldReceive('removeBackground')->once()->andThrow(new RuntimeException('replicate down'));

    $optimizer = Mockery::mock(ProductImageOptimizer::class);
    $optimizer->shouldNotReceive('optimize');

    $analyzer = Mockery::mock(ProductImageAiAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with('original-bytes', 'image/jpeg')->andThrow(new RuntimeException('anthropic down'));

    $result = (new ProductImagePipeline($backgroundRemover, $optimizer, $analyzer))
        ->process('original-bytes', 'image/jpeg');

    expect($result->backgroundRemovalFailed)->toBeTrue()
        ->and($result->analysisFailed)->toBeTrue()
        ->and($result->hasOptimizedImage())->toBeFalse()
        ->and($result->hasAnalysis())->toBeFalse();
});

test('happy path returns optimized images and analysis together', function () {
    $backgroundRemover = Mockery::mock(BackgroundRemover::class);
    $backgroundRemover->shouldReceive('removeBackground')->once()->andReturn('clean-png-bytes');

    $optimized = ['transparent' => 'png-bytes', 'webp' => 'webp-bytes', 'fallback' => 'jpeg-bytes'];
    $optimizer = Mockery::mock(ProductImageOptimizer::class);
    $optimizer->shouldReceive('optimize')->once()->with('clean-png-bytes')->andReturn($optimized);

    $analysis = new ProductImageAnalysis('blue-mug', 'A blue ceramic mug', 'Blue Mug', 'A sturdy blue mug.', ['mug', 'ceramic']);
    $analyzer = Mockery::mock(ProductImageAiAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with('webp-bytes', 'image/webp')->andReturn($analysis);

    $result = (new ProductImagePipeline($backgroundRemover, $optimizer, $analyzer))
        ->process('original-bytes', 'image/jpeg');

    expect($result->backgroundRemovalFailed)->toBeFalse()
        ->and($result->analysisFailed)->toBeFalse()
        ->and($result->optimizedImages)->toBe($optimized)
        ->and($result->analysis)->toBe($analysis);
});
