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
use Filament\Schemas\Components\Fieldset;
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

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Group::make([
                            Section::make('Basic Information')
                                ->schema([
                                    // Leads the form: the one thing every other
                                    // field here is describing.
                                    TextInput::make('name')
                                        ->required()
                                        ->columnSpanFull(),

                                    // Each fieldset's label names exactly the
                                    // fields inside it — no field sits in a
                                    // group its own heading doesn't explain.
                                    Fieldset::make('Category & Brand')
                                        ->schema([
                                            Select::make('category_id')
                                                ->relationship('category', 'name')
                                                ->required()
                                                ->searchable()
                                                ->preload(),

                                            TextInput::make('brand')
                                                ->placeholder('e.g., Apple, Samsung'),
                                        ]),

                                    Fieldset::make('Identifiers')
                                        ->schema([
                                            TextInput::make('sku')
                                                ->label('SKU')
                                                ->placeholder('e.g., APL-IP15-128-BLK')
                                                ->maxLength(100),

                                            // Camera scan reuses the barcode-scanner component already
                                            // mounted globally on this panel (see VendorPanelProvider) —
                                            // same one used by Inventory Count's scan button. Full width
                                            // within the fieldset: the scan action needs the room, and it
                                            // reads better as its own row than squeezed beside SKU.
                                            TextInput::make('barcode')
                                                ->label('Barcode')
                                                ->placeholder('e.g., 0123456789012')
                                                ->maxLength(100)
                                                ->columnSpanFull()
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
                                        ]),

                                    Fieldset::make('Supplier & Store')
                                        ->schema([
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

                                            // A product lives in exactly one
                                            // branch: it never shows in another
                                            // store's inventory or till. Only
                                            // the owner decides where — the
                                            // same gate that governs opening
                                            // and closing branches. For anyone
                                            // else the field is absent and
                                            // CreateProduct fills the store
                                            // they are working in.
                                            Select::make('store_id')
                                                ->label('Home store')
                                                ->options(fn () => Store::query()
                                                    ->where('vendor_id', filament()->getTenant()->id)
                                                    ->orderByDesc('is_default')
                                                    ->orderBy('name')
                                                    ->pluck('name', 'id'))
                                                ->default(fn () => ActiveStore::currentId())
                                                ->required()
                                                ->native(false)
                                                ->helperText('Which branch stocks this product. Moving it later moves its stock too.')
                                                ->visible(fn () => auth()->user()?->can('create', Store::class) ?? false),
                                        ]),

                                    Textarea::make('description')
                                        ->rows(4)
                                        ->columnSpanFull(),
                                ]),

                            Section::make('Media')
                                ->schema([
                                    SpatieMediaLibraryFileUpload::make('images')
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

                            Section::make('Specifications')
                                ->schema([
                                    KeyValue::make('specifications')
                                        ->keyLabel('Spec Name (e.g., RAM)')
                                        ->valueLabel('Value (e.g., 8GB)')
                                        ->columnSpanFull(),
                                ])
                                ->collapsible(),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            // Leads the sidebar — draft vs. published is the
                            // first decision an owner makes about a product,
                            // and the one most likely to change after saving.
                            Section::make('Status')
                                ->schema([
                                    ToggleButtons::make('status')
                                        ->label('')
                                        ->options([
                                            'draft' => 'Draft',
                                            'published' => 'Published',
                                            'archived' => 'Archived',
                                        ])
                                        ->colors([
                                            'draft' => 'gray',
                                            'published' => 'success',
                                            'archived' => 'danger',
                                        ])
                                        ->icons([
                                            'draft' => 'heroicon-o-pencil-square',
                                            'published' => 'heroicon-o-check-circle',
                                            'archived' => 'heroicon-o-archive-box',
                                        ])
                                        ->grouped()
                                        ->default('draft')
                                        ->required()
                                        ->live(),

                                    DateTimePicker::make('published_at')
                                        ->label('Publish Date')
                                        ->placeholder('Publish immediately')
                                        ->hidden(fn ($get) => $get('status') !== 'published'),

                                    DateTimePicker::make('unpublish_at')
                                        ->label('Unpublish Date')
                                        ->placeholder('Never (stays live)')
                                        ->after('published_at')
                                        ->hidden(fn ($get) => $get('status') !== 'published'),
                                ]),

                            Section::make('Pricing')
                                ->schema([
                                    TextInput::make('cost_price')
                                        ->numeric()
                                        ->prefix('₦')
                                        ->placeholder('Leave blank if unknown')
                                        ->helperText('Optional — shown as "—" in reports until set.')
                                        ->live()
                                        ->hidden(fn () => ! self::canSeeCostPrice()),

                                    TextInput::make('price')
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
                                        ->live(),

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
                                    // Leads the section: every quantity below
                                    // (threshold, reorder point, order size) is
                                    // a count of this unit — reading it first
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
            ]);
    }
}
