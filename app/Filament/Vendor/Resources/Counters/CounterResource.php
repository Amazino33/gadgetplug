<?php

namespace App\Filament\Vendor\Resources\Counters;

use App\Models\StockTakeCounter;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeAccess;
use App\Services\StockTake\StockTakeCounters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Counters: people who count stock but have no use for the site. They sign in
 * at the shop's QR code with their name and a 6-digit code (StockTakeCounters).
 *
 * Needs manage_stock_takes, the same as giving out count tasks.
 */
class CounterResource extends Resource
{
    protected static ?string $model = StockTakeCounter::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-user-group';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string                $navigationLabel = 'Counters';
    protected static ?string                $modelLabel      = 'counter';
    protected static ?int                   $navigationSort  = 7;

    public static function canAccess(): bool
    {
        $user    = auth()->user();
        $vendor  = filament()->getTenant();
        $storeId = ActiveStore::currentId();

        return $user && $vendor && $storeId && StockTakeAccess::canManageCounters($user, $vendor->id, $storeId);
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        // Explicit rather than left to the tenant scope: one shop's counters
        // must never reach another's screen.
        return parent::getEloquentQuery()->where('vendor_id', filament()->getTenant()?->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->emptyStateHeading('No counters yet')
            ->emptyStateDescription('Add the people who will count. They get a 6-digit code and sign in by scanning the shop\'s QR code. No email or password.')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (StockTakeCounter $record) => ! $record->is_active ? 'Off' : ($record->isLocked() ? 'Locked' : 'On'))
                    ->color(fn (string $state) => match ($state) {
                        'On'    => 'success',
                        'Locked' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (StockTakeCounter $record) => $record->isLocked() ? 'Too many wrong codes. A new code unlocks them.' : null),
                TextColumn::make('last_signed_in_at')
                    ->label('Last signed in')
                    ->dateTime('D d M, g:ia', 'Africa/Lagos')
                    ->placeholder('Never'),
            ])
            ->recordActions([
                Action::make('newCode')
                    ->label('New code')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (StockTakeCounter $record) => "New code for {$record->name}?")
                    ->modalDescription('Their old code stops working at once, and they are signed out.')
                    ->action(function (StockTakeCounter $record) {
                        self::run(function () use ($record) {
                            $code = app(StockTakeCounters::class)->resetCode(auth()->user(), $record);
                            self::showCode($record->name, $code);
                        });
                    }),

                Action::make('toggle')
                    ->label(fn (StockTakeCounter $record) => $record->is_active ? 'Turn off' : 'Turn on')
                    ->icon(fn (StockTakeCounter $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (StockTakeCounter $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation(fn (StockTakeCounter $record) => $record->is_active)
                    ->modalDescription('They are signed out and cannot sign in again until you turn them back on. What they counted stays on record.')
                    ->action(function (StockTakeCounter $record) {
                        self::run(function () use ($record) {
                            app(StockTakeCounters::class)->setActive(auth()->user(), $record, ! $record->is_active);
                            Notification::make()->title($record->is_active ? "{$record->name} turned on" : "{$record->name} turned off")->success()->send();
                        });
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCounters::route('/'),
        ];
    }

    /** The code, once. It is kept only as a hash and cannot be looked up again. */
    public static function showCode(string $name, string $code): void
    {
        Notification::make()
            ->title("Code for {$name}: {$code}")
            ->body('Write it down or tell them now. It cannot be shown again; if it is lost, make a new one.')
            ->success()
            ->persistent()
            ->send();
    }

    /** Run an action, showing a refusal as a message rather than an error page. */
    public static function run(callable $call): void
    {
        try {
            $call();
        } catch (ValidationException|AuthorizationException $e) {
            Notification::make()
                ->title('Not done')
                ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
