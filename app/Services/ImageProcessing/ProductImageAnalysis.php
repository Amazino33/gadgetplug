<?php

namespace App\Services\ImageProcessing;

// Structured result of the vision model's SEO-metadata suggestion for one
// product photo. All fields are editable by the vendor before saving —
// this is a suggestion, not a fact.
readonly class ProductImageAnalysis
{
    /**
     * @param  string[]  $keywords
     */
    public function __construct(
        public string $filename,
        public string $altText,
        public string $title,
        public string $description,
        public array $keywords,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            filename: $data['filename'],
            altText: $data['alt_text'],
            title: $data['title'],
            description: $data['description'],
            keywords: $data['keywords'],
        );
    }
}
