<?php

namespace App\Filament\Vendor\Resources\Customers;

use App\Filament\Vendor\Resources\CustomerDebts\CustomerDebtResource;
use App\Models\PosCustomer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * Every customer the shop has — added at the till, imported, or entered here.
 *
 * Until this existed the panel only showed customers who owed money (Customer
 * Debts), so a customer list could be imported and then not seen anywhere.
 * What each one owes is shown alongside, from the same ledger Customer Debts
 * reads, and links there to collect it.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = PosCustomer::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-users';
    protected static string|null|UnitEnum    $navigationGroup = 'Sales Point';
    protected static ?string                 $navigationLabel = 'Customers';
    protected static ?string                 $modelLabel      = 'customer';
    protected static ?int                    $navigationSort  = 2;

    /** Customers' contact details: the owner, store admins, and whoever chases debts. */
    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user && (
            $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'edit_vendor')
            || $user->hasVendorPermission($vendor->id, 'view_customer_debts')
        );
    }

    /** Adding and changing customers: the owner and store admins. */
    public static function canManage(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user && (
            $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'edit_vendor')
        );
    }

    public static function canCreate(): bool        { return static::canManage(); }
    public static function canEdit($record): bool   { return static::canManage(); }
    // Never deleted from here: a customer carries a debt ledger and sales
    // history, and removing them would take both with them.
    public static function canDelete($record): bool { return false; }
    public static function canDeleteAny(): bool     { return false; }

    public static function getEloquentQuery(): Builder
    {
        // What each customer owes, from the same append-only ledger Customer
        // Debts reads: charges, less payments and write-offs.
        // Scoped to the shop explicitly, as Customer Debts is: this model is
        // not wired into the panel's automatic tenant scoping, and without the
        // where() every shop's customers would show here.
        return parent::getEloquentQuery()
            ->where('pos_customers.vendor_id', filament()->getTenant()?->id)
            ->withSum('ledgerEntries as outstanding_amount', 'amount');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Customer')->schema([
                TextInput::make('name')->required()->maxLength(100),
                TextInput::make('phone')
                    ->tel()
                    ->maxLength(20)
                    ->helperText('Used to find them at the till, and to match them on a later import.')
                    // One record per number, as the till keeps it: the same
                    // person should not end up with two balances.
                    ->rule(fn ($record) => Rule::unique('pos_customers', 'phone')
                        ->where('vendor_id', filament()->getTenant()?->id)
                        ->ignore($record?->id)),
                TextInput::make('email')->email()->maxLength(100),
                TextInput::make('shop_location')->label('Shop / Area')->maxLength(255),
                TextInput::make('address')->maxLength(255)->columnSpanFull(),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold')
                    ->description(fn (PosCustomer $c) => $c->shop_location),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                TextColumn::make('email')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('outstanding_amount')
                    ->label('Owes')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? '₦'.number_format((float) $state, 2) : '—'),
                TextColumn::make('total_spent')->label('Spent')->money('NGN')->sortable()->toggleable(),
                TextColumn::make('total_transactions')->label('Visits')->alignCenter()->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Added')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('owes')
                    ->label('Owes money')
                    // Named $query: Filament passes it by name.
                    ->query(fn (Builder $query) => $query->whereRaw('(select coalesce(sum(amount), 0) from pos_customer_ledger_entries'
                        .' where pos_customer_ledger_entries.pos_customer_id = pos_customers.id) > 0')),
            ])
            ->recordActions([
                EditAction::make(),
                // Collecting a debt lives on Customer Debts; this takes them there.
                Action::make('debt')
                    ->label('Debt')
                    ->icon('heroicon-o-banknotes')
                    ->color('danger')
                    ->url(fn (PosCustomer $c) => CustomerDebtResource::getUrl('view', ['record' => $c]))
                    ->visible(fn (PosCustomer $c) => (float) $c->outstanding_amount > 0 && CustomerDebtResource::canAccess()),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('Customers are added at the Sales Point, or import your existing list with "Import customers".');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCustomers::route('/'),
        ];
    }
}
