<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Support\Engagement\Features\Feature;
use App\Support\Engagement\Moments\Moment;
use LogicException;

/**
 * Every feature the tips and Learn system knows about, in Learn-page order.
 *
 * The single source of truth for "what is a feature and has this person used
 * it". Tips hide themselves with it, the Learn page ticks with it, the tip
 * funnel counts adoptions with it and the adoption report lists vendors with
 * it. Adding a feature: write the class, list it here, run `features:sync`.
 */
class FeatureRegistry
{
    /** @var array<int, class-string<Feature>> */
    public const FEATURES = [
        Features\SalesPointFeature::class,
        Features\CustomerDebtsFeature::class,
        Features\CashSubmissionsFeature::class,
        Features\ExpensesFeature::class,
        Features\ProductPhotosFeature::class,
        Features\ProductImportFeature::class,
        Features\ProcurementFeature::class,
        Features\StockCountFeature::class,
        Features\TeamMembersFeature::class,
        Features\MultiStoreFeature::class,
        Features\SettlementStatementFeature::class,
        Features\AiAssistantFeature::class,
    ];

    /** @var array<string, Feature>|null */
    private ?array $features = null;

    /** @var array<string, Moment>|null */
    private ?array $moments = null;

    /** @return array<string, Feature> key => feature, in registry order */
    public function all(): array
    {
        if ($this->features !== null) {
            return $this->features;
        }

        $features = [];

        foreach (static::FEATURES as $class) {
            $feature = app($class);
            $key = $feature->key();

            if (isset($features[$key])) {
                throw new LogicException("Two features share the key [{$key}].");
            }

            $features[$key] = $feature;
        }

        return $this->features = $features;
    }

    public function find(?string $key): ?Feature
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /** The feature whose "Show me" goes to $routeName, if any. */
    public function forRoute(string $routeName): ?Feature
    {
        foreach ($this->all() as $feature) {
            if ($feature->destinationRoute() === $routeName) {
                return $feature;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, Moment> every moment any feature can trigger */
    public function moments(): array
    {
        if ($this->moments !== null) {
            return $this->moments;
        }

        $moments = [];

        foreach ($this->all() as $feature) {
            foreach ($feature->moments() as $class) {
                $moment = app($class);

                if (isset($moments[$moment->key()]) && ! $moments[$moment->key()] instanceof $class) {
                    throw new LogicException("Two moments share the key [{$moment->key()}].");
                }

                $moments[$moment->key()] = $moment;
            }
        }

        return $this->moments = $moments;
    }

    public function moment(?string $key): ?Moment
    {
        return $key === null ? null : ($this->moments()[$key] ?? null);
    }
}
