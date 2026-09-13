<?php

use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Support\Tours\TourRegistry;
use Database\Seeders\HelpCenterSeeder;

beforeEach(fn () => (new HelpCenterSeeder)->run());

it('publishes the guides the contextual buttons deep-link to', function () {
    // These three slugs are hard-coded into header actions on the Procurements,
    // Products and Suppliers pages. If a title here is reworded without moving
    // the button, the button silently disappears -- which is what this catches.
    foreach ([
        'how-do-i-record-a-procurement',
        'how-do-i-add-a-product',
        'how-do-i-add-a-supplier',
    ] as $slug) {
        expect(HelpArticle::published()->where('slug', $slug)->exists())->toBeTrue($slug);
    }
});

it('only names tours that exist', function () {
    HelpArticle::whereNotNull('tour_key')->each(
        fn (HelpArticle $article) => expect(TourRegistry::has($article->tour_key))->toBeTrue($article->tour_key)
    );

    expect(HelpArticle::whereNotNull('tour_key')->count())->toBe(3);
});

it('can be run twice without duplicating anything', function () {
    $articles = HelpArticle::count();
    $categories = HelpCategory::count();

    (new HelpCenterSeeder)->run();

    expect(HelpArticle::count())->toBe($articles)
        ->and(HelpCategory::count())->toBe($categories);
});

it('does not overwrite a guide an admin has rewritten', function () {
    $article = HelpArticle::firstWhere('slug', 'how-do-i-add-a-product');
    $article->update(['body' => '<p>My own words, with a screenshot.</p>']);

    (new HelpCenterSeeder)->run();

    expect($article->fresh()->body)->toBe('<p>My own words, with a screenshot.</p>');
});

it('leaves the seeded guides findable by an obvious search word', function () {
    expect(HelpArticle::published()->search('procurement')->pluck('slug'))
        ->toContain('how-do-i-record-a-procurement')
        ->and(HelpArticle::published()->search('supplier')->pluck('slug'))
        ->toContain('how-do-i-add-a-supplier');
});
