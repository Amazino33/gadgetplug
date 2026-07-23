<?php

namespace App\Filament\Vendor\Resources\Products\Tables;

use App\Models\AuditSession;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    // Neutral gray box + photo glyph — used wherever a product has no image.
    private const PLACEHOLDER_IMAGE = 'data:image/svg+xml,' . '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="%23e5e7eb"/><path d="M28 68l16-22 11 13 9-11 18 24" stroke="%239ca3af" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="37" cy="36" r="7" fill="%239ca3af"/></svg>';

    public static function configure(Table $table): Table
    {
        // Resolved once, directly, rather than via closures — contentGrid()'s
        // closure evaluation does not reliably receive $livewire the way
        // per-column hidden()/visible() closures do.
        $isGrid = $table->getLivewire()->displayMode === 'grid';

        return $table
            ->contentGrid($isGrid
                ? ['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4]
                : null
            )
            ->columns([
                ViewColumn::make('card')
                    ->view('filament.vendor.products.grid-card')
                    ->visible($isGrid),

                Split::make([
                    ImageColumn::make('thumb')
                        ->label('')
                        ->getStateUsing(fn (Product $record): ?string =>
                            $record->getFirstMediaUrl('product-images', 'thumb') ?: null
                        )
                        ->defaultImageUrl(self::PLACEHOLDER_IMAGE)
                        ->size(44)
                        ->grow(false),

                    Stack::make([
                        TextColumn::make('name')
                            ->weight(FontWeight::SemiBold)
                            ->searchable()
                            ->sortable()
                            ->wrap(),

                        TextColumn::make('eyebrow')
                            ->label('')
                            ->getStateUsing(fn (Product $record): ?string =>
                                trim(collect([$record->category?->name, $record->brand])->filter()->implode(' · ')) ?: null
                            )
                            ->color('gray')
                            ->size(TextSize::ExtraSmall),
                    ])->space(1),
                ])->hidden($isGrid),

                TextColumn::make('category.name')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->hidden($isGrid),

                TextColumn::make('price')
                    ->label('Price')
                    ->money('NGN')
                    ->weight(FontWeight::Bold)
                    ->description(fn (Product $record): ?string => $record->cost_price !== null
                        ? 'Cost ₦' . number_format((float) $record->cost_price, 2)
                        : null, position: 'above')
                    ->sortable()
                    ->hidden($isGrid),

                TextColumn::make('stock_quantity')
                    ->label('On Shelf')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->hidden($isGrid),

                TextColumn::make('reserved_stock')
                    ->label('Reserved')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->hidden($isGrid),

                TextColumn::make('available_stock')
                    ->label('Available')
                    ->numeric()
                    ->sortable(query: fn ($query, $direction) =>
                        $query->orderByRaw("CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) {$direction}")
                    )
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state === 0 => 'danger',
                        $state < 5   => 'warning',
                        default      => 'success',
                    })
                    ->hidden($isGrid),

                TextColumn::make('brand')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->hidden($isGrid),

                TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(function (Product $record): string {
                        if ($record->status === 'published' && $record->unpublish_at?->isPast()) {
                            return 'expired';
                        }
                        return $record->status;
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft'     => 'gray',
                        'archived'  => 'warning',
                        'expired'   => 'danger',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->hidden($isGrid),

                TextColumn::make('published_at')
                    ->label('Goes Live')
                    ->since()
                    ->sortable()
                    ->placeholder('Immediately')
                    ->toggleable()
                    ->hidden($isGrid),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft'     => 'Draft',
                        'published' => 'Published',
                        'archived'  => 'Archived',
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Edit'),

                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Delete'),

                Action::make('start_audit')
                    ->label('Start Inventory Count')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->iconButton()
                    ->tooltip('Start Inventory Count')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Initiate Inventory Audit')
                    ->modalDescription('Enter your physical count. A second storekeeper will verify this.')
                    ->slideOver()
                    ->form([
                        TextInput::make('count_a')
                            ->label('My Physical Count')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->visible(fn (?Product $record): bool =>
                        $record !== null &&
                        !AuditSession::where('product_id', $record->id)->where('status', 'pending')->exists()
                    )
                    ->action(function (Product $record, array $data): void {
                        AuditSession::create([
                            'vendor_id'         => $record->vendor_id,
                            'product_id'        => $record->id,
                            'storekeeper_a_id'  => auth()->id(),
                            'count_a'           => $data['count_a'],
                            'status'            => 'pending',
                        ]);
                    })
                    ->successNotificationTitle('Audit started — waiting for Storekeeper B to verify.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
