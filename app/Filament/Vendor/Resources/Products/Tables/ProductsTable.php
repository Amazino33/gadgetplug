<?php

namespace App\Filament\Vendor\Resources\Products\Tables;

use App\Models\AuditSession;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
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

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->hidden($isGrid),

                TextColumn::make('category.name')
                    ->sortable()
                    ->hidden($isGrid),

                TextColumn::make('price')
                    ->money('NGN')
                    ->sortable()
                    ->hidden($isGrid),

                TextColumn::make('stock_quantity')
                    ->label('On Shelf')
                    ->numeric()
                    ->sortable()
                    ->hidden($isGrid),

                TextColumn::make('reserved_stock')
                    ->label('Reserved')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
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
                EditAction::make(),

                Action::make('start_audit')
                    ->label('Start Inventory Count')
                    ->icon('heroicon-o-clipboard-document-check')
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
