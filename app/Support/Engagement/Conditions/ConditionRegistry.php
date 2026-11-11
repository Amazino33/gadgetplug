<?php

declare(strict_types=1);

namespace App\Support\Engagement\Conditions;

use App\Models\Product;
use App\Support\Engagement\TipContext;

/** Every condition a tip can be targeted with. */
class ConditionRegistry
{
    public const ALWAYS = 'always';

    public const FEWER_PRODUCTS = 'fewer_products';

    public const NEW_ACCOUNT = 'new_account';

    /** @var array<string, Condition>|null */
    private ?array $conditions = null;

    /** @return array<string, Condition> */
    public function all(): array
    {
        return $this->conditions ??= collect([
            new class implements Condition
            {
                public function key(): string
                {
                    return ConditionRegistry::ALWAYS;
                }

                public function label(): string
                {
                    return 'Always';
                }

                public function needsValue(): bool
                {
                    return false;
                }

                public function passes(TipContext $context, ?int $value): bool
                {
                    return true;
                }
            },
            new class implements Condition
            {
                public function key(): string
                {
                    return ConditionRegistry::FEWER_PRODUCTS;
                }

                public function label(): string
                {
                    return 'Business has fewer than N products (N = 1 means none yet)';
                }

                public function needsValue(): bool
                {
                    return true;
                }

                public function passes(TipContext $context, ?int $value): bool
                {
                    if (! $context->vendor) {
                        return false;
                    }

                    $limit = max(1, (int) $value);

                    // Counting at most N rows is enough to answer "fewer than N".
                    return Product::query()
                        ->where('vendor_id', $context->vendor->id)
                        ->limit($limit)
                        ->pluck('id')
                        ->count() < $limit;
                }
            },
            new class implements Condition
            {
                public function key(): string
                {
                    return ConditionRegistry::NEW_ACCOUNT;
                }

                public function label(): string
                {
                    return 'Business joined within the last N days';
                }

                public function needsValue(): bool
                {
                    return true;
                }

                public function passes(TipContext $context, ?int $value): bool
                {
                    return $context->vendor?->created_at !== null
                        && $context->vendor->created_at->gte(now()->subDays(max(1, (int) $value)));
                }
            },
        ])->keyBy(fn (Condition $c) => $c->key())->all();
    }

    public function find(?string $key): ?Condition
    {
        return $this->all()[$key ?? self::ALWAYS] ?? null;
    }

    /** @return array<string, string> key => label, for the admin picker */
    public function options(): array
    {
        return array_map(fn (Condition $c) => $c->label(), $this->all());
    }
}
