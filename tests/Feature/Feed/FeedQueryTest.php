<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductInteraction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Feed\FeedCursor;
use App\Services\Feed\FeedQuery;
use App\Services\Feed\ProductInteractions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function feedVendor(string $name = 'Shop', bool $online = true): Vendor
{
    return Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => $name.' '.uniqid(),
        'online_sales_enabled' => $online,
    ]);
}

function feedProduct(Vendor $vendor, array $over = []): Product
{
    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Feed Cat'])->id,
        'name'           => 'Feed Item '.Str::random(6),
        'price'          => 10000,
        'cost_price'     => 6000,
        'stock_quantity' => 5,
        'status'         => 'published',
        'published_at'   => now()->subMinutes(random_int(1, 10000)),
        'show_online'    => true,
    ], $over));
}

/** Walk the whole feed, page by page, exactly as the client would. */
function walkFeed(int $seed = 0, ?int $categoryId = null, ?string $search = null, int $perPage = 5): array
{
    $feed = app(FeedQuery::class);
    $ids = [];
    $cursor = null;
    $guard = 0;

    do {
        $page = $feed->page($seed, $cursor, $categoryId, $search, $perPage);
        $ids = array_merge($ids, $page['posts']->pluck('id')->all());
        $cursor = $page['next_cursor'];
    } while ($cursor && ++$guard < 50);

    return $ids;
}

describe('what the feed shows', function () {
    test('a vendor with the marketplace switched off is absent', function () {
        $open = feedVendor('Open');
        $shut = feedVendor('Shut', online: false);

        $visible = feedProduct($open);
        $hidden = feedProduct($shut);

        $ids = walkFeed();

        expect($ids)->toContain($visible->id)
            ->and($ids)->not->toContain($hidden->id);
    });

    test('an out-of-stock product is absent', function () {
        $vendor = feedVendor();
        $inStock = feedProduct($vendor, ['stock_quantity' => 3]);
        $sold = feedProduct($vendor, ['stock_quantity' => 0]);

        $ids = walkFeed();

        expect($ids)->toContain($inStock->id)
            ->and($ids)->not->toContain($sold->id);
    });

    test('an unpublished product is absent', function () {
        $vendor = feedVendor();
        $live = feedProduct($vendor);
        $draft = feedProduct($vendor, ['status' => 'draft']);

        $ids = walkFeed();

        expect($ids)->toContain($live->id)
            ->and($ids)->not->toContain($draft->id);
    });

    test('the category filter narrows it', function () {
        $vendor = feedVendor();
        $phones = Category::create(['name' => 'Phones '.uniqid(), 'slug' => 'phones-'.uniqid()]);

        $phone = feedProduct($vendor, ['category_id' => $phones->id]);
        $other = feedProduct($vendor);

        $ids = walkFeed(categoryId: $phones->id);

        expect($ids)->toContain($phone->id)
            ->and($ids)->not->toContain($other->id);
    });

    test('search matches name and description', function () {
        $vendor = feedVendor();
        $byName = feedProduct($vendor, ['name' => 'Anker PowerCore 20000']);
        $byDesc = feedProduct($vendor, ['description' => 'A rugged Anker charger']);
        $neither = feedProduct($vendor, ['name' => 'Something else entirely']);

        $ids = walkFeed(search: 'Anker');

        expect($ids)->toContain($byName->id)
            ->and($ids)->toContain($byDesc->id)
            ->and($ids)->not->toContain($neither->id);
    });
});

describe('paging through it', function () {
    test('every product appears exactly once across all pages', function () {
        $vendors = collect(range(1, 4))->map(fn ($i) => feedVendor("Shop {$i}"));
        $made = collect();

        foreach ($vendors as $vendor) {
            for ($i = 0; $i < 6; $i++) {
                $made->push(feedProduct($vendor)->id);
            }
        }

        $ids = walkFeed(seed: 40, perPage: 5);

        // No duplicates — the cursor never re-serves a post it already gave.
        expect(count($ids))->toBe(count(array_unique($ids)))
            // And nothing was skipped between pages.
            ->and(collect($ids)->sort()->values()->all())->toBe($made->sort()->values()->all());
    });

    test('a product published mid-scroll does not shift the pages under the reader', function () {
        $vendor = feedVendor();
        $original = collect(range(1, 10))->map(fn () => feedProduct($vendor)->id);

        $feed = app(FeedQuery::class);
        $first = $feed->page(0, null, null, null, 4);
        $seen = $first['posts']->pluck('id')->all();

        // Somebody lists something while the reader is mid-feed. With offset
        // paging this is exactly where a post gets repeated or lost.
        feedProduct($vendor);

        $second = $feed->page(0, $first['next_cursor'], null, null, 4);
        $secondIds = $second['posts']->pluck('id')->all();

        expect(array_intersect($seen, $secondIds))->toBeEmpty();
    });

    test('a tampered cursor restarts the feed rather than failing', function () {
        $vendor = feedVendor();
        feedProduct($vendor);

        $page = app(FeedQuery::class)->page(0, 'not-a-real-cursor', null, null, 5);

        expect($page['posts'])->not->toBeEmpty();
    });

    test('different sessions do not open on the same post', function () {
        $vendor = feedVendor();
        collect(range(1, 30))->each(fn () => feedProduct($vendor));

        $feed = app(FeedQuery::class);
        $openings = collect(range(0, 90, 10))
            ->map(fn ($seed) => $feed->page($seed, null, null, null, 3)['posts']->first()['id'] ?? null)
            ->unique();

        // Not a guarantee that every seed differs, but a rotation that never
        // moves would collapse to one.
        expect($openings->count())->toBeGreaterThan(1);
    });
});

