<?php

namespace App\Filament\Vendor\Resources\FinancialAccounts;

use App\Filament\Vendor\Resources\FinancialAccounts\Pages\EditFinancialAccount;
use App\Filament\Vendor\Resources\FinancialAccounts\Pages\ListFinancialAccounts;
use App\Filament\Vendor\Resources\FinancialAccounts\RelationManagers\LedgerEntriesRelationManager;
use App\Models\FinancialAccount;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

// Bank/cash are seeded once per vendor (FinancialAccounts::seedFor) and never
// created or deleted through this UI — an owner can only view balances and
// correct an opening figure. Everything after that is the ledger, not this form.
class FinancialAccountResource extends Resource
{
    protected static ?string $model = FinancialAccount::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = Heroicon::OutlinedBanknotes;
    protected static string|null|UnitEnum   $navigationGroup = 'Store';
    protected static ?string                $navigationLabel = 'Financial Accounts';
    protected static ?int                   $navigationSort  = 7;

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'manage_financial_accounts')
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description('How much was already in this account before you started tracking it here — e.g. what was actually in your bank or cash box on the day you began using this page. This is NOT the current balance; every payment you record afterwards updates that automatically (see the table below). Set this once and leave it — if it needs correcting later, use a new entry in the ledger tab rather than changing this figure.')
                ->schema([
                    TextInput::make('opening_balance')
                        ->label('Opening Balance')
                        ->numeric()
                        ->prefix('₦')
                        ->required()
                        ->helperText('The starting amount only — not what\'s in the account today.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('bold'),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => $state === 'bank' ? 'info' : 'success'),

                TextColumn::make('opening_balance')
                    ->label('Opening Balance')
                    ->money('NGN'),

                TextColumn::make('balance')
                    ->label('Current Balance')
                    ->weight('bold')
                    ->getStateUsing(fn (FinancialAccount $record) => $record->balance())
                    ->formatStateUsing(fn ($state) => '₦' . number_format((float) $state, 2)),

                IconColumn::make('is_active')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Set Opening Balance'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LedgerEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialAccounts::route('/'),
            'edit'  => EditFinancialAccount::route('/{record}/edit'),
        ];
    }
}
