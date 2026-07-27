<?php

namespace App\Services\ImageProcessing;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Removes the background from a product photo via Replicate's hosted
// rembg model — same open-source model as self-hosted rembg, run as an
// API call so this PHP app never needs a Python runtime.
class BackgroundRemover
{
    private const MODEL = 'cjwbw/rembg';

    // Replicate accepts data URIs directly for image inputs, so the upload
    // never needs to be publicly hosted first.
    public function removeBackground(string $imageBinary, string $mimeType): string
    {
        $token = config('services.replicate.token');

        if (! $token) {
            throw new RuntimeException('REPLICATE_API_TOKEN is not configured.');
        }

        $dataUri = 'data:'.$mimeType.';base64,'.base64_encode($imageBinary);

        $response = Http::withToken($token)
            ->withHeaders(['Prefer' => 'wait=30'])
            ->timeout(45)
            ->post('https://api.replicate.com/v1/models/'.self::MODEL.'/predictions', [
                'input' => ['image' => $dataUri],
            ])
            ->throw()
            ->json();

        $outputUrl = $this->awaitOutput($response, $token);

        return Http::timeout(30)->get($outputUrl)->throw()->body();
    }

    private function awaitOutput(array $prediction, string $token): string
    {
        $deadline = now()->addSeconds(30);

        while (in_array($prediction['status'], ['starting', 'processing'], true)) {
            if (now()->greaterThan($deadline)) {
                throw new RuntimeException('Background removal timed out.');
            }

            usleep(500_000);

            $prediction = Http::withToken($token)
                ->timeout(15)
                ->get($prediction['urls']['get'])
                ->throw()
                ->json();
        }

        if ($prediction['status'] !== 'succeeded' || empty($prediction['output'])) {
            throw new RuntimeException('Background removal failed: '.($prediction['error'] ?? 'unknown error'));
        }

        return is_array($prediction['output']) ? $prediction['output'][0] : $prediction['output'];
    }
}
