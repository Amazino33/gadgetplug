<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PosSale;
use App\Models\Product;
use App\Support\Engagement\Conditions\ConditionRegistry;
use App\Support\Engagement\DestinationResolver;
use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\FeatureUsage;
use App\Support\Engagement\Moments\MomentRecorder;
use App\Support\Engagement\Moments\PendingMoments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Wiring for in-app tips: the registries, the usage-cache recheck, and the
 * moment listeners.
 *
 * Every listener here goes through guarded(): whatever it decides during the
 * save is wrapped so an exception is reported and swallowed, and anything it
 * writes happens after the surrounding transaction commits, wrapped the same
 * way. A tip is never worth a failed sale or a failed sync.
 */
class EngagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureRegistry::class);
        $this->app->singleton(ConditionRegistry::class);
        $this->app->singleton(DestinationResolver::class);
        $this->app->singleton(PendingMoments::class);
        // Per request (or queued job): both hold memos that must not outlive it.
        $this->app->scoped(FeatureUsage::class);
        $this->app->scoped(MomentRecorder::class);
    }

    public function boot(): void
    {
        $this->recheckUsageWhenSourcesChange();
        $this->listenForMoments();
    }

    /**
     * A saved row that counts as use may turn a cached "not used yet" into a
     * date. Only that answer is cleared; a cached date is never touched,
     * because first use cannot change. Once this request has seen the keys
     * holding dates, further saves do no cache work at all.
     */
    private function recheckUsageWhenSourcesChange(): void
    {
        $byModel = [];

        foreach ($this->app->make(FeatureRegistry::class)->all() as $feature) {
            foreach ($feature->sources() as $source) {
                if ($source->model) {
                    $byModel[$source->model][] = [$feature, $source];
                }
            }
        }

        foreach ($byModel as $model => $pairs) {
            $model::saved(function (Model $record) use ($pairs): void {
                self::guarded(function () use ($record, $pairs): ?callable {
                    $vendorId = (int) $record->getAttribute('vendor_id');

                    if (! $vendorId) {
                        return null;
                    }

                    $usage = app(FeatureUsage::class);
                    $keys = [];

                    foreach ($pairs as [$feature, $source]) {
                        $userIds = [];

                        foreach (array_keys($source->userColumns) as $column) {
                            if ($id = (int) $record->getAttribute($column)) {
                                $userIds[] = $id;
                            }
                        }

                        array_push($keys, ...$usage->keysToRecheck($feature, $vendorId, $userIds));
                    }

                    return $keys === [] ? null : fn () => $usage->recheck($keys);
                });
            });
        }
    }

    private function listenForMoments(): void
    {
        PosSale::created(function (PosSale $sale): void {
            self::guarded(fn () => app(MomentRecorder::class)->saleSaved($sale));
        });

        Product::created(function (Product $product): void {
            self::guarded(fn () => app(MomentRecorder::class)->productCreated($product));
        });
    }

    /**
     * Run $decide now; if it hands back work, run that after commit. Neither
     * step can throw out of here.
     *
     * @param  callable(): (callable|null)  $decide
     */
    public static function guarded(callable $decide): void
    {
        try {
            $after = $decide();
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($after === null) {
            return;
        }

        DB::afterCommit(function () use ($after): void {
            try {
                $after();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
