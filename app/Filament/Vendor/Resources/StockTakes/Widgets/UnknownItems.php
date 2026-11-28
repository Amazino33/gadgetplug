<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeUnknownItem;
use App\Services\StockTake\StockTakePlanner;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
use Illuminate\Support\Collection;
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
            ->extraAttributes(['class' => 'gp-phone-cards gp-st-scroll'])
            ->query(fn () => StockTakeUnknownItem::query()
                ->where('vendor_id', $stockTake->vendor_id)
                ->where('store_id', $stockTake->store_id)
                ->open()
                ->with(['aisle', 'creator:id,name']))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
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
                // Three columns that fit a laptop, instead of six that ran off it:
                // the photo, what the counter wrote with its barcode, and where,
                // who and when.
                ImageColumn::make('photo_path')->visibleFrom('md')->label('')->disk('public')->square()->imageSize(40)->grow(false),
                TextColumn::make('name_entered')
                    ->visibleFrom('md')
                    ->label('Written as')
                    ->weight('bold')
                    ->wrap()
                    ->searchable(['name_entered', 'barcode'])
                    ->description(fn (StockTakeUnknownItem $record) => $record->barcode ?: 'No barcode'),
                TextColumn::make('created_at')
                    ->visibleFrom('md')
                    ->label('Found')
                    ->sortable()
                    ->wrap()
                    ->formatStateUsing(fn (StockTakeUnknownItem $record) => $record->aisle?->name ?? 'No aisle')
                    ->description(fn (StockTakeUnknownItem $record) => collect([
                        $record->creator?->name,
                        $record->created_at?->timezone('Africa/Lagos')->format('D j M, g:ia'),
                    ])->filter()->implode(' · ')),
            ])
            ->recordActions([
                // Opens LinkProductPanel straight away in the browser: what the
                // counter wrote beside the likeliest products, to compare.
                Action::make('link_product')
                    ->label('Link to existing')
                    ->icon('heroicon-o-link')
                    ->alpineClickHandler(fn (StockTakeUnknownItem $record) => "\$dispatch('gp-link-product', { id: {$record->id} })")
                    // A pointer resting on it (or a finger landing) starts
                    // loading, so the pop-up is usually filled by the click.
                    ->extraAttributes(fn (StockTakeUnknownItem $record) => [
                        'x-on:mouseenter' => "\$dispatch('gp-link-product-hover', { id: {$record->id} })",
                        'x-on:mouseleave' => "\$dispatch('gp-link-product-hover', { id: null })",
                        'x-on:touchstart.passive' => "\$dispatch('gp-link-product-hover', { id: {$record->id} })",
                    ]),

                Action::make('create_product')
                    ->color('gray')
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

                Action::make('ignore')
                    ->label('Ignore')
                    ->icon('heroicon-o-eye-slash')
                    ->iconButton()
                    ->tooltip('Ignore: set it aside')
                    ->modalHeading('Set this barcode aside?')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (StockTakeUnknownItem $record) => $this->attempt(
                        fn () => app(StockTakePlanner::class)->ignoreUnknown(auth()->user(), $record),
                        'Set aside',
                    )),
            ])
            ->toolbarActions([
                BulkAction::make('ignore_selected')
                    ->label('Ignore selected')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (?Collection $records) => 'Set ' . ($records?->count() ?? 0) . ' aside?')
                    ->modalDescription('They leave this list. Nothing changes in stock.')
                    ->modalSubmitActionLabel('Ignore')
                    ->action(function (Collection $records) {
                        $done = 0;
                        $left = 0;

                        foreach ($records as $record) {
                            try {
                                app(StockTakePlanner::class)->ignoreUnknown(auth()->user(), $record);
                                $done++;
                            } catch (ValidationException|AuthorizationException) {
                                $left++;
                            }
                        }

                        Notification::make()
                            ->title("{$done} set aside")
                            ->body($left > 0 ? "{$left} could not be: already dealt with, or not allowed." : null)
                            ->{$done > 0 ? 'success' : 'warning'}()
                            ->send();
                        $this->dispatch('stock-take-updated');
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /** "Create it as a new product" from the link pop-up, when nothing matched. */
    #[On('gp-create-unknown')]
    public function createUnknown(int $id): void
    {
        $this->mountTableAction('create_product', (string) $id);
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