describe('one store cannot flood the feed', function () {
    test('a run is broken whenever another store has a post left to break it with', function () {
        $loud = feedVendor('Loud');
        $quiet = feedVendor('Quiet');

        collect(range(1, 12))->each(fn () => feedProduct($loud));
        collect(range(1, 6))->each(fn () => feedProduct($quiet));

        $posts = app(FeedQuery::class)->page(0, null, null, null, 12)['posts'];
        $vendorOf = Product::whereIn('id', $posts->pluck('id'))->pluck('vendor_id', 'id');
        $sequence = $posts->map(fn ($p) => $vendorOf[$p['id']])->values();

        // The guarantee is exactly this: a third consecutive post from one
        // store only survives when nothing else was left in the page to put
        // between them.
        //
        // The cap works within the page and not across the fetched window,
        // because reordering across the page boundary is what made the cursor
        // serve duplicates — and a feed that repeats posts is a worse fault
        // than one that occasionally shows three of a store in a row. Random
        // feed_bucket assignment is the primary mixer; this is the backstop.
        // Collected rather than asserted inside the loop: a loop that asserts
        // only on violation makes NO assertion in the common case, and Pest
        // rightly calls that risky — it would pass whether or not the feed
        // worked.
        $violations = [];
        $run = 0;
        $previous = null;

        foreach ($sequence as $i => $vendor) {
            $run = $vendor === $previous ? $run + 1 : 1;
            $previous = $vendor;

            if ($run > 2 && $sequence->slice($i)->contains(fn ($v) => $v !== $vendor)) {
                $violations[] = "position {$i}";
            }
        }

        expect($sequence)->toHaveCount(12)
            ->and($violations)->toBeEmpty();
    });

    test('a single-store catalogue still fills the feed rather than emptying it', function () {
        $only = feedVendor('Only Shop');
        collect(range(1, 8))->each(fn () => feedProduct($only));

        // The cap must not become a reason to show nothing.
        expect(app(FeedQuery::class)->page(0, null, null, null, 8)['posts'])->toHaveCount(8);
    });
});

describe('likes and saves', function () {
    test('a guest likes with a device token, and unlikes again', function () {
        $product = feedProduct(feedVendor());
        $service = app(ProductInteractions::class);

        expect($service->toggleLike($product, null, 'device-a'))->toBeTrue()
            ->and($product->fresh()->like_count)->toBe(1);

        expect($service->toggleLike($product, null, 'device-a'))->toBeFalse()
            ->and($product->fresh()->like_count)->toBe(0);
    });

    test('two devices liking the same post count twice, one device only once', function () {
        $product = feedProduct(feedVendor());
        $service = app(ProductInteractions::class);

        $service->toggleLike($product, null, 'device-a');
        $service->toggleLike($product, null, 'device-b');

        expect($product->fresh()->like_count)->toBe(2)
            ->and(ProductInteraction::likes()->count())->toBe(2);
    });

    test('saving is refused for a guest at the service, not only in the UI', function () {
        $product = feedProduct(feedVendor());

        expect(fn () => app(ProductInteractions::class)->save($product, null))
            ->toThrow(RuntimeException::class, 'needs an account');
    });

    test('saving writes the existing wishlist, so the wishlist page keeps working', function () {
        $product = feedProduct(feedVendor());
        $user = User::factory()->create();
        $service = app(ProductInteractions::class);

        $service->save($product, $user);

        expect(App\Models\Wishlist::where('user_id', $user->id)->where('product_id', $product->id)->exists())
            ->toBeTrue()
            ->and($service->hasSaved($product, $user))->toBeTrue();

        $service->unsave($product, $user);

        expect($service->hasSaved($product, $user))->toBeFalse();
    });

    test('saving twice does not duplicate the wishlist row', function () {
        $product = feedProduct(feedVendor());
        $user = User::factory()->create();
        $service = app(ProductInteractions::class);

        $service->save($product, $user);
        $service->save($product, $user);

        expect(App\Models\Wishlist::where('user_id', $user->id)->count())->toBe(1);
    });
});

describe('signing in keeps what you did as a guest', function () {
    test('device likes become the account\'s likes', function () {
        $a = feedProduct(feedVendor());
        $b = feedProduct(feedVendor());
        $service = app(ProductInteractions::class);
        $user = User::factory()->create();

        $service->toggleLike($a, null, 'device-a');
        $service->toggleLike($b, null, 'device-a');

        expect($service->mergeDeviceInto($user, 'device-a'))->toBe(2);

        expect($service->likedProductIds([$a->id, $b->id], $user, null))
            ->toHaveCount(2)
            ->and(ProductInteraction::whereNotNull('device_token')->count())->toBe(0);
    });

    test('a product the account already liked is not counted twice', function () {
        $product = feedProduct(feedVendor());
        $service = app(ProductInteractions::class);
        $user = User::factory()->create();

        $service->toggleLike($product, $user, null);
        $service->toggleLike($product, null, 'device-a');

        expect($product->fresh()->like_count)->toBe(2);

        $service->mergeDeviceInto($user, 'device-a');

        // One person, one like. The orphan row goes, and the count is repaired
        // by reconciliation rather than guessed at here.
        expect(ProductInteraction::likes()->where('product_id', $product->id)->count())->toBe(1);
    });
});

describe('shares', function () {
    test('each share is logged and counted, never toggled', function () {
        $product = feedProduct(feedVendor());
        $service = app(ProductInteractions::class);

        $service->logShare($product, null, 'device-a');
        $service->logShare($product, null, 'device-a');

        expect(ProductInteraction::shares()->count())->toBe(2)
            ->and($product->fresh()->share_count)->toBe(2);
    });
});
