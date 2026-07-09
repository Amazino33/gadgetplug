<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Product;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\HtmlString;

class CreateProcurement extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = ProcurementResource::class;

    protected function getSteps(): array
    {
        return [
            // ── Step 1: Supplier & Waybill ─────────────────────────────────────
            Step::make('Supplier')
                ->description('Who are you buying from?')
                ->icon('heroicon-o-building-office-2')
                ->schema([
                    Select::make('supplier_id')
                        ->label('Supplier')
                        ->relationship(
                            'supplier',
                            'name',
                            fn ($query) => $query->where('vendor_id', filament()->getTenant()?->id)
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
                            Textarea::make('address')->rows(2),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return Supplier::create(
                                array_merge($data, ['vendor_id' => filament()->getTenant()?->id])
                            )->id;
                        })
                        ->helperText('Can\'t find your supplier? Use the + button to add them.')
                        ->columnSpanFull(),

                    FileUpload::make('waybill_image')
                        ->label('Waybill / Receipt Photo')
                        ->image()
                        ->disk('public')
                        ->directory('waybills')
                        ->maxSize(5120)
                        ->imageEditor()
                        ->helperText('Take a photo of the physical receipt or delivery note.')
                        ->columnSpanFull(),

                    Textarea::make('notes')
                        ->label('Notes (optional)')
                        ->placeholder('e.g. Delivery batch #2 — 10 cartons of mixed accessories')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            // ── Step 2: Items ──────────────────────────────────────────────────
            Step::make('Items')
                ->description('What did you buy?')
                ->icon('heroicon-o-cube')
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Grid::make(['default' => 1, 'sm' => 2])->schema([
                                TextInput::make('barcode')
                                    ->label('Barcode')
                                    ->placeholder('Scan or type barcode…')
                                    ->live(debounce: 400)
                                    ->afterStateUpdated(function (?string $state, Set $set) {
                                        if (! $state) return;
                                        $product = Product::where('barcode', $state)
                                            ->where('vendor_id', filament()->getTenant()?->id)
                                            ->first();
                                        if ($product) {
                                            $set('product_id',    $product->id);
                                            $set('unit_cost',     $product->cost_price > 0 ? (float) $product->cost_price : null);
                                            $set('selling_price', (float) $product->price);
                                        }
                                    })
                                    ->suffixActions([
                                        Action::make('openScanner')
                                            ->icon('heroicon-o-camera')
                                            ->tooltip('Use camera to scan barcode')
                                            ->alpineClickHandler(
                                                "window.dispatchEvent(new CustomEvent('open-barcode-scanner', { detail: { fieldId: \$el.closest('[data-id]')?.dataset.id } }))"
                                            ),
                                    ]),

                                // ↓ options() used instead of relationship() to avoid null Builder
                                //   inside Repeater + HasWizard context in this Filament version.
                                Select::make('product_id')
                                    ->label('Product')
                                    ->options(fn () => Product::where('vendor_id', filament()->getTenant()?->id)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (?int $state, Set $set) {
                                        if (! $state) return;
                                        $product = Product::find($state);
                                        if ($product) {
                                            $set('barcode',       $product->barcode);
                                            $set('unit_cost',     $product->cost_price > 0 ? (float) $product->cost_price : null);
                                            $set('selling_price', (float) $product->price);
                                        }
                                    }),
                            ]),

                            Grid::make(['default' => 1, 'sm' => 3])->schema([
                                TextInput::make('quantity')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->default(1)
                                    ->suffix('units'),

                                TextInput::make('unit_cost')
                                    ->label('Unit Cost')
                                    ->numeric()
                                    ->prefix('₦')
                                    ->required()
                                    ->minValue(0),

                                TextInput::make('selling_price')
                                    ->label('Selling Price')
                                    ->numeric()
                                    ->prefix('₦')
                                    ->required()
                                    ->minValue(0),
                            ]),
                        ])
                        ->addActionLabel('＋ Add Another Item')
                        ->minItems(1)
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string =>
                            isset($state['product_id'])
                                ? (Product::find($state['product_id'])?->name ?? 'Item')
                                : 'New Item'
                        )
                        ->columnSpanFull(),
                ]),

            // ── Step 3: Financials ─────────────────────────────────────────────
            Step::make('Financials')
                ->description('How much was paid?')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    Placeholder::make('total_preview')
                        ->label('Total Cost (auto-calculated from items)')
                        ->content(function ($get): HtmlString {
                            $items = $get('items') ?? [];
                            $total = collect($items)->sum(
                                fn ($i) => (float) ($i['quantity'] ?? 0) * (float) ($i['unit_cost'] ?? 0)
                            );
                            return new HtmlString(
                                '<span class="text-3xl font-extrabold text-primary-600">₦' .
                                number_format($total, 2) . '</span>'
                            );
                        })
                        ->columnSpanFull(),

                    TextInput::make('amount_paid')
                        ->label('Amount Paid (₦)')
                        ->numeric()
                        ->prefix('₦')
                        ->required()
                        ->minValue(0)
                        ->default(0)
                        ->helperText('Enter 0 if this is a fully-credited purchase.')
                        ->columnSpanFull(),
                ]),

            // ── Step 4: Confirm ────────────────────────────────────────────────
            Step::make('Confirm')
                ->description('Submit for approval')
                ->icon('heroicon-o-check-badge')
                ->schema([
                    Placeholder::make('confirmation_notice')
                        ->label('')
                        ->content(new HtmlString('
                            <div class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-700 p-4">
                                <p class="font-semibold text-amber-800 dark:text-amber-300 text-sm mb-1">⚠️ Ready to Submit?</p>
                                <p class="text-amber-700 dark:text-amber-400 text-sm">
                                    This will create a <strong>Pending</strong> procurement record.
                                    Inventory levels will <strong>not</strong> change until approved.
                                </p>
                                <p class="text-amber-700 dark:text-amber-400 text-sm mt-2">
                                    Once submitted, this record cannot be edited. If a mistake is made, it must be voided and a new one created.
                                </p>
                            </div>
                        '))
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['vendor_id']  = filament()->getTenant()->id;
        $data['status']     = 'pending';
        $data['total_cost'] = 0;
        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->recalculate();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Procurement submitted — awaiting approval.';
    }

    protected function getRedirectUrl(): string
    {
        return ProcurementResource::getUrl('index');
    }
}
