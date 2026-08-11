<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Product;

// One ranked row from ProductVelocityService::topSellers() — units sold and
// revenue within whatever period was asked for, not a trailing window, so
// this ranks "who actually sold the most this week/month" rather than
// feeding the restock tiering (that's what forVendor()/forProduct() are for).
readonly class TopSellerRow
{
    public function __construct(
        public Product $product,
        public int $unitsSold,
        public float $revenue,
        public float $dailyVelocity,
    ) {}
}
