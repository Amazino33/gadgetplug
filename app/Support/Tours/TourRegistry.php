<?php

namespace App\Support\Tours;

/**
 * Every guided tour in the vendor panel, defined in code.
 *
 * A tour is a list of *chapters*, not a flat list of steps, because the flows
 * vendors actually get lost in cross page boundaries — "record a procurement"
 * starts in the Filament sidebar and finishes in a Blade wizard on a different
 * layout. Each chapter carries a `match` regex tested against the current
 * pathname, so the runtime resumes a tour by asking "which chapter belongs to
 * the page I am on now?" rather than by trusting a saved step number. A vendor
 * who wanders off mid-tour therefore never gets a popover pointing at nothing.
 *
 * Selectors are `data-tour` attributes or IDs that already existed. Anything
 * positional (nth-child, generated Filament classes) is off limits: it breaks
 * the next time the page is touched, and a tour that highlights the wrong
 * button is worse than no tour.
 */
class TourRegistry
{
    /**
     * The vendor sidebar as a pair of steps: open the group, then click the item.
     *
     * Every panel-side chapter starts this way, and the groups are collapsed by
     * default, so a vendor who is told to "click Procurements" genuinely cannot
     * see it yet. Factored out because getting that wrong once would be getting
     * it wrong in three tours.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function sidebarSteps(string $group, string $item, string $label, string $whatItIs): array
    {
        return [
            [
                'element' => '[data-group-label="'.$group.'"] .fi-sidebar-group-btn',
                'title' => 'Open '.$group,
                'body' => 'The menu starts closed to keep it short. Tap <strong>'.$group.'</strong> to open this section.',
                'side' => 'right',
            ],
            [
                'element' => '[data-tour="'.$item.'"]',
                'title' => 'Tap '.$label,
                'body' => $whatItIs,
                'side' => 'right',

                // Last step of the chapter: the vendor's own click is what moves
                // the tour on, so the button says so rather than "Done".
                'done_label' => 'Got it, I will tap it',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'record-procurement' => [
                'key' => 'record-procurement',
                'title' => 'Record a procurement',
                'description' => 'From the menu to a saved delivery: pick the supplier, then enter what arrived and what it cost.',
                'icon' => 'heroicon-o-inbox-arrow-down',
                'start_path' => '/plug/{vendor}',
                'chapters' => [
                    [
                        'match' => '^/plug/[^/]+/?$',
                        'steps' => static::sidebarSteps(
                            'Procurement',
                            'nav-procurements',
                            'Procurements',
                            'This is the list of every delivery you have recorded, and where a new one starts.',
                        ),
                    ],
                    [
                        'match' => '^/plug/[^/]+/procurements/?$',
                        'steps' => [
                            [
                                'element' => '[data-tour="new-procurement"]',
                                'title' => 'Start a new one',
                                'body' => 'Tap <strong>New Procurement</strong>. It opens a five-step form: supplier, items, logistics, money, then a summary to check before you save.',
                                'side' => 'bottom',
                                'done_label' => 'Got it, I will tap it',
                            ],
                        ],
                    ],
                    [
                        'match' => '^/procurement/create/?$',
                        'steps' => [
                            [
                                'element' => '#receiptDropzone',
                                'title' => 'Snap the receipt (optional)',
                                'body' => 'Photograph the supplier invoice now and it stays attached to this delivery. Useful later when a figure is queried.',
                                'side' => 'bottom',
                            ],
                            [
                                'element' => '#supplierSearch',
                                'title' => 'Find the supplier',
                                'body' => 'Type part of their name to narrow the list below.',
                                'side' => 'bottom',
                            ],
                            [
                                'element' => '[data-tour="procurement-new-supplier"]',
                                'title' => 'Not on the list?',
                                'body' => 'Add them under <strong>Suppliers</strong> first, then come back here. A delivery always belongs to a supplier.',
                                'side' => 'bottom',
                            ],
                            [
                                'element' => '#supplierGrid',
                                'title' => 'Pick them to continue',
                                'body' => 'Tap <strong>Select</strong> on the right supplier card. That saves step 1 and takes you on to the items.',
                                'side' => 'top',
                                'done_label' => 'Got it, I will pick one',
                            ],
                        ],
                    ],
                    [
                        'match' => '^/procurement/items/?$',
                        'steps' => [
                            [
                                'element' => '[data-tour="procurement-add-item"]',
                                'title' => 'Add what arrived',
                                'body' => 'One line per product. Tap <strong>Add Item</strong> for each different thing in the delivery.',
                                'side' => 'bottom',
                            ],
                            [
                                'element' => '#itemsList',
                                'title' => 'Quantity and cost',
                                'body' => 'Search the product, then enter <strong>how many</strong> came and <strong>what one unit cost you</strong>, not the selling price. That cost is what your profit gets measured against later.',
                                'side' => 'top',
                            ],
                            [
                                'element' => '[data-tour="procurement-items-continue"]',
                                'title' => 'Then finish the last three steps',
                                'body' => 'Continue takes you through logistics, payment, and a summary. Nothing touches your stock until you press <strong>Submit</strong> on that final screen, and the stock only moves once the delivery is approved at the receiving branch.',
                                'side' => 'top',
                            ],
                        ],
                    ],
                ],
            ],

            'add-product' => [
                'key' => 'add-product',
                'title' => 'Add a product',
                'description' => 'Put a new item in your catalogue so it can be sold at the till and counted in stock.',
                'icon' => 'heroicon-o-rectangle-stack',
                'start_path' => '/plug/{vendor}',
                'chapters' => [
                    [
                        'match' => '^/plug/[^/]+/?$',
                        'steps' => static::sidebarSteps(
                            'Products',
                            'nav-products',
                            'Products',
                            'Your catalogue: everything you sell, whether online or over the counter.',
                        ),
                    ],
                    [
                        'match' => '^/plug/[^/]+/products/?$',
                        'steps' => [
                            [
                                'element' => '[data-tour="products-search"]',
                                'title' => 'Check it is not already there',
                                'body' => 'Search the name first. Adding a second copy of a product you already stock splits its stock figure in two.',
                                'side' => 'bottom',
                            ],
                            [
                                'element' => '[data-tour="new-product"]',
                                'title' => 'Create the product',
                                'body' => 'Tap <strong>New product</strong> to open the form.',
                                'side' => 'bottom',
                                'done_label' => 'Got it, I will tap it',
                            ],
                        ],
                    ],
                    [
                        'match' => '^/plug/[^/]+/products/create/?$',
                        'steps' => [
                            [
                                'element' => '[data-tour="product-name"]',
                                'title' => 'Name it the way you say it',
                                'body' => 'Whatever your staff would type at the till. This is what the POS search matches on.',
                                'side' => 'bottom',
                            ],
                            [
                                'element' => '[data-tour="product-cost-price"]',
                                'title' => 'What the customer pays',
                                'body' => 'The selling price. What you <em>paid</em> for it goes under <strong>Advanced settings</strong> — the gap between the two is the profit every report on this platform is built from, so it is worth filling in.',
                                'side' => 'bottom',
                            ],
                            [
                                'element' => '[data-tour="product-status"]',
                                'title' => 'Publish when it is ready',
                                'body' => 'A draft is saved but not sellable. Set it to <strong>Published</strong> once the price and stock are right.',
                                'side' => 'left',
                            ],
                        ],
                    ],
                ],
            ],

            'daily-report' => [
                'key' => 'daily-report',
                'title' => 'Read your daily report',
                'description' => 'The one screen that tells you what sold, what money came in, and what needs restocking.',
                'icon' => 'heroicon-o-squares-2x2',
                'start_path' => '/plug/{vendor}',
                'chapters' => [
                    [
                        'match' => '^/plug/[^/]+/?$',
                        'steps' => static::sidebarSteps(
                            'Reports',
                            'nav-reports-hub',
                            'Reports',
                            'Every report starts here. It is the one page worth opening each morning.',
                        ),
                    ],
                    [
                        'match' => '^/plug/[^/]+/reports-hub/?$',
                        'steps' => [
                            [
                                'element' => '[data-tour="reports-cards"]',
                                'title' => 'Today at a glance',
                                'body' => 'Each card summarises one report: sales, money position, restocking, dead stock. Read the headline; that is usually the whole answer.',
                                'side' => 'bottom',
                            ],
                            [
                                'element' => '[data-tour="report-card-first"]',
                                'title' => 'A number in a badge needs you',
                                'body' => 'A count on a card means that many things are waiting on a decision: items to reorder, money unaccounted for. Tap the card to open the full report.',
                                'side' => 'bottom',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, static::all());
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    /**
     * The registry with {vendor} resolved, ready to hand to the browser.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forVendorSlug(string $slug): array
    {
        return array_map(function (array $tour) use ($slug): array {
            $tour['start_path'] = str_replace('{vendor}', $slug, $tour['start_path']);

            return $tour;
        }, static::all());
    }

    /**
     * Options for the Filament select on the article editor, so an author links
     * a guide to a tour by picking its title rather than typing a key.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $tour): string => $tour['title'], static::all());
    }
}
