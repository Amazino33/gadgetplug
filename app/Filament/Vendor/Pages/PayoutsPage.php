<?php

namespace App\Filament\Vendor\Pages;

use App\Models\OrderItem;
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
use Illuminate\Support\Facades\DB;

class PayoutsPage extends Page implements HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $view = 'filament.vendor.pages.payouts-page';

    protected static ?string $navigationLabel = 'Payouts';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 20;

    public ?array $data = [];

    public float $availableBalance = 0.0;
    public float $totalEarned      = 0.0;
    public float $totalPaid        = 0.0;
    public float $totalPending     = 0.0;

    public function mount(): void
    {
        $vendor = filament()->getTenant();

        $earned = OrderItem::where('vendor_id', $vendor->id)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['paid', 'delivered']))
            ->sum(DB::raw('quantity * unit_price'));

        $paid = VendorPayout::where('vendor_id', $vendor->id)
            ->whereIn('status', ['approved', 'paid'])
            ->sum('amount');

        $pending = VendorPayout::where('vendor_id', $vendor->id)
            ->where('status', 'pending')
            ->sum('amount');

        $this->totalEarned      = (float) $earned;
        $this->totalPaid        = (float) $paid;
        $this->totalPending     = (float) $pending;
        $this->availableBalance = max(0, $this->totalEarned - $this->totalPaid - $this->totalPending);

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
                            ->minValue(500)
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

        if ($this->availableBalance < 500) {
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
        ]);

        // Refresh balance stats
        $this->mount();

        Notification::make()
            ->title('Payout requested')
            ->body('Your request has been submitted and will be processed within 1-3 business days.')
            ->success()
            ->send();
    }
}
