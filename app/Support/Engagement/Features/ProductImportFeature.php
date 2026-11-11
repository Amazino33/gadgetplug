<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\ImportLog;

class ProductImportFeature extends Feature
{
    public function key(): string
    {
        return 'product_import';
    }

    public function defaultTitle(): string
    {
        return 'Import products from a spreadsheet';
    }

    public function defaultDescription(): string
    {
        return 'Add hundreds of products at once from Excel or CSV instead of typing them one by one.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.pages.import-products';
    }

    protected function usageSources(): array
    {
        return [
            new UsageSource(
                query: fn () => ImportLog::query(),
                userColumns: ['user_id' => 'created_at'],
                model: ImportLog::class,
            ),
        ];
    }
}
