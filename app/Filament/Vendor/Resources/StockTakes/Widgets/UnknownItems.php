<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeUnknownItem;
use App\Services\StockTake\StockTakePlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Storage;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

/**
 * Barcodes the system did not know, found at this branch while mapping or
 * counting. Each becomes a new product, is linked to an existing one, or is
 * set aside.
 */
class UnknownItems extends TableWidget
{
    protected static bool $isDiscovered = false;

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Not in the system';

    #[On('stock-take-updated')]
    public function refreshList(): void {}

    public function table(Table $table): Table
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->record;

        return $table
            ->extraAttributes(['class' => 'gp-phone-cards'])
            ->query(fn () => StockTakeUnknownItem::query()
                ->where('vendor_id', $stockTake->vendor_id)
                ->where('store_id', $stockTake->store_id)
                ->open()
                ->with(['aisle', 'creator:id,name']))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No unknown barcodes waiting')
            ->columns([
                // Phone only: one card per row (mobile-row.blade.php), so nothing
                // runs off the side of the screen. The columns below are md and up.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->state(fn (StockTakeUnknownItem $record) => [
                        'title' => $record->name_entered,
                        'image' => $record->photo_path ? Storage::disk('public')->url($record->photo_path) : null,
                        'lines' => [
                            $record->barcode,
                            collect([
                                $record->aisle?->name ? 'Found in ' . $record->aisle->name : null,
                                $record->creator?->name ? 'by ' . $record->creator->name : null,
                                $record->created_at?->timezone('Africa/Lagos')->format('d M, g:ia'),
                            ])->filter()->implode(' · '),
                        ],
                    ])
                    ->view('filament.vendor.stock-take.mobile-row')
                    ->disabledClick()
                    ->hiddenFrom('md'),
                ImageColumn::make('photo_path')->visibleFrom('md')->label('Photo')->disk('public')->square(),
                TextColumn::make('name_entered')->visibleFrom('md')->label('Written as')->weight('bold')->searchable(),
                TextColumn::make('barcode')->visibleFrom('md')->placeholder('—')->searchable(),
                TextColumn::make('aisle.name')->visibleFrom('md')->label('Found in')->placeholder('—'),
                TextColumn::make('creator.name')->visibleFrom('md')->label('By'),
                TextColumn::make('created_at')->visibleFrom('md')->label('When')->dateTime('d M, g:ia', 'Africa/Lagos'),
            ])
            ->recordActions([
                Action::make('create_product')
                    ->label('Create product')
                    ->icon('heroicon-o-plus')
                    ->fillForm(fn (StockTakeUnknownItem $record) => ['name' => $record->name_entered])
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('price')->label('Selling price')->numeric()->minValue(0)->prefix('₦')->required(),
                        TextInput::make('base_unit')->label('Sold by')->placeholder('Piece')->maxLength(32),
                    ])
                    ->action(fn (StockTakeUnknownItem $record, array $data) => $this->attempt(
                        fn () => app(StockTakePlanner::class)->createFromUnknown(auth()->user(), $record, $data),
                        'Product created and put on its aisle',
                    )),

                Action::make('link_product')
                    ->label('Link to existing')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->searchable()
                            ->required()
                            ->getSearchResultsUsing(fn (string $search) => Product::query()
                                ->where('vendor_id', $stockTake->vendor_id)
                                ->whereNull('source_product_id')
                                ->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                                ->orderBy('name')
                                ->limit(20)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value) => Product::query()->where('vendor_id', $stockTake->vendor_id)->whereKey($value)->value('name')),
                    ])
                    ->action(fn (StockTakeUnknownItem $record, array $data) => $this->attempt(
                        fn () => app(StockTakePlanner::class)->linkUnknown(
                            auth()->user(),
                            $record,
                            Product::query()->where('vendor_id', $stockTake->vendor_id)->findOrFail($data['product_id']),
                        ),
                        'Barcode linked',
                    )),

                Action::make('ignore')
                    ->label('Ignore')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (StockTakeUnknownItem $record) => $this->attempt(
                        fn () => app(StockTakePlanner::class)->ignoreUnknown(auth()->user(), $record),
                        'Set aside',
                    )),
            ]);
    }

    private function attempt(callable $call, string $success): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            Notification::make()->title('Not done')->body(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        } catch (AuthorizationException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
        $this->dispatch('stock-take-updated');
    }
}
