<?php

namespace App\Filament\Vendor\Resources\Stores\Tables;

use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\Store;
use App\Models\User;
use App\Services\Inventory\StoreStockMetrics;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class StoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Store')
                    ->weight('bold')
                    ->description(fn (Store $record): ?string => $record->address ?: null)
                    ->searchable(),

                TextColumn::make('is_default')
                    ->label('Main')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Main store' : '—')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Open' : 'Closed')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),

                TextColumn::make('products_held')
                    ->label('Products')
                    ->state(fn (Store $record): int => self::metrics($record)->product_count),

                // Cost reveals margin, so it rides the same gate as everywhere
                // else. Retail is safe for anyone who can reach this screen —
                // which today is only the owner anyway.
                TextColumn::make('stock_value')
                    ->label('Stock value')
                    ->state(function (Store $record): string {
                        $m = self::metrics($record);

                        return ProductForm::canSeeCostPrice()
                            ? '₦'.number_format($m->cost_value, 2).' at cost'
                            : '₦'.number_format($m->retail_value, 2).' at retail';
                    }),

                TextColumn::make('phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('is_default', 'desc')
            ->recordActions([
                EditAction::make(),

                Action::make('setDefault')
                    ->label('Make main store')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Make this the main store')
                    ->modalDescription('New orders and stock with no branch named will fall back here instead of the current main store.')
                    ->visible(fn (Store $record) => ! $record->is_default && $record->is_active)
                    ->action(function (Store $record): void {
                        // The policy is the gate; ->visible() above only decides
                        // whether the button is drawn.
                        abort_unless(auth()->user()->can('setDefault', $record), 403);

                        $current = Store::where('vendor_id', $record->vendor_id)
                            ->where('is_default', true)
                            ->first();

                        // The hazard this guard exists for: stock reserved at
                        // the outgoing default is dispatched by reading the
                        // order's allocations, but anything that still falls
                        // back to "the default" would move to the new branch
                        // mid-flight, stranding the reservation behind it.
                        if ($current && $current->hasOutstandingReservations()) {
                            Notification::make()
                                ->title("Can't change the main store yet")
                                ->body("{$current->name} still has stock reserved for orders awaiting fulfilment. Dispatch or cancel those orders first, then try again.")
                                ->warning()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $record->makeDefault();

                        Notification::make()
                            ->title("{$record->name} is now the main store")
                            ->success()
                            ->send();
                    }),

                Action::make('toggleActive')
                    ->label(fn (Store $record) => $record->is_active ? 'Close branch' : 'Reopen branch')
                    ->icon(fn (Store $record) => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (Store $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Store $record) => $record->is_active
                        ? 'A closed branch keeps its stock and history, but no new order will be filled from it.'
                        : 'Stock here becomes sellable again.')
                    ->action(function (Store $record): void {
                        abort_unless(auth()->user()->can('toggleActive', $record), 403);

                        // The default is where everything falls back to; closing
                        // it would leave the vendor with nowhere to resolve to.
                        if ($record->is_active && $record->is_default) {
                            Notification::make()
                                ->title("Can't close the main store")
                                ->body('Make another branch the main store first, then close this one.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->update(['is_active' => ! $record->is_active]);

                        Notification::make()
                            ->title($record->is_active ? "{$record->name} reopened" : "{$record->name} closed")
                            ->success()
                            ->send();
                    }),

                Action::make('assignMembers')
                    ->label('Who works here')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->modalHeading(fn (Store $record) => "Staff at {$record->name}")
                    ->modalDescription('Only the people ticked here can work in this branch. The owner always has access to every branch and is not listed.')
                    ->fillForm(fn (Store $record) => [
                        'members' => $record->users()->pluck('users.id')->all(),
                    ])
                    ->schema(fn (Store $record) => [
                        CheckboxList::make('members')
                            ->label('Team members')
                            ->options(fn () => User::query()
                                ->whereIn('id', fn ($q) => $q->select('user_id')
                                    ->from('vendor_users')
                                    ->where('vendor_id', $record->vendor_id))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->noSearchResultsMessage('No team members yet — add staff under Team Members first.')
                            ->bulkToggleable(),
                    ])
                    ->action(function (Store $record, array $data): void {
                        abort_unless(auth()->user()->can('assignMembers', $record), 403);

                        // sync, not attach: it has to revoke as well as grant,
                        // and it is naturally idempotent against the
                        // unique(store_id, user_id) constraint.
                        $record->users()->sync($data['members'] ?? []);

                        Notification::make()
                            ->title("Staff updated for {$record->name}")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * Per-store figures, memoised for the life of the request so a table of
     * branches costs one query rather than one per row per column.
     */
    private static function metrics(Store $store): object
    {
        static $cache = [];

        if (! array_key_exists($store->id, $cache)) {
            $cache = StoreStockMetrics::forStores(
                Store::where('vendor_id', $store->vendor_id)->pluck('id')
            )->all();
        }

        return $cache[$store->id] ?? StoreStockMetrics::empty();
    }
}
