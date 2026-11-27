<?php

namespace App\Filament\Resources\Vendors\Tables;

use App\Filament\Resources\Vendors\Support\VendorFeatures;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every shop, readable at a glance on one screen: who it is, whether it can
 * get in, and what is switched on for it. The switches themselves are one
 * click away (Features) or many shops at once (the bulk switch).
 */
class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name,email'))
            ->columns([
                // The shop first, so it never scrolls off: name, the name in
                // its addresses, and whose it is — all searchable here.
                TextColumn::make('name')
                    ->label('Shop')
                    ->weight('bold')
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $u) => $u
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"))))
                    ->description(fn (Vendor $record) => collect([
                        $record->slug,
                        $record->user?->name,
                        $record->user?->email,
                    ])->filter()->implode(' · '))
                    ->wrap(),

                TextColumn::make('access')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Vendor $record) => array_values(array_filter([
                        $record->dashboard_blocked ? 'Blocked' : 'Active',
                        $record->is_verified ? null : 'Unverified',
                        $record->isFreePlan() ? 'Free plan' : null,
                    ])))
                    ->color(fn (string $state) => match ($state) {
                        'Blocked'    => 'danger',
                        'Unverified' => 'warning',
                        'Free plan'  => 'info',
                        default      => 'success',
                    })
                    ->tooltip(fn (Vendor $record) => $record->dashboard_blocked ? $record->dashboardBlockMessage() : null),

                TextColumn::make('features')
                    ->label('Switched on')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Vendor $record) => VendorFeatures::badges($record))
                    ->placeholder('Nothing')
                    ->wrap(),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('stores_count')
                    ->label('Branches')
                    ->counts('stores')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('whatsapp')
                    ->label('WhatsApp')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('dashboard_blocked')->label('Blocked'),
                TernaryFilter::make('is_verified')->label('Verified'),
                SelectFilter::make('plan')->options([
                    Vendor::PLAN_FREE => 'Free',
                    Vendor::PLAN_PAID => 'Paid',
                ]),
                ...collect(VendorFeatures::SWITCHES)
                    ->map(fn (array $feature, string $column) => TernaryFilter::make($column)->label($feature[0]))
                    ->values()
                    ->all(),
            ])
            ->recordActions([
                Action::make('open_panel')
                    ->label('Open panel')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record) => route('filament.vendor.home', ['tenant' => $record->slug]))
                    ->openUrlInNewTab(),

                // Every switch for one shop, each saying what it does, without
                // the full edit form.
                Action::make('features')
                    ->label('Features')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('primary')
                    ->modalHeading(fn (Vendor $record) => "Features for {$record->name}")
                    ->modalSubmitActionLabel('Save')
                    ->fillForm(fn (Vendor $record) => collect(VendorFeatures::SWITCHES)
                        ->keys()
                        ->mapWithKeys(fn (string $column) => [$column => (bool) $record->{$column}])
                        ->all())
                    ->schema(VendorFeatures::toggles())
                    ->action(function (Vendor $record, array $data) {
                        $record->update(collect($data)
                            ->only(array_keys(VendorFeatures::SWITCHES))
                            ->map(fn ($on) => (bool) $on)
                            ->all());

                        Notification::make()->title("Saved for {$record->name}")->success()->send();
                    }),

                EditAction::make(),

                ActionGroup::make([
                    Action::make('toggleOnlineSales')
                        ->label(fn ($record) => $record->online_sales_enabled ? 'Disable Online Sales' : 'Enable Online Sales')
                        ->icon(fn ($record) => $record->online_sales_enabled ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                        ->color(fn ($record) => $record->online_sales_enabled ? 'danger' : 'success')
                        ->requiresConfirmation()
                        ->modalDescription(fn ($record) => $record->online_sales_enabled
                            ? 'This vendor\'s products will disappear from the storefront and Orders will be hidden from their panel. Sales Point / offline sales are unaffected. Existing online orders are untouched.'
                            : 'This vendor\'s products will become visible on the storefront and they will regain access to Orders in their panel.')
                        ->action(fn ($record) => $record->update(['online_sales_enabled' => ! $record->online_sales_enabled])),
                    // Separate from the edit form so support can lock or release an
                    // account in one click from the list, without opening a form
                    // that could save four other fields by accident.
                    Action::make('toggleDashboardBlock')
                        ->label(fn ($record) => $record->dashboard_blocked ? 'Restore Access' : 'Block Access')
                        ->icon(fn ($record) => $record->dashboard_blocked ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                        ->color(fn ($record) => $record->dashboard_blocked ? 'success' : 'danger')
                        ->modalHeading(fn ($record) => $record->dashboard_blocked
                            ? 'Restore access for '.$record->name
                            : 'Block access for '.$record->name)
                        ->modalDescription(fn ($record) => $record->dashboard_blocked
                            ? 'The owner and their team get the vendor panel and the Sales Point back immediately.'
                            : 'The owner and their whole team lose the vendor panel and the Sales Point immediately — till logins already open stop working on their next request. Storefront listings are not affected.')
                        ->modalSubmitActionLabel(fn ($record) => $record->dashboard_blocked ? 'Restore access' : 'Block access')
                        ->schema(fn ($record) => $record->dashboard_blocked ? [] : [
                            Textarea::make('dashboard_blocked_reason')
                                ->label('Reason shown to the vendor')
                                ->rows(2)
                                ->maxLength(500)
                                ->required()
                                ->default(fn () => $record->dashboard_blocked_reason)
                                ->placeholder('e.g. Outstanding platform commission for August. Contact accounts to settle.'),
                        ])
                        // Confirmation is redundant once there is a form to fill in,
                        // but the unblock direction has no form and still deserves a
                        // deliberate click.
                        ->requiresConfirmation(fn ($record) => (bool) $record->dashboard_blocked)
                        ->action(function ($record, array $data) {
                            $record->update($record->dashboard_blocked
                                ? ['dashboard_blocked' => false]
                                : [
                                    'dashboard_blocked'        => true,
                                    'dashboard_blocked_reason' => $data['dashboard_blocked_reason'] ?? null,
                                ]);
                        }),
                ])->label('More')->icon('heroicon-o-ellipsis-vertical')->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // One switch, on or off, for every ticked shop.
                    BulkAction::make('set_feature')
                        ->label('Switch a feature on or off')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->schema([
                            Select::make('feature')
                                ->label('Feature')
                                ->options(VendorFeatures::options())
                                ->required(),
                            Radio::make('on')
                                ->label('Set it')
                                ->options(['1' => 'On', '0' => 'Off'])
                                ->required()
                                ->inline(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $column = $data['feature'];

                            if (! array_key_exists($column, VendorFeatures::SWITCHES)) {
                                return;
                            }

                            $on = (string) $data['on'] === '1';

                            foreach ($records as $vendor) {
                                $vendor->update([$column => $on]);
                            }

                            Notification::make()
                                ->title(VendorFeatures::label($column) . ($on ? ' switched on' : ' switched off'))
                                ->body($records->count() . ' shop' . ($records->count() === 1 ? '' : 's'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
