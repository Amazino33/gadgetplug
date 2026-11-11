<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PosSale;
use App\Models\Product;
use App\Support\Engagement\Conditions\ConditionRegistry;
use App\Support\Engagement\DestinationResolver;
use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\FeatureUsage;
use App\Support\Engagement\Moments\CashSalesTodayReached;
use App\Support\Engagement\Moments\FirstProductAdded;
use App\Support\Engagement\Moments\PendingMoments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Wiring for in-app tips: the registries, the usage-cache refresh, and the
 * moment listeners.
 *
 * Every listener here runs after the surrounding transaction commits and
 * swallows its own errors. A tip is never worth a failed sale.
 */
class EngagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureRegistry::class);
        $this->app->singleton(ConditionRegistry::class);
        $this->app->singleton(DestinationResolver::class);
        $this->app->singleton(PendingMoments::class);
        $this->app->scoped(FeatureUsage::class);
    }

    public function boot(): void
    {
        $this->refreshUsageWhenSourcesChange();
        $this->listenForMoments();
    }

    /**
     * Saving a row that counts as use refreshes that vendor's cached usage,
     * so a tip about debts disappears the moment the first debt is recorded.
     */
    private function refreshUsageWhenSourcesChange(): void
    {
        $models = [];

        foreach ($this->app->make(FeatureRegistry::class)->all() as $feature) {
            foreach ($feature->sourceModels() as $model) {
                $models[$model] = true;
            }
        }

        foreach (array_keys($models) as $model) {
            $model::saved(function (Model $record): void {
                $vendorId = $record->getAttribute('vendor_id');

                if ($vendorId) {
                    self::safely(fn () => FeatureUsage::bump((int) $vendorId));
                }
            });
        }
    }

    private function listenForMoments(): void
    {
        PosSale::created(function (PosSale $sale): void {
            self::safely(fn () => app(CashSalesTodayReached::class)->handleSale($sale, app(PendingMoments::class)));
        });

        Product::created(function (Product $product): void {
            self::safely(fn () => app(FirstProductAdded::class)->handleProduct($product, app(PendingMoments::class)));
        });
    }

    private static function safely(callable $callback): void
    {
        DB::afterCommit(function () use ($callback): void {
            try {
                $callback();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
