<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\SupplierLink;
use App\Models\SupplierPayableEntry;
use App\Services\VendorLink\SupplierPayable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

/**
 * What the reseller owes each supplier, and paying it.
 *
 * The balance is summed from the ledger on every read — charges less payments —
 * so this screen cannot disagree with the history behind it. Payments are ledger
 * rows like everything else; nothing here mutates a balance, because there is no
 * balance to mutate.
 */
class SupplierPayablePage extends Page implements HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-banknotes';
    protected static string|null|UnitEnum   $navigationGroup = 'Money';
    protected static ?string                $navigationLabel = 'Supplier Payable';
    protected static ?string                $title           = 'What you owe your suppliers';
    protected static ?int                   $navigationSort  = 3;

    protected string $view = 'filament.vendor.pages.supplier-payable';

    public ?string $from = null;
    public ?string $to = null;

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function balances(): Collection
    {
        $vendor = filament()->getTenant();

        return SupplierLink::forReseller($vendor->id)
            ->with('supplier')
            ->get()
            ->map(function (SupplierLink $link) {
                $summary = app(SupplierPayable::class)->summary($link);

                return [
                    'link'     => $link,
                    'supplier' => $link->supplier?->name ?? 'Unknown supplier',
                    'active'   => $link->is_active,
                    'charged'  => $summary['charged'],
                    'paid'     => $summary['paid'],
                    'balance'  => $summary['balance'],
                ];
            })
            ->sortByDesc('balance')
            ->values();
    }

    public function totalOwed(): float
    {
        return round((float) $this->balances()->sum('balance'), 2);
    }

    /** Record money handed to a supplier. */
    public function payAction(): Action
    {
        return Action::make('pay')
            ->label('Record a payment')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->schema([
                TextInput::make('amount')
                    ->label('Amount paid')
                    ->numeric()
                    ->required()
                    ->prefix('₦'),
                Textarea::make('note')->label('Note')->rows(2),
            ])
            ->action(function (array $data, array $arguments) {
                $link = SupplierLink::forReseller(filament()->getTenant()->id)
                    ->find($arguments['link'] ?? null);

                if (! $link) {
                    Notification::make()->title('That supplier is not one of yours.')->danger()->send();

                    return;
                }

                try {
                    app(SupplierPayable::class)->pay(
                        $link,
                        (float) $data['amount'],
                        auth()->user(),
                        $data['note'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Payment recorded')->success()->send();
            });
    }

    /** The statement: every charge and payment in the chosen window. */
    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->statementQuery())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplierLink.supplier.name')
                    ->label('Supplier'),

                Tables\Columns\TextColumn::make('entry_type')
                    ->label('Entry')
                    ->badge()
                    ->color(fn (string $state) => $state === SupplierPayableEntry::TYPE_CHARGE ? 'warning' : 'success')
                    ->formatStateUsing(fn (string $state) => $state === SupplierPayableEntry::TYPE_CHARGE
                        ? 'Owed'
                        : 'Paid'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Units')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('His price')
                    ->money('NGN')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money('NGN')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->emptyStateHeading('Nothing in this period')
            ->emptyStateDescription('Debts appear here when a resold order is delivered.');
    }

    protected function statementQuery(): Builder
    {
        $vendor = filament()->getTenant();

        return SupplierPayableEntry::query()
            ->with('supplierLink.supplier')
            ->where('vendor_id', $vendor->id)
            ->when($this->from, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->to));
    }

    /**
     * The owner's alone.
     *
     * Paying a supplier is a commercial decision about the shop's money, not a
     * counter task. Enforced here rather than by hiding the nav item, which a
     * URL walks straight past.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && ($user->isSuperAdmin() || $vendor->isOwner($user));
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (! static::canAccess()) {
            return false;
        }

        return SupplierLink::forReseller(filament()->getTenant()->id)->exists();
    }
}
