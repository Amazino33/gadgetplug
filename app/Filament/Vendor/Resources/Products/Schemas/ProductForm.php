<?php

namespace App\Filament\Vendor\Resources\Products\Schemas;

use App\Models\Store;
use App\Models\Tag;
use App\Services\ActiveStore;
use App\Services\ImageProcessing\ProductImagePipeline;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductForm
{
    // Transient, UI-only state used by the "Enhance with AI" flow — never
    // persisted to the Product model. Stripped before save via
    // stripTransientAiFields() (see CreateProduct/EditProduct pages).
    public const AI_TRANSIENT_FIELDS = [
        'ai_enhanced_file_key',
        'ai_suggested_filename',
        'ai_suggested_alt_text',
        'ai_suggested_title',
    ];

    public static function stripTransientAiFields(array $data): array
    {
        return array_diff_key($data, array_flip(self::AI_TRANSIENT_FIELDS));
    }

    public static function canSeeCostPrice(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $user && $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_cost_price')
        );
    }

    /**
     * The product form, as two things rather than eight.
     *
     * It used to open as eight bordered sections — Basic Information, Media,
     * Specifications, Status, Pricing, Inventory, Sales Channels, Tags — all
     * expanded, twenty-two fields, running about three screens. Every one of
     * those fields has a reason to exist, but only six of them are the product
     * as a commercial decision: what it is called, what shelf it sits on, which
     * branch stocks it, what it cost, what it sells for, and whether it is
     * live. A vendor adding their first product had to read past the other
     * sixteen to find them.
     *
     * Photos are not in that six. A photo can be added later from the edit page
     * and nothing depends on it, whereas a product saved with no cost price is
     * one that every margin report has to show a dash for until somebody goes
     * back for it — so the upload sits under Advanced and cost price is up
     * here, beside the selling price it is judged against.
     *
     * The groupings inside Advanced are the old ones unchanged, because a
     * vendor who goes looking for Inventory or Sales Channels should find them
     * where they have always been. The change is that they are no longer in the
     * way of someone who just wants to add a product.
     *
     * Nothing inside Advanced is required without a default, so the form can
     * always be saved without ever opening it.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // columnSpanFull() is load-bearing, not decoration. Filament wraps
                // a resource form in its own two-column grid, so without this the
                // whole layout below is nested inside ONE of those two columns and
                // renders at half the page width — measured at 479px of an
                // available 982px, giving three 143px columns that wrapped every
                // label and select inside them.
                Grid::make(1)
                    ->columnSpanFull()
                    ->schema([

                        // ── THE PRODUCT ──────────────────────────────────────
                        // Untitled on purpose. A heading here would be naming
                        // the form from inside the form; the page already says
                        // what this is.
                        Section::make()
                            ->schema([
                                // Leads the form: the one thing every other
                                // field here is describing.
                                TextInput::make('name')
                                    ->label('Product name')
                                    ->required()
                                    ->columnSpanFull()
                                    ->placeholder('What your staff would type at the till')
                                    // Tour hook ("Add a product").
                                    ->extraFieldWrapperAttributes(['data-tour' => 'product-name']),

                                // What it is, and which branch holds it.
                                Grid::make(['default' => 1, 'md' => 2])
                                    ->schema([
                                        // Native, and deliberately not searchable.
                                        //
                                        // Filament's own select is a JS widget that
                                        // builds its dropdown when you click it, and
                                        // a searchable one asks the server as you
                                        // type. That is the right trade for a long
                                        // list; for eleven categories it is a wait
                                        // in front of a list the browser could have
                                        // shown instantly from markup already on the
                                        // page. A native select also gets the OS
                                        // picker on a phone, which is where most of
                                        // this panel is used, and still supports
                                        // type-ahead to jump to an entry.
                                        //
                                        // Worth revisiting past roughly twenty
                                        // categories, where scanning starts to cost
                                        // more than searching.
                                        Select::make('category_id')
                                            ->label('Category')
                                            ->relationship('category', 'name')
                                            ->required()
                                            ->native(true),

                                        // A product lives in exactly one branch:
                                        // it never shows in another store's
                                        // inventory or till. Only the owner
                                        // decides where — the same gate that
                                        // governs opening and closing branches.
                                        // For anyone else the field is absent and
                                        // CreateProduct fills the store they are
                                        // working in.
                                        Select::make('store_id')
                                            ->label('Home store')
                                            ->options(fn () => Store::query()
                                                ->where('vendor_id', filament()->getTenant()->id)
                                                ->orderByDesc('is_default')
                                                ->orderBy('name')
                                                ->pluck('name', 'id'))
                                            ->default(fn () => ActiveStore::currentId())
                                            ->required()
                                            // Native for the same reason as the
                                            // category above: six branches is not a
                                            // list worth loading a widget for.
                                            ->native(true)
                                            ->helperText('Which branch stocks this. Moving it later moves its stock too.')
                                            ->visible(fn () => auth()->user()?->can('create', Store::class) ?? false),
                                    ]),

                                // The two halves of every margin on the platform,
                                // side by side, because reading one without the
                                // other tells a vendor nothing.
                                Grid::make(['default' => 1, 'md' => 2])
                                    ->schema([
                                        TextInput::make('cost_price')
                                            ->label('Cost price')
                                            ->numeric()
                                            ->prefix('₦')
                                            ->placeholder('Leave blank if unknown')
                                            ->helperText('What you pay. Optional — shown as "—" in reports until set.')
                                            ->live()
                                            ->hidden(fn () => ! self::canSeeCostPrice()),

                                        TextInput::make('price')
                                            ->label('Selling price')
                                            ->numeric()
                                            ->required()
                                            ->prefix('₦')
                                            // Not ->gt('cost_price'): that compares against the
                                            // sibling field's raw state, and Laravel's gt rule
                                            // fails closed when it resolves to null — which it
                                            // always does for staff without view_cost_price (the
                                            // field is hidden, never submitted), permanently
                                            // blocking them from saving any product. Comparing
                                            // against the literal value only when one exists
                                            // fixes that and skips the check when cost price is
                                            // legitimately left blank.
                                            ->rule(
                                                fn (Get $get): string => 'gt:' . $get('cost_price'),
                                                fn (Get $get): bool => filled($get('cost_price')),
                                            )
                                            ->live()
                                            ->disabled(fn ($record, Get $get) => $record?->isLinked() && ! $get('has_custom_price'))
                                            ->helperText(fn ($record, Get $get) => ($record?->isLinked() && ! $get('has_custom_price')) ? 'Calculated automatically. Turn on "Override Linked Price" under Advanced settings to change it manually.' : 'What the customer pays.')
                                            // Tour hook ("Add a product"). Sits on the
                                            // selling price rather than the cost price
                                            // because cost is hidden from staff without
                                            // view_cost_price, and a tour cannot point
                                            // at something that is not on screen.
                                            ->extraFieldWrapperAttributes(['data-tour' => 'product-cost-price']),
                                    ]),

                                Placeholder::make('margin_preview')
                                    ->label('')
                                    ->hidden(fn () => ! self::canSeeCostPrice())
                                    ->content(function ($get): HtmlString {
                                        $price = (float) ($get('price') ?? 0);

                                        if ($price <= 0) {
                                            return new HtmlString(
                                                '<p class="text-xs text-gray-400 dark:text-gray-500">Enter a selling price to see profit.</p>'
                                            );
                                        }

                                        $cost = $get('cost_price');
                                        $cost = ($cost !== null && $cost !== '') ? (float) $cost : null;

                                        if ($cost === null) {
                                            return new HtmlString(
                                                '<p class="text-xs text-gray-400 dark:text-gray-500">Add a cost price to see profit and margin.</p>'
                                            );
                                        }

                                        $profit = $price - $cost;
                                        $margin = ($profit / $price) * 100;
                                        $markup = $cost > 0 ? ($profit / $cost) * 100 : 0;
                                        $profitColor = $profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';

                                        return new HtmlString(
                                            '<div class="rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-gray-800 p-3 space-y-1.5">'
                                                .'<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Profit</span><span class="font-bold '.$profitColor.'">₦'.number_format($profit, 2).'</span></div>'
                                                .'<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Margin / Markup</span><span class="font-semibold text-gray-900 dark:text-white">'.number_format($margin, 1).'% / '.number_format($markup, 1).'%</span></div>'
                                            .'</div>'
                                        );
                                    }),

                                // Two states only. Archiving is a deliberate,
                                // confirmed header action on the edit page
                                // (see EditProduct::getHeaderActions) rather
                                // than a third button here — it is not the
                                // same kind of decision as draft-vs-live, and
                                // it is meaningless on a product being created.
                                ToggleButtons::make('status')
                                    ->label('Availability')
                                    ->options([
                                        'draft' => 'Draft',
                                        'published' => 'Published',
                                    ])
                                    ->colors([
                                        'draft' => 'gray',
                                        'published' => 'success',
                                    ])
                                    ->icons([
                                        'draft' => 'heroicon-o-pencil-square',
                                        'published' => 'heroicon-o-check-circle',
                                    ])
                                    ->grouped()
                                    ->default('draft')
                                    ->required()
                                    ->live()
                                    // An archived product is neither draft nor
                                    // published, so it gets no toggle at all —
                                    // the note below states the real state
                                    // instead. Hidden rather than disabled:
                                    // disabled() only stops the field being
                                    // saved, it still validates, and 'archived'
                                    // fails this field's own in:draft,published
                                    // rule — which blocked saving any edit to an
                                    // archived product (covered by
                                    // ProductArchiveTest).
                                    ->hidden(fn ($record) => $record?->status === 'archived')
                                    // Tour hook ("Add a product").
                                    ->extraFieldWrapperAttributes(['data-tour' => 'product-status']),

                                Placeholder::make('archived_note')
                                    ->label('')
                                    ->visible(fn ($record) => $record?->status === 'archived')
                                    ->content(new HtmlString(
                                        '<p class="text-xs text-gray-500 dark:text-gray-400">'
                                        .'This product is archived — hidden from the storefront and the till. '
                                        .'Use <span class="font-semibold">Restore</span> at the top of the page to bring it back.'
                                        .'</p>'
                                    )),
                            ]),

                        // ── EVERYTHING ELSE ──────────────────────────────────
                        Section::make('Advanced settings')
                            ->description('Photos, codes, supplier, stock rules, channels and scheduling. None of it is needed to save.')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Grid::make(['default' => 1, 'lg' => 3])
                                    ->schema([
                                        Group::make([
                                            Section::make('Photos')
                                                ->schema([
                                                    SpatieMediaLibraryFileUpload::make('images')
                                                        ->label('')
                                                        ->collection('product-images')
                                                        ->multiple()
                                                        ->reorderable()
                                                        ->image()
                                                        ->imageEditor()
                                                        ->maxFiles(8)
                                                        ->panelLayout('grid')
                                                        ->columnSpanFull()
                                                        // Only the one photo targeted by "Enhance with AI" (tracked by
                                                        // ai_enhanced_file_key) gets the suggested filename/alt/title —
                                                        // every other file falls back to the component's normal defaults.
                                                        ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, Get $get): string {
                                                            if ($file->getFilename() === $get('ai_enhanced_file_key') && filled($get('ai_suggested_filename'))) {
                                                                return Str::slug($get('ai_suggested_filename')).'.'.$file->getClientOriginalExtension();
                                                            }

                                                            return Str::ulid().'.'.$file->getClientOriginalExtension();
                                                        })
                                                        ->customProperties(function (TemporaryUploadedFile $file, Get $get): array {
                                                            if ($file->getFilename() !== $get('ai_enhanced_file_key')) {
                                                                return [];
                                                            }

                                                            return array_filter([
                                                                'alt' => $get('ai_suggested_alt_text'),
                                                                'title' => $get('ai_suggested_title'),
                                                            ]);
                                                        }),

                                                    Hidden::make('ai_enhanced_file_key'),
                                                    Hidden::make('ai_suggested_filename'),
                                                    Hidden::make('ai_suggested_alt_text'),
                                                    Hidden::make('ai_suggested_title'),

                                                    Actions::make([
                                                        Action::make('enhanceWithAi')
                                                            ->label('Enhance with AI')
                                                            ->icon('heroicon-o-sparkles')
                                                            ->color('primary')
                                                            ->action(function (Get $get, Set $set) {
                                                                $target = collect($get('images') ?? [])
                                                                    ->first(fn ($file) => $file instanceof TemporaryUploadedFile);

                                                                if (! $target) {
                                                                    Notification::make()
                                                                        ->title('Upload a photo first, then click Enhance with AI.')
                                                                        ->warning()
                                                                        ->send();

                                                                    return;
                                                                }

                                                                $result = app(ProductImagePipeline::class)
                                                                    ->process($target->get(), $target->getMimeType());

                                                                if ($result->hasOptimizedImage()) {
                                                                    // Local disk only — Livewire's temp-upload storage on this
                                                                    // app is never S3 (see config/filesystems.php), so writing
                                                                    // straight to the real path is safe and avoids re-deriving
                                                                    // the Storage disk/relative-path internals.
                                                                    file_put_contents($target->getRealPath(), $result->optimizedImages['fallback']);
                                                                    $set('ai_enhanced_file_key', $target->getFilename());
                                                                }

                                                                if ($result->hasAnalysis()) {
                                                                    $analysis = $result->analysis;

                                                                    $set('ai_suggested_filename', $analysis->filename);
                                                                    $set('ai_suggested_alt_text', $analysis->altText);
                                                                    $set('ai_suggested_title', $analysis->title);

                                                                    if (blank($get('description'))) {
                                                                        $set('description', $analysis->description);
                                                                    }

                                                                    $vendorId = filament()->getTenant()->id;
                                                                    $suggestedTagIds = collect($analysis->keywords)
                                                                        ->filter()
                                                                        ->map(fn (string $keyword) => Tag::firstOrCreate(
                                                                            ['vendor_id' => $vendorId, 'name' => $keyword],
                                                                        )->getKey());

                                                                    $set(
                                                                        'tags',
                                                                        collect($get('tags') ?? [])->merge($suggestedTagIds)->unique()->values()->all(),
                                                                    );
                                                                }

                                                                if ($result->backgroundRemovalFailed || $result->analysisFailed) {
                                                                    Notification::make()
                                                                        ->title('Enhanced with a few hiccups')
                                                                        ->body(match (true) {
                                                                            $result->backgroundRemovalFailed && $result->analysisFailed => 'Background removal and AI suggestions both failed — the original photo was kept.',
                                                                            $result->backgroundRemovalFailed => 'Background removal failed — the original photo was kept, but AI suggestions were still generated.',
                                                                            default => 'AI metadata suggestions failed, but the photo was still cleaned up.',
                                                                        })
                                                                        ->warning()
                                                                        ->send();
                                                                } else {
                                                                    Notification::make()
                                                                        ->title('Photo enhanced — review the suggested description, tags, and image below before saving.')
                                                                        ->success()
                                                                        ->send();
                                                                }
                                                            }),
                                                    ]),
                                                ]),

                                            // Grouping is carried by the row pairings rather than
                                            // bordered fieldsets: each row holds two fields that
                                            // answer the same question (what we call it / where it
                                            // comes from), which reads the same but costs none of
                                            // the ~40px of border-and-label chrome per group.
                                            Section::make('Details')
                                                ->schema([
                                                    Grid::make(2)
                                                        ->schema([
                                                            TextInput::make('brand')
                                                                ->placeholder('e.g., Apple, Samsung'),

                                                            // What we call it — both are lookup
                                                            // codes for the same item.
                                                            TextInput::make('sku')
                                                                ->label('SKU')
                                                                ->placeholder('e.g., APL-IP15-128-BLK')
                                                                ->maxLength(100),

                                                            // Camera scan reuses the barcode-scanner component already
                                                            // mounted globally on this panel (see VendorPanelProvider) —
                                                            // same one used by Inventory Count's scan button.
                                                            TextInput::make('barcode')
                                                                ->label('Barcode')
                                                                ->placeholder('e.g., 0123456789012')
                                                                ->maxLength(100)
                                                                ->extraFieldWrapperAttributes([
                                                                    'x-on:barcode-scanned.window' => "\$wire.set('data.barcode', \$event.detail.barcode)",
                                                                ])
                                                                ->suffixAction(
                                                                    Action::make('scanBarcode')
                                                                        ->icon('heroicon-o-qr-code')
                                                                        ->tooltip('Scan barcode with camera')
                                                                        ->alpineClickHandler(
                                                                            "window.dispatchEvent(new CustomEvent('open-barcode-scanner'))"
                                                                        ),
                                                                ),

                                                            // Where it comes from.
                                                            //
                                                            // Vendor-scoped, and creatable inline
                                                            // so a supplier arriving via import
                                                            // does not have to be pre-registered.
                                                            Select::make('supplier_id')
                                                                ->label('Supplier')
                                                                ->relationship(
                                                                    'supplier',
                                                                    'name',
                                                                    fn ($query) => $query->where('vendor_id', filament()->getTenant()->id),
                                                                )
                                                                ->searchable()
                                                                ->preload()
                                                                ->createOptionForm([
                                                                    TextInput::make('name')->required()->maxLength(255),
                                                                    TextInput::make('phone')->tel()->maxLength(30),
                                                                ])
                                                                ->createOptionUsing(fn (array $data) => \App\Models\Supplier::create([
                                                                    ...$data,
                                                                    'vendor_id' => filament()->getTenant()->id,
                                                                ])->getKey()),

                                                            Textarea::make('description')
                                                                ->rows(3)
                                                                ->columnSpanFull(),
                                                        ]),
                                                ]),

                                            Section::make('Specifications')
                                                ->schema([
                                                    KeyValue::make('specifications')
                                                        ->keyLabel('Spec Name (e.g., RAM)')
                                                        ->valueLabel('Value (e.g., 8GB)')
                                                        ->columnSpanFull(),
                                                ])
                                                ->collapsible(),
                                        ])->columnSpan(['lg' => 2]),

                                        // Single column throughout this sidebar. It is one
                                        // third of the page, so two columns here leave about
                                        // 170px per field — enough for the input, not enough
                                        // for the label and helper text beside it, which then
                                        // wrap to four or five lines each and make the section
                                        // TALLER than the single-column version it replaced.
                                        Group::make([
                                            // Cost and selling price are up in the
                                            // essentials now; what is left here is the
                                            // rules that govern them.
                                            Section::make('Pricing rules')
                                                ->schema([
                                                    Toggle::make('has_custom_price')
                                                        ->label('Override Linked Price')
                                                        ->helperText('Turn on to set a custom selling price, ignoring the general percentage markup from the supplier.')
                                                        ->default(false)
                                                        ->live()
                                                        ->visible(fn ($record) => $record?->isLinked()),

                                                    Toggle::make('allow_pos_price_override')
                                                        ->label('Allow price negotiation at the till')
                                                        ->helperText(fn ($get) => blank($get('cost_price'))
                                                            ? 'Needs a cost price before this can take effect — without one there is no way to tell what selling at a loss would mean.'
                                                            : 'Cashiers can haggle on this product, but never below your minimum margin. Turn off to fix the price.')
                                                        ->default(true)
                                                        ->disabled(fn ($get) => blank($get('cost_price'))),
                                                ]),

                                            Section::make('Inventory')
                                                ->schema([
                                                    // Leads the section: every quantity below it
                                                    // is a count of this unit — reading it first
                                                    // is what makes "5" mean "5 cartons."
                                                    TextInput::make('measurement_unit')
                                                        ->label('Unit')
                                                        ->placeholder('e.g., pcs, pair, carton')
                                                        ->maxLength(32)
                                                        ->helperText('How this is counted and sold.'),

                                                    TextInput::make('low_stock_threshold')
                                                        ->label('Low Stock Alert Threshold')
                                                        ->helperText('Flagged as "low stock" once available units fall below this.')
                                                        ->numeric()
                                                        ->default(5)
                                                        ->minValue(0)
                                                        ->required(),

                                                    // Filled by the product importer from a
                                                    // vendor's existing POS export. Editable
                                                    // here too, or an imported value would be
                                                    // visible nowhere and impossible to correct.
                                                    TextInput::make('reorder_point')
                                                        ->label('Reorder Point')
                                                        ->helperText('The level at which you intend to reorder. Distinct from the alert above, which only warns.')
                                                        ->numeric()
                                                        ->minValue(0),

                                                    TextInput::make('preferred_quantity')
                                                        ->label('Preferred Order Quantity')
                                                        ->helperText('How much you usually buy when restocking this.')
                                                        ->numeric()
                                                        ->minValue(0),

                                                    Toggle::make('is_service')
                                                        ->label('This is a service, not a physical item')
                                                        ->helperText('Services hold no stock and are left out of inventory counts.')
                                                        ->default(false),
                                                ]),

                                            Section::make('Sales Channels')
                                                ->schema([
                                                    Toggle::make('show_online')
                                                        ->label('Online Store')
                                                        ->helperText('Show on the public storefront')
                                                        ->default(true),

                                                    Toggle::make('show_in_pos')
                                                        ->label('Offline Store (POS)')
                                                        ->helperText('Available for in-person POS sales')
                                                        ->default(true),
                                                ]),

                                            // Only ever reachable once Published is
                                            // chosen above; a draft has no dates to set.
                                            Section::make('Scheduling')
                                                ->visible(fn ($get) => $get('status') === 'published')
                                                ->schema([
                                                    DateTimePicker::make('published_at')
                                                        ->label('Publish Date')
                                                        ->placeholder('Publish immediately'),

                                                    DateTimePicker::make('unpublish_at')
                                                        ->label('Unpublish Date')
                                                        ->placeholder('Never (stays live)')
                                                        ->after('published_at'),
                                                ]),

                                            Section::make('Tags')
                                                ->schema([
                                                    Select::make('tags')
                                                        ->relationship('tags', 'name')
                                                        ->multiple()
                                                        ->searchable()
                                                        ->preload()
                                                        ->label('')
                                                        ->placeholder('Add tags…')
                                                        ->createOptionForm([
                                                            TextInput::make('name')->required(),
                                                        ])
                                                        ->createOptionUsing(function (array $data): int {
                                                            return Tag::create([
                                                                'vendor_id' => filament()->getTenant()->id,
                                                                'name' => $data['name'],
                                                            ])->getKey();
                                                        }),
                                                ]),
                                        ])->columnSpan(['lg' => 1]),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
