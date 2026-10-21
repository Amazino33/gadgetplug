<?php

namespace App\Filament\Vendor\Pages;

use App\Models\VendorPayout;
use Filament\Schemas\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PayoutsPage extends Page implements HasTable
{
    protected static string|null|\UnitEnum $navigationGroup = 'Money';
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $view = 'filament.vendor.pages.payouts-page';

    protected static ?string $navigationLabel = 'Payouts';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public float $availableBalance = 0.0;
    public float $totalEarned      = 0.0;
    public float $totalPaid        = 0.0;
    public float $totalPending     = 0.0;

    public function mount(): void
    {
        $vendor = filament()->getTenant();

        // Only money GadgetPlug has actually collected can be withdrawn: the
        // Due from GadgetPlug balance (VendorPayout::balanceFor). Order status
        // alone no longer counts — a delivered pay-on-delivery order is not
        // money until its payment is recorded.
        $balance = VendorPayout::balanceFor($vendor->id);

        $paid = VendorPayout::where('vendor_id', $vendor->id)
            ->where('status', 'paid')
            ->sum('amount');

        $pending = VendorPayout::where('vendor_id', $vendor->id)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount');

        $this->totalEarned      = $balance['collected'];
        $this->totalPaid        = (float) $paid;
        $this->totalPending     = (float) $pending;
        $this->availableBalance = $balance['available'];

        $this->form->fill([
            'bank_name'      => $vendor->bank_name ?? '',
            'account_number' => $vendor->account_number ?? '',
            'account_name'   => $vendor->account_name ?? '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Request a Payout')
                    ->description('Funds will be sent to the bank account below. Update your bank details in Store Profile.')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount to Withdraw (₦)')
                            ->numeric()
                            ->required()
                            ->minValue(VendorPayout::MINIMUM)
                            ->maxValue(fn() => $this->availableBalance)
                            ->helperText(fn() => 'Available: ₦' . number_format($this->availableBalance, 2)),

                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->disabled(),

                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->disabled(),

                        TextInput::make('account_name')
                            ->label('Account Name')
                            ->disabled(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function table(Table $table): Table
    {
        $vendor = filament()->getTenant();

        return $table
            ->query(VendorPayout::query()->where('vendor_id', $vendor->id)->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn($state) => '₦' . number_format($state, 2))
                    ->sortable(),

                TextColumn::make('bank_name')->label('Bank'),

                TextColumn::make('account_number')->label('Account No.'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'info',
                        'paid'     => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('settled_at')
                    ->label('Settled')
                    ->date('d M Y')
                    ->placeholder('—'),

                TextColumn::make('admin_notes')
                    ->label('Note')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(60),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No payout requests yet')
            ->emptyStateDescription('Submit your first payout request above.');
    }

    public function requestPayout(): void
    {
        $data   = $this->form->getState();
        $vendor = filament()->getTenant();

        // Recomputed now, not trusted from when the page loaded.
        $this->availableBalance = VendorPayout::balanceFor($vendor->id)['available'];

        if ($this->availableBalance < VendorPayout::MINIMUM) {
            Notification::make()
                ->title('Insufficient balance')
                ->body('Your available balance is below the minimum withdrawal of ₦500.')
                ->danger()
                ->send();
            return;
        }

        if ((float) $data['amount'] > $this->availableBalance) {
            Notification::make()
                ->title('Amount exceeds available balance')
                ->danger()
                ->send();
            return;
        }

        if (empty($vendor->bank_name) || empty($vendor->account_number)) {
            Notification::make()
                ->title('Bank details missing')
                ->body('Please add your bank details in Store Profile before requesting a payout.')
                ->warning()
                ->send();
            return;
        }

        VendorPayout::create([
            'vendor_id'      => $vendor->id,
            'amount'         => $data['amount'],
            'bank_name'      => $vendor->bank_name,
            'account_number' => $vendor->account_number,
            'account_name'   => $vendor->account_name,
            'status'         => 'pending',
            'funded_from'    => VendorPayout::FUNDED_GADGETPLUG_DUE,
        ]);

        // Refresh balance stats
        $this->mount();

        Notification::make()
            ->title('Payout requested')
            ->body('Your request has been submitted and will be processed within 1-3 business days.')
            ->success()
            ->send();
    }

    public static function canAccess(): bool 
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_payouts')
        );
    }
}
