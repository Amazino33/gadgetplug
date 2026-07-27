<?php

namespace App\Services\ImageProcessing;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use RuntimeException;

// Asks a Claude vision model to draft SEO metadata for a product photo.
// Uses structured outputs (output_config.format) so the response is
// guaranteed-parseable JSON rather than free text we'd have to coax and parse.
class ProductImageAiAnalyzer
{
    private const MODEL = 'claude-haiku-4-5';

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'filename' => [
                'type' => 'string',
                'description' => 'SEO-friendly filename in kebab-case, descriptive, no file extension.',
            ],
            'alt_text' => [
                'type' => 'string',
                'description' => 'Concise, descriptive alt text for accessibility and SEO.',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'A short image title attribute.',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'A short marketing-style product description, 1-2 sentences.',
            ],
            'keywords' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => '5 to 10 relevant SEO keywords/tags for this product.',
            ],
        ],
        'required' => ['filename', 'alt_text', 'title', 'description', 'keywords'],
        'additionalProperties' => false,
    ];

    public function analyze(string $imageBinary, string $mimeType): ProductImageAnalysis
    {
        $apiKey = config('services.anthropic.key');

        if (! $apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $client = new Client(apiKey: $apiKey);

        $message = $client->messages->create(
            model: self::MODEL,
            maxTokens: 1024,
            messages: [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => base64_encode($imageBinary),
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Analyze this product photo and generate SEO metadata for an e-commerce '
                                .'product listing: an SEO-friendly filename, alt text, an image title, a short '
                                .'product description, and 5-10 relevant keywords.',
                        ],
                    ],
                ],
            ],
            outputConfig: [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => self::SCHEMA,
                ],
            ],
        );

        $textBlock = collect($message->content)->first(fn ($block) => $block instanceof TextBlock);

        if (! $textBlock) {
            throw new RuntimeException('Claude vision response contained no text content.');
        }

        $data = json_decode($textBlock->text, true, flags: JSON_THROW_ON_ERROR);

        return ProductImageAnalysis::fromArray($data);
    }
}
