<?php

namespace Database\Seeders;

use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Illuminate\Database\Seeder;

/**
 * Starter guides, so the help centre is not empty on the day it ships.
 *
 * These exist mainly so the contextual "?" buttons have somewhere to point:
 * each one is hidden until the guide with its slug is published, so without at
 * least these three the whole deep-linking half of the feature is invisible.
 *
 * They are a floor, not the finished thing. Every one of them is written to be
 * rewritten from the admin panel, with the screenshots and GIFs that can only
 * be added there. Re-running this seeder will not overwrite an edited article:
 * it only fills in what is missing.
 */
class HelpCenterSeeder extends Seeder
{
    public function run(): void
    {
        $procurement = $this->category('Procurement', 'heroicon-o-inbox-arrow-down', 1);
        $products = $this->category('Products', 'heroicon-o-rectangle-stack', 2);
        $reports = $this->category('Reports', 'heroicon-o-squares-2x2', 3);

        $this->article($procurement, [
            'title' => 'How do I record a procurement?',
            'excerpt' => 'From the menu to a saved delivery, in five steps.',
            'tour_key' => 'record-procurement',
            'body' => <<<'HTML'
                <p>A procurement is you recording a delivery you have paid for or
                received. Until you record it, the stock does not exist on the
                system and nothing can be sold against it.</p>
                <ol>
                    <li>Open the menu and tap <strong>Procurement</strong>, then
                        <strong>Procurements</strong>.</li>
                    <li>Tap <strong>New Procurement</strong>.</li>
                    <li>Photograph the supplier's receipt if you have it, choose
                        the branch the goods are going to, then tap
                        <strong>Select</strong> on the supplier you bought from.
                        If they are not listed, add them under
                        <strong>Suppliers</strong> first.</li>
                    <li>Add one line per product: search the product, enter how
                        many arrived, and what <em>one unit</em> cost you. That
                        is the cost price, not what you will sell it for.</li>
                    <li>Work through transport cost, payment, and the summary,
                        then tap <strong>Submit</strong>.</li>
                </ol>
                <p>Nothing moves your stock until the delivery is approved at the
                branch it was sent to. Somebody there has to check it against the
                waybill first.</p>
                HTML,
        ], 1);

        $this->article($procurement, [
            'title' => 'How do I add a supplier?',
            'excerpt' => 'Every delivery belongs to a supplier, so they come first.',
            'body' => <<<'HTML'
                <p>You cannot record a procurement without saying who you bought
                from, so a new supplier has to be added before their first
                delivery.</p>
                <ol>
                    <li>Open the menu and tap <strong>Procurement</strong>, then
                        <strong>Suppliers</strong>.</li>
                    <li>Tap <strong>New supplier</strong>.</li>
                    <li>Fill in their name. Phone and address are optional but
                        worth having when a delivery is queried.</li>
                    <li>Save. They will now appear when you start a
                        procurement.</li>
                </ol>
                <p>Suppliers belong to your store only. Nobody else on the
                platform can see yours.</p>
                HTML,
        ], 2);

        $this->article($products, [
            'title' => 'How do I add a product?',
            'excerpt' => 'Put an item in your catalogue so it can be sold and counted.',
            'tour_key' => 'add-product',
            'body' => <<<'HTML'
                <ol>
                    <li>Open the menu and tap <strong>Products</strong>, then
                        <strong>Products</strong>.</li>
                    <li><strong>Search for it first.</strong> Adding a second
                        copy of something you already stock splits its stock
                        figure in two, and both halves will then be wrong.</li>
                    <li>Tap <strong>New product</strong>.</li>
                    <li>Name it the way your staff would say it at the till, and
                        pick a category.</li>
                    <li>Enter the cost price (what you pay) and the price (what
                        the customer pays). The gap between them is the profit
                        every report you have is built from.</li>
                    <li>Set the status to <strong>Published</strong> when it is
                        ready to sell. A draft is saved but cannot be sold.</li>
                </ol>
                HTML,
        ], 1);

        $this->article($reports, [
            'title' => 'How do I read my daily report?',
            'excerpt' => 'The one screen worth opening every morning.',
            'tour_key' => 'daily-report',
            'body' => <<<'HTML'
                <p>Open the menu and tap <strong>Reports</strong>. The page you
                land on summarises every report as one card.</p>
                <ul>
                    <li><strong>Sales pulse</strong> - what sold, and how that
                        compares to the day before.</li>
                    <li><strong>Money position</strong> - what is in your
                        accounts and what is owed to you.</li>
                    <li><strong>Restock</strong> - what is running low.</li>
                    <li><strong>Dead stock</strong> - what is sitting on the
                        shelf not moving.</li>
                </ul>
                <p>A number in a badge on a card means that many things are
                waiting on a decision from you. Tap the card to open the full
                report behind it.</p>
                HTML,
        ], 1);
    }

    protected function category(string $name, string $icon, int $sort): HelpCategory
    {
        return HelpCategory::firstOrCreate(
            ['name' => $name],
            ['icon' => $icon, 'sort_order' => $sort, 'is_published' => true],
        );
    }

    /**
     * firstOrCreate on the title, so an admin's rewrite is never clobbered by a
     * later `db:seed`.
     */
    protected function article(HelpCategory $category, array $attributes, int $sort): void
    {
        HelpArticle::firstOrCreate(
            ['title' => $attributes['title']],
            [
                ...$attributes,
                'help_category_id' => $category->id,
                'sort_order' => $sort,
                'is_published' => true,
                'published_at' => now(),
            ],
        );
    }
}
