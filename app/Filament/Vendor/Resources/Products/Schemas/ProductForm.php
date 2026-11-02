<?php

namespace App\Filament\Vendor\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Store;
use App\Models\Tag;
use App\Services\ActiveStore;
use App\Services\ImageProcessing\ProductImagePipeline;
use App\Services\Products\SkuGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
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
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductForm
{
    // Transient, UI-only state used by the "Enhance with AI" flow — never
    // persisted to the Product model. Stripped before save via
    // stripTransientFields() (see CreateProduct/EditProduct pages).
    public const AI_TRANSIENT_FIELDS = [
        'ai_enhanced_file_key',
        'ai_suggested_filename',
        'ai_suggested_alt_text',
        'ai_suggested_title',
    ];

    // Form-only state with no column behind it: whether the vendor unlocked the
    // SKU field to type their own, and which unit the till should open.
    public const TRANSIENT_FIELDS = [
        ...self::AI_TRANSIENT_FIELDS,
        'sku_manual',
        'default_unit_choice',
    ];

    /** The units most shops sell by. Anything else is added as a custom entry. */
    public const COMMON_UNITS = ['Bottle', 'Can', 'Piece', 'Pack', 'Sachet', 'Bag', 'Carton'];

    public static function stripTransientAiFields(array $data): array
    {
        return array_diff_key($data, array_flip(self::AI_TRANSIENT_FIELDS));
    }

    public static function stripTransientFields(array $data): array
    {
        return array_diff_key($data, array_flip(self::TRANSIENT_FIELDS));
    }

    /**
     * A barcode is free if no OTHER product in this shop answers to it — as its
     * main barcode or any extra one. This product's own codes are fine to keep.
     */
    public static function barcodeFreeRule(?int $productId): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($productId) {
            $code = trim((string) $value);

            if ($code === '') {
                return;
            }

            $vendorId = filament()->getTenant()?->id;

            $taken = ProductBarcode::where('vendor_id', $vendorId)
                ->where('barcode', $code)
                ->when($productId, fn ($q) => $q->where('product_id', '!=', $productId))
                ->exists()
                || Product::where('vendor_id', $vendorId)
                    ->where('barcode', $code)
                    ->when($productId, fn ($q) => $q->whereKeyNot($productId))
                    ->exists();

            if ($taken) {
                $fail("Barcode {$code} is already used by another product in this shop.");
            }
        };
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

    /** Whether this shop sells things with specifications and serial numbers. */
    public static function usesGadgetFields(): bool
    {
        return (bool) filament()->getTenant()?->usesGadgetFields();
    }

    /**
     * Whether this shop sells halves (½ crate). Every other shop never sees a
     * half price, and whatever is saved in one is left alone.
     */
    public static function usesHalfQuantities(): bool
    {
        return (bool) filament()->getTenant()?->allow_half_quantities;
    }

    /**
     * The optional price for half of a unit. Blank means exactly half of
     * $priceField, which the placeholder shows as it is typed.
     */
    private static function halfPriceInput(string $priceField): TextInput
    {
        return TextInput::make('half_price')
            ->numeric()
            ->minValue(0)
            ->prefix('₦')
            ->placeholder(function (Get $get) use ($priceField): string {
                $price = (float) $get($priceField);

                return $price > 0 ? 'Auto: ₦'.number_format(round($price / 2, 2), 2) : 'Auto: half';
            })
            ->hint('Price for half of this unit. Leave blank to charge exactly half.');
    }

    /** "bottle", "crate" — the base unit as it reads inside a sentence. */
    private static function unitWord(?string $baseUnit): string
    {
        return mb_strtolower(trim((string) $baseUnit) ?: 'piece');
    }

    /**
     * What a new bigger unit might sell for: the singles' price times how many
     * it holds, less 8%, to the nearest ₦50. Only ever a starting point.
     */
    public static function suggestedUnitPrice(float $basePrice, int $factor): float
    {
        return round($basePrice * $factor * 0.92 / 50) * 50;
    }

    /** Whether this shop types its own SKUs (Store Profile → Product codes). */
    public static function skuIsManual(): bool
    {
        return app(SkuGenerator::class)->isManual(filament()->getTenant()?->id);
    }

    /** The SKU a product with the form's current name and category would get. */
    private static function previewSku(Get $get, ?int $recordId = null): ?string
    {
        if (blank($get('name'))) {
            return null;
        }

        return app(SkuGenerator::class)->for(
            filament()->getTenant()->id,
            filled($get('category_id')) ? (int) $get('category_id') : null,
            (string) $get('name'),
            $recordId,
        );
    }

    /** "3 units · POS sells as Crate · 2 barcodes" — what is inside Advanced, at a glance. */
    private static function advancedSummary(Get $get): string
    {
        $extra = collect($get('extraUnits') ?? [])->filter(fn ($u) => filled($u['name'] ?? null));
        $units = 1 + $extra->count();

        $choice  = $get('default_unit_choice');
        $default = (is_string($choice) && str_starts_with($choice, 'unit:'))
            ? substr($choice, 5)
            : ($get('base_unit') ?: 'Piece');

        $barcodes = (filled($get('barcode')) ? 1 : 0)
            + collect($get('extraBaseBarcodes') ?? [])->filter(fn ($b) => filled($b['barcode'] ?? $b ?? null))->count()
            + $extra->sum(fn ($u) => collect($u['barcodes'] ?? [])->filter(fn ($b) => filled(is_array($b) ? ($b['barcode'] ?? null) : $b))->count());

        return $units.' '.Str::plural('unit', $units)
            .' · POS sells as '.$default
            .' · '.$barcodes.' '.Str::plural('barcode', $barcodes);
    }

    /** One chip per unit: what it earns and at what margin. Red when it loses money. */
    private static function marginChips(Get $get): HtmlString
    {
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

        $units = [[
            'name'   => $get('base_unit') ?: 'Piece',
            'price'  => $price,
            'factor' => 1,
        ]];

        foreach ($get('extraUnits') ?? [] as $unit) {
            if (blank($unit['name'] ?? null) || blank($unit['price'] ?? null)) {
                continue;
            }

            $units[] = [
                'name'   => $unit['name'],
                'price'  => (float) $unit['price'],
                'factor' => max(1, (int) ($unit['conversion_factor'] ?? 1)),
            ];
        }

        $chips = '';

        foreach ($units as $unit) {
            $profit = $unit['price'] - ($cost * $unit['factor']);
            $margin = $unit['price'] > 0 ? ($profit / $unit['price']) * 100 : 0;
            $colour = $profit >= 0
                ? 'border-gray-200 bg-gray-50 text-gray-900 dark:border-white/10 dark:bg-gray-800 dark:text-white'
                : 'border-red-300 bg-red-50 text-red-700 dark:border-red-500/40 dark:bg-red-950 dark:text-red-300';

            $chips .= '<div class="rounded-lg border px-3 py-1.5 text-sm '.$colour.'">'
                .'<span class="font-medium">'.e($unit['name']).'</span> '
                .'<span class="font-bold">'.($profit < 0 ? '−' : '').'₦'.number_format(abs($profit), 2).'</span> '
                .'<span class="text-xs opacity-75">'.number_format($margin, 1).'%</span>'
                .'</div>';
        }

        return new HtmlString('<div class="flex flex-wrap gap-2">'.$chips.'</div>');
    }

    /**
     * The product form.
     *
     * Six things sit up front — what it is called and coded as, what shelf it
     * is on, which branch stocks it, what it cost, what it sells for, and
     * whether it is live. Everything else lives under one collapsed "Advanced
     * settings" section, split into tabs so a vendor looking for barcodes or
     * stock rules goes to one place instead of scrolling past the rest.
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
                // renders at half the page width.
                Grid::make(1)
                    ->columnSpanFull()
                    ->schema([
                        self::essentials(),
                        self::advanced(),
                    ]),
            ]);
    }

    // ── THE PRODUCT ──────────────────────────────────────────────────────
    // Untitled on purpose: the page already says what this is.
    private static function essentials(): Section
    {
        return Section::make()
            ->schema([
                // Whether the vendor unlocked the SKU to type their own. Form-only
                // state: it decides what CreateProduct keeps and is never saved.
                Hidden::make('sku_manual')->default(false),

                // Row 1: the name, and the code that goes with it.
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        TextInput::make('name')
                            ->label('Product name')
                            ->required()
                            ->columnSpan(['md' => 2])
                            ->placeholder('What your staff would type at the till')
                            // Only on create does a new name re-preview the SKU;
                            // on edit the code is already the product's.
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, $record) {
                                if ($record === null && ! $get('sku_manual') && ! self::skuIsManual()) {
                                    $set('sku', self::previewSku($get));
                                }
                            })
                            // Tour hook ("Add a product").
                            ->extraFieldWrapperAttributes(['data-tour' => 'product-name']),

                        self::skuField(),
                    ]),

                // Read-only for vendors: GadgetPlug sets the online name and meta
                // description. Never a form field, so a vendor save can't write them.
                Grid::make(['default' => 1, 'md' => 2])
                    ->visible(fn ($record) => $record !== null)
                    ->schema([
                        Placeholder::make('online_name_display')
                            ->label('Online name')
                            ->helperText('Set by GadgetPlug for online search')
                            ->content(fn ($record) => $record && $record->displayName() !== $record->name
                                ? $record->displayName()
                                : '— (customers see the product name)'),

                        Placeholder::make('meta_description_display')
                            ->label('Meta description')
                            ->helperText('Set by GadgetPlug for online search')
                            ->content(fn ($record) => $record?->catalogueSource()?->meta_description ?: '—'),
                    ]),

                // Row 2: what it is, and which branch holds it.
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        // Native, and deliberately not searchable: for a dozen
                        // categories the browser's own list is instant and gets
                        // the OS picker on a phone. Worth revisiting past about
                        // twenty categories.
                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->required()
                            ->native(true)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, $record) {
                                if ($record === null && ! $get('sku_manual') && ! self::skuIsManual()) {
                                    $set('sku', self::previewSku($get));
                                }
                            }),

                        // A product lives in exactly one branch: it never shows
                        // in another store's inventory or till. Only the owner
                        // decides where; for anyone else the field is absent and
                        // CreateProduct fills the store they are working in.
                        Select::make('store_id')
                            ->label('Home store')
                            ->options(fn () => Store::query()
                                ->where('vendor_id', filament()->getTenant()->id)
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->default(fn () => ActiveStore::currentId())
                            ->required()
                            ->native(true)
                            ->helperText('Which branch stocks this. Moving it later moves its stock too.')
                            ->visible(fn () => auth()->user()?->can('create', Store::class) ?? false),
                    ]),

                // Row 3: the two halves of every margin, side by side. Both are
                // per base unit, and say so.
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('cost_price')
                            ->label(fn (Get $get) => 'Cost price / '.self::unitWord($get('base_unit')))
                            ->numeric()
                            ->prefix('₦')
                            ->placeholder('Leave blank if unknown')
                            ->helperText('What you pay. Optional — shown as "—" in reports until set.')
                            ->live()
                            ->hidden(fn () => ! self::canSeeCostPrice()),

                        TextInput::make('price')
                            ->label(fn (Get $get) => 'Selling price / '.self::unitWord($get('base_unit')))
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
                            ->suffixAction(self::addUnitAction())
                            // Tour hook ("Add a product"). Sits on the selling
                            // price rather than the cost price because cost is
                            // hidden from staff without view_cost_price.
                            ->extraFieldWrapperAttributes(['data-tour' => 'product-cost-price']),
                    ]),

                // Row 4: profit per unit.
                Placeholder::make('margin_preview')
                    ->label('')
                    ->hidden(fn () => ! self::canSeeCostPrice())
                    ->content(fn (Get $get): HtmlString => self::marginChips($get)),

                // Two states only, and only while creating. On an existing
                // product Publish / Move to draft are header actions next to
                // Archive (see EditProduct::getHeaderActions); archiving is not
                // the same kind of decision and is meaningless on a new product.
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
                    ->visible(fn ($record) => $record === null)
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
            ]);
    }

    /**
     * The SKU: generated, shown read-only, and unlockable.
     *
     * On create it previews what the product will be called as the name and
     * category change; the real number is only chosen when it is saved (see
     * CreateProduct), so a preview can never clash with a simultaneous create.
     */
    private static function skuField(): TextInput
    {
        return TextInput::make('sku')
            ->label('SKU')
            ->maxLength(100)
            // A shop that types its own SKUs must give one; every other shop
            // always has one made for it.
            ->required(fn () => self::skuIsManual())
            ->placeholder(fn () => self::skuIsManual() ? 'Enter a SKU' : 'Generated when you save')
            ->hint(fn () => match (app(SkuGenerator::class)->styleFor(filament()->getTenant()?->id)) {
                SkuGenerator::STYLE_SERIAL        => 'Next number in your sequence',
                SkuGenerator::STYLE_PREFIX_SERIAL => 'Your prefix + next number',
                SkuGenerator::STYLE_MANUAL        => 'You type this one',
                default                           => 'Generated from category + name',
            })
            ->readOnly(fn (Get $get) => ! self::skuIsManual() && ! $get('sku_manual'))
            ->extraInputAttributes(['class' => 'font-mono'])
            // A friendly refusal instead of the database's. Skipped for a
            // generated preview on create (it is replaced on save anyway) and
            // enforced for everything the vendor controls: edits, and a SKU
            // they typed themselves.
            ->rule(
                fn ($record) => Rule::unique('products', 'sku')
                    ->where('vendor_id', filament()->getTenant()->id)
                    ->ignore($record?->getKey()),
                fn ($record, Get $get): bool => $record !== null || (bool) $get('sku_manual') || self::skuIsManual(),
            )
            ->validationMessages(['unique' => 'This SKU is already used by another product in this shop.'])
            // Nothing to regenerate or unlock when the shop types them all.
            ->suffixActions([
                Action::make('regenerateSku')
                    ->icon('heroicon-m-arrow-path')
                    ->tooltip('Regenerate')
                    ->visible(fn () => ! self::skuIsManual())
                    ->action(function (Get $get, Set $set, $record) {
                        $set('sku', self::previewSku($get, $record?->getKey()));
                    }),

                Action::make('editSkuManually')
                    ->icon('heroicon-m-pencil-square')
                    ->tooltip('Edit manually')
                    ->visible(fn () => ! self::skuIsManual())
                    ->action(fn (Get $get, Set $set) => $set('sku_manual', ! $get('sku_manual'))),
            ]);
    }

    /**
     * "+ Unit" beside the selling price.
     *
     * Filament has no supported way to open a collapsed section and switch its
     * tab from the server, so this asks for the three things a unit needs in a
     * small dialog and adds the row to the Selling units table itself.
     */
    private static function addUnitAction(): Action
    {
        return Action::make('addUnit')
            ->label('Add unit')
            // Affix actions render as icon buttons and hide their label, so
            // without an icon this was an empty box.
            ->icon('heroicon-m-plus')
            ->tooltip('Add a selling unit, e.g. a crate')
            ->modalHeading('Add a selling unit')
            ->modalDescription('Sell this product in a bigger size — a crate, a pack. Fine-tune it under Advanced settings → Selling units.')
            ->modalSubmitActionLabel('Add unit')
            ->modalWidth('md')
            ->schema([
                TextInput::make('name')->label('Unit name')->required()->maxLength(64),
                TextInput::make('conversion_factor')
                    ->label('Contains (how many singles)')
                    ->integer()->minValue(2)->required(),
                TextInput::make('price')->label('Retail price')->numeric()->minValue(0)->prefix('₦')->required(),
            ])
            ->fillForm(function (Get $get): array {
                $price = (float) $get('price');

                return [
                    'name'              => 'Crate',
                    'conversion_factor' => 12,
                    'price'             => $price > 0 ? self::suggestedUnitPrice($price, 12) : null,
                ];
            })
            ->action(function (array $data, Get $get, Set $set) {
                $units = $get('extraUnits') ?? [];

                $units['new-'.Str::lower(Str::random(8))] = [
                    'name'              => $data['name'],
                    'sku'               => null,
                    'conversion_factor' => (int) $data['conversion_factor'],
                    'price'             => $data['price'],
                    'wholesale_price'   => null,
                    'is_active'         => true,
                    'barcodes'          => [],
                ];

                $set('extraUnits', $units);

                Notification::make()
                    ->title("{$data['name']} added")
                    ->body('Find it under Advanced settings → Selling units, where you can add its wholesale price and barcodes.')
                    ->success()
                    ->send();
            });
    }

    // ── EVERYTHING ELSE ──────────────────────────────────────────────────
    private static function advanced(): Section
    {
        return Section::make('Advanced settings')
            ->description(fn (Get $get) => self::advancedSummary($get))
            ->icon('heroicon-o-adjustments-horizontal')
            ->collapsible()
            ->collapsed()
            ->schema([
                Tabs::make('advanced')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Selling units')->icon('heroicon-o-scale')->schema(self::sellingUnitsTab()),
                        Tab::make('Barcodes')->icon('heroicon-o-qr-code')->schema(self::barcodesTab()),
                        Tab::make('Photos')->icon('heroicon-o-photo')->schema(self::photosTab()),
                        Tab::make('Details')->icon('heroicon-o-document-text')->schema(self::detailsTab()),
                        Tab::make('Stock rules')->icon('heroicon-o-archive-box')->schema(self::stockRulesTab()),
                        Tab::make('Pricing rules')->icon('heroicon-o-banknotes')->schema(self::pricingRulesTab()),
                        Tab::make('Channels & schedule')->icon('heroicon-o-globe-alt')->schema(self::channelsTab()),
                    ]),
            ]);
    }

    // Bottle / half crate / crate. Stock is always counted in the base unit;
    // each extra unit says how many base units it holds and has its own price,
    // set by hand — a crate is rarely 24 bottle prices.
    private static function sellingUnitsTab(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                // A short list of the usual ones, searchable, with a way to
                // type something else. The stored value is just the text.
                Select::make('base_unit')
                    ->label('Base unit')
                    ->options(function (Get $get): array {
                        $options = array_combine(self::COMMON_UNITS, self::COMMON_UNITS);
                        $current = $get('base_unit');

                        if (filled($current)) {
                            $options[$current] = $current;
                        }

                        return $options;
                    })
                    ->default('Piece')
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->rule('max:32')
                    ->createOptionForm([
                        TextInput::make('name')->label('Unit name')->required()->maxLength(32),
                    ])
                    ->createOptionUsing(fn (array $data): string => trim($data['name']))
                    ->helperText('The smallest unit you sell. Stock is counted in this.'),

                TextInput::make('wholesale_price')
                    ->label(fn (Get $get) => 'Wholesale price / '.self::unitWord($get('base_unit')))
                    ->numeric()
                    ->minValue(0)
                    ->prefix('₦')
                    ->placeholder('Leave blank for retail only')
                    ->helperText('The retail price is the Selling price above.'),

                ...(self::usesHalfQuantities() ? [
                    self::halfPriceInput('price')
                        ->label(fn (Get $get) => 'Half price / ½ '.self::unitWord($get('base_unit'))),
                ] : []),
            ]),

            // One choice for the whole product, so there can never be two.
            Radio::make('default_unit_choice')
                ->label('Default unit at POS')
                ->helperText('The unit this product goes into the cart as when a cashier rings it up without choosing one.')
                ->options(function (Get $get): array {
                    $options = ['base' => ($get('base_unit') ?: 'Piece').' (single)'];

                    foreach ($get('extraUnits') ?? [] as $unit) {
                        if (filled($unit['name'] ?? null)) {
                            $options['unit:'.$unit['name']] = $unit['name'];
                        }
                    }

                    return $options;
                })
                ->default('base')
                ->inline()
                ->live(),

            Repeater::make('extraUnits')
                ->label('Other units')
                ->relationship('extraUnits')
                ->orderColumn('sort_order')
                ->defaultItems(0)
                ->addActionLabel('Add unit')
                ->columnSpanFull()
                ->table([
                    TableColumn::make('Unit name')->markAsRequired(),
                    TableColumn::make('Contains')->markAsRequired()->width('140px'),
                    TableColumn::make('SKU')->width('170px'),
                    TableColumn::make('Retail')->markAsRequired(),
                    TableColumn::make('Wholesale'),
                    ...(self::usesHalfQuantities() ? [TableColumn::make('Half')] : []),
                    TableColumn::make('Per single'),
                    TableColumn::make('On sale')->width('80px'),
                    TableColumn::make('Barcodes')->width('200px'),
                ])
                ->schema([
                    TextInput::make('name')
                        ->placeholder('e.g., Crate, Half Crate')
                        ->required()
                        ->maxLength(64)
                        ->distinct(),

                    TextInput::make('conversion_factor')
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->default(1)
                        ->live(onBlur: true)
                        ->suffix(fn (Get $get) => self::unitWord($get('../../base_unit')).'s'),

                    // Made from the product's own SKU the first time the unit is
                    // saved; shown here, never typed.
                    TextInput::make('sku')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('Made when saved')
                        ->extraInputAttributes(['class' => 'font-mono text-xs']),

                    TextInput::make('price')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₦')
                        ->required()
                        ->live(onBlur: true),

                    TextInput::make('wholesale_price')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₦')
                        ->placeholder('Retail only'),

                    ...(self::usesHalfQuantities() ? [self::halfPriceInput('price')->hiddenLabel()] : []),

                    Placeholder::make('per_single')
                        ->hiddenLabel()
                        ->content(function (Get $get): HtmlString {
                            $single = (float) $get('../../price');
                            $price  = (float) $get('price');
                            $factor = (int) $get('conversion_factor');

                            if ($single <= 0 || $price <= 0 || $factor < 1) {
                                return new HtmlString('<span class="text-gray-400">—</span>');
                            }

                            $each = $price / $factor;
                            $diff = round((($single - $each) / $single) * 100, 1);
                            $money = '₦'.number_format($each, 2);

                            if ($diff < 0) {
                                return new HtmlString('<span class="text-xs font-semibold text-red-600 dark:text-red-400">'.$money.' · '.abs($diff).'% dearer than single!</span>');
                            }

                            return new HtmlString('<span class="text-xs text-gray-700 dark:text-gray-300">'.$money.($diff > 0 ? ' · <span class="font-semibold text-green-600 dark:text-green-400">saves '.$diff.'%</span>' : '').'</span>');
                        }),

                    Toggle::make('is_active')
                        ->default(true),

                    // Collapsed in a table cell: it shows its rows only when asked.
                    Repeater::make('barcodes')
                        ->relationship('barcodes')
                        ->simple(
                            TextInput::make('barcode')
                                ->required()
                                ->maxLength(100)
                                ->distinct()
                                ->rule(fn ($livewire) => self::barcodeFreeRule(data_get($livewire, 'record.id'))),
                        )
                        ->collapsible()
                        ->collapsed()
                        ->defaultItems(0)
                        ->addActionLabel('Add barcode'),
                ]),
        ];
    }

    private static function barcodesTab(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                // Camera scan reuses the barcode-scanner component already
                // mounted globally on this panel (see VendorPanelProvider) —
                // same one used by Inventory Count's scan button.
                TextInput::make('barcode')
                    ->label('Barcode')
                    ->placeholder('e.g., 0123456789012')
                    ->maxLength(100)
                    // Also checked against other products' unit barcodes, which
                    // the old unique index never saw.
                    ->rule(fn ($livewire) => self::barcodeFreeRule(data_get($livewire, 'record.id')))
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

                // Other codes for the same single item — the same product from
                // two manufacturers, or an old label still on the shelf. Any of
                // them scans as this product. Codes for a bigger unit (a crate)
                // are set on that unit under Selling units.
                Repeater::make('extraBaseBarcodes')
                    ->label('Alternative barcodes')
                    ->relationship('extraBaseBarcodes')
                    ->simple(
                        TextInput::make('barcode')
                            ->required()
                            ->maxLength(100)
                            ->distinct()
                            ->rule(fn ($livewire) => self::barcodeFreeRule(data_get($livewire, 'record.id'))),
                    )
                    ->defaultItems(0)
                    ->addActionLabel('Add alternative barcode'),
            ]),
        ];
    }

    private static function photosTab(): array
    {
        $upload = [
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
        ];

        return [
            // A resold listing shows its supplier's photos, live; anything
            // uploaded here would never be seen.
            Placeholder::make('linked_photos_note')
                ->hiddenLabel()
                ->visible(fn ($record) => (bool) $record?->isLinked())
                ->content('Photos come from your supplier\'s product and update automatically when they change theirs.'),

            Group::make($upload)
                ->visible(fn ($record) => ! $record?->isLinked())
                ->columnSpanFull(),
        ];
    }

    private static function detailsTab(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('brand')
                    ->placeholder('e.g. Nigerian Breweries, Golden Penny'),

                // Where it comes from. Vendor-scoped, and creatable inline so a
                // supplier arriving via import does not have to be pre-registered.
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

                Select::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->placeholder('Add tags…')
                    ->columnSpanFull()
                    ->createOptionForm([
                        TextInput::make('name')->required(),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        return Tag::create([
                            'vendor_id' => filament()->getTenant()->id,
                            'name' => $data['name'],
                        ])->getKey();
                    }),

                // Phones and laptops, not crates of drinks. Hidden, never
                // cleared: a hidden field is left out of the save, so whatever
                // a product already holds stays as it was.
                KeyValue::make('specifications')
                    ->keyLabel('Spec Name (e.g., RAM)')
                    ->valueLabel('Value (e.g., 8GB)')
                    ->columnSpanFull()
                    ->visible(fn () => self::usesGadgetFields()),
            ]),
        ];
    }

    private static function stockRulesTab(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('low_stock_threshold')
                    ->label('Low stock alert')
                    ->helperText(fn (Get $get) => 'In '.self::unitWord($get('base_unit')).'s. Flagged as "low stock" once available units fall below this.')
                    ->numeric()
                    ->default(5)
                    ->minValue(0)
                    ->required(),

                // Filled by the product importer from a vendor's existing POS
                // export. Editable here too, or an imported value would be
                // visible nowhere and impossible to correct.
                TextInput::make('reorder_point')
                    ->label('Reorder point')
                    ->helperText('The level at which you intend to reorder. Distinct from the alert, which only warns.')
                    ->numeric()
                    ->minValue(0),

                TextInput::make('preferred_quantity')
                    ->label('Preferred order qty')
                    ->helperText('How much you usually buy when restocking this.')
                    ->numeric()
                    ->minValue(0),

                // Per-product override of the shop-wide setting.
                Select::make('negative_stock_override')
                    ->label('Selling below zero stock')
                    ->options([
                        '1' => 'Allow for this product',
                        '0' => 'Block for this product',
                    ])
                    ->placeholder('Follow shop setting')
                    ->formatStateUsing(fn ($state) => $state === null ? null : ($state ? '1' : '0'))
                    ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (bool) (int) $state)
                    ->helperText('Shop setting is under Store Profile → Sales Point Settings.'),

                Toggle::make('is_service')
                    ->label('This is a service, not a physical item')
                    ->helperText('Services hold no stock and are left out of inventory counts.')
                    ->default(false),
            ]),
        ];
    }

    private static function pricingRulesTab(): array
    {
        return [
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
        ];
    }

    private static function channelsTab(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Toggle::make('show_in_pos')
                    ->label('Offline store (Sales Point)')
                    ->helperText('Available for in-person sales at the Sales Point')
                    ->default(true),

                Toggle::make('show_online')
                    ->label('Online store')
                    ->helperText('Show on the public storefront. Also needs at least one photo.')
                    ->default(true),

                // Only meaningful once the product is published. On edit the
                // status is not a field, but it is still in the form's state.
                DateTimePicker::make('published_at')
                    ->label('Publish date')
                    ->placeholder('Publish immediately')
                    ->visible(fn ($get) => $get('status') === 'published'),

                DateTimePicker::make('unpublish_at')
                    ->label('Unpublish date')
                    ->placeholder('Never (stays live)')
                    ->after('published_at')
                    ->visible(fn ($get) => $get('status') === 'published'),
            ]),
        ];
    }
}
