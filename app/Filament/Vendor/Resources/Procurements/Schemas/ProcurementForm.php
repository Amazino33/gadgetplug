<?php

namespace App\Filament\Vendor\Resources\Procurements\Schemas;

use App\Models\Procurement;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\PricingService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

// The auto-pricing workflow's create/edit form (repeater-in-schema, mirroring
// the Repeater usage already established in CreateProcurement's wizard step 2
// — same "options() instead of relationship()" workaround on the product
// select to avoid the null-Builder issue that pattern was written to avoid).
// Shared between the new CreateProcurement and EditProcurement pages, the
// same way ProductForm::configure() is shared across Products' pages.
class ProcurementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Procurement')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->relationship(
                                'supplier',
                                'name',
                                fn ($query) => $query->where('vendor_id', filament()->getTenant()?->id),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')->required(),
                                Grid::make(2)->schema([
                                    TextInput::make('phone')->tel(),
                                    TextInput::make('email')->email(),
                                ]),
                            ])
                            ->createOptionUsing(fn (array $data): int => Supplier::create(
                                array_merge($data, ['vendor_id' => filament()->getTenant()?->id]),
                            )->id)
                            ->disabled(fn (?Procurement $record) => $record && ! $record->isDraft()),

                        Placeholder::make('status_display')
                            ->label('Status')
                            ->content(fn (?Procurement $record) => new HtmlString(self::statusBadge($record?->status ?? 'draft'))),
                    ]),

                    FileUpload::make('waybill_image')
                        ->label('Waybill / Receipt Photo')
                        ->image()
                        ->disk('public')
                        ->directory('waybills')
                        ->maxSize(5120)
                        ->imageEditor()
                        ->helperText('Take a photo of the physical receipt or delivery note.')
                        ->columnSpanFull(),

                    Grid::make(2)->schema([
                        Radio::make('payment_method')
                            ->label('Payment Method')
                            ->options([
                                'bank_transfer' => 'Bank Transfer',
                                'cash' => 'Cash',
                                'credit' => 'Credit',
                            ])
                            ->default('bank_transfer')
                            ->inline()
                            ->required(),

                        TextInput::make('amount_paid')
                            ->label('Amount Paid (₦)')
                            ->numeric()
                            ->prefix('₦')
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->helperText('Leave at 0 if this is a fully-credited purchase.'),
                    ]),

                    TextInput::make('logistics_cost')
                        ->label('Trip Logistics Cost')
                        ->helperText('Total inbound freight cost for this whole trip — leave blank until known. Recording this and reconciling recomputes every line\'s landed cost.')
                        ->numeric()
                        ->prefix('₦')
                        ->minValue(0)
                        ->nullable()
                        ->visible(fn (?Procurement $record) => $record
                            && $record->isAwaitingLogistics()
                            && auth()->user()->hasVendorPermission(filament()->getTenant()->id, 'record_procurement_logistics'))
                        ->columnSpanFull(),

                    Textarea::make('notes')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Line Items')
                ->description(fn (?Procurement $record) => match (true) {
                    ! $record || $record->isDraft() => 'Add each product, its purchase price, and quantity.',
                    $record->isAwaitingLogistics() => 'Provisional pricing — final numbers land once logistics is reconciled.',
                    default => 'Final pricing — reconciled.',
                })
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Grid::make(['default' => 1, 'sm' => 2])->schema([
                                // options() instead of relationship() — same workaround
                                // used in CreateProcurement's wizard repeater, to avoid a
                                // null Builder inside Repeater in this Filament version.
                                Select::make('product_id')
                                    ->label('Product')
                                    ->options(fn () => Product::where('vendor_id', filament()->getTenant()?->id)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->searchable()
                                    ->required()
                                    ->live(),

                                TextInput::make('unit_cost')
                                    ->label('Purchase Price (₦)')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->required()
                                    ->live(debounce: 400),
                            ]),

                            TextInput::make('quantity')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->live(debounce: 400)
                                ->suffix('units'),

                            Placeholder::make('pricing_preview')
                                ->label('Engine pricing')
                                ->content(fn (Get $get) => new HtmlString(self::linePreview($get)))
                                ->columnSpanFull(),
                        ])
                        ->addActionLabel('＋ Add Another Item')
                        ->minItems(1)
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => isset($state['product_id'])
                            ? (Product::find($state['product_id'])?->name ?? 'Item')
                            : 'New Item')
                        // Status alone isn't enough — canEdit() lets both
                        // submit_procurement and record_procurement_logistics
                        // holders open a draft record's edit page (so logistics
                        // staff can reach it once it moves to awaiting_logistics
                        // too), but only submit_procurement holders may actually
                        // touch line items.
                        ->disabled(fn (?Procurement $record) => ($record && ! $record->isDraft())
                            || ! auth()->user()->hasVendorPermission(filament()->getTenant()->id, 'submit_procurement'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    // Live, pre-save preview of what the engine will compute — calls
    // PricingService's pure methods directly (no re-implementation of the
    // formula here). Provisional only (factor 1): the real trip logistics
    // allocation isn't known until reconcile, same as SubmitProcurementForLogisticsAction.
    private static function linePreview(Get $get): string
    {
        $purchasePrice = (float) ($get('unit_cost') ?? 0);
        $productId = $get('product_id');

        if ($purchasePrice <= 0 || ! $productId) {
            return '<p class="text-xs text-gray-400 dark:text-gray-500">Enter a product and purchase price to preview.</p>';
        }

        $product = Product::with('category')->find($productId);
        $markup = $product?->category?->markup !== null
            ? (float) $product->category->markup
            : (float) config('pricing.fallback_markup');

        $service = app(PricingService::class);
        $landed = $service->landedUnitCost($purchasePrice, 1.0);
        $suggested = $service->suggestedPrice($landed, $markup);
        $profit = $suggested - $landed;
        $marginPct = $suggested > 0 ? ($profit / $suggested) * 100 : 0;
        $markupPct = $landed > 0 ? ($profit / $landed) * 100 : 0;

        return '<div class="rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-gray-800 p-3 grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">'
            .'<div><span class="block text-xs text-gray-400">Landed cost (provisional)</span>₦'.number_format($landed, 2).'</div>'
            .'<div><span class="block text-xs text-gray-400">Suggested price</span>₦'.number_format($suggested, 2).'</div>'
            .'<div><span class="block text-xs text-gray-400">Profit / Margin</span>₦'.number_format($profit, 2).' · '.number_format($marginPct, 1).'%</div>'
            .'<div><span class="block text-xs text-gray-400">Markup</span>'.number_format($markupPct, 1).'%</div>'
            .'</div>';
    }

    private static function statusBadge(string $status): string
    {
        [$label, $color] = match ($status) {
            'draft' => ['Draft', 'gray'],
            'awaiting_logistics' => ['Awaiting Logistics', 'info'],
            'reconciled' => ['Reconciled', 'success'],
            default => [ucfirst($status), 'gray'],
        };

        $classes = match ($color) {
            'success' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'info' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };

        return "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {$classes}'>{$label}</span>";
    }
}
