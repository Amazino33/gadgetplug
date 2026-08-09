<?php

namespace App\Filament\Vendor\Resources\Expenses;

use App\Filament\Vendor\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Vendor\Resources\Expenses\Pages\EditExpense;
use App\Filament\Vendor\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\FinancialAccount;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = Heroicon::OutlinedReceiptPercent;
    protected static string|null|UnitEnum   $navigationGroup = 'Store';
    protected static ?string                $navigationLabel = 'Expenses';
    protected static ?int                   $navigationSort  = 8;

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'manage_expenses')
        );
    }

    public static function canDelete($record): bool
    {
        // Matches EditExpense's header action — deleting a posted expense
        // would silently understate the ledger entry it already caused.
        return ! $record->isPosted();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('category')
                        ->label('What kind of cost is this?')
                        ->options(Expense::CATEGORIES)
                        ->required()
                        ->disabled(fn (?Expense $record) => $record?->isPosted() ?? false)
                        ->helperText(
                            '• Advertising — Facebook/Instagram boosts, influencer pay, any marketing spend. ' .
                            '• Logistics (Other) — a bike repair, a rider\'s monthly retainer, fuel not tied to one specific supplier trip. Do NOT use this for stock-collection costs (record those as a "Logistics" stage when creating the procurement) or for a customer\'s delivery cost (record that on the order itself, under "Assign & Notify Rider") — both of those are already tracked elsewhere and would be counted twice if entered here too. ' .
                            '• Other — rent, airtime, electricity, anything that doesn\'t fit above.'
                        )
                        ->columnSpanFull(),

                    TextInput::make('amount')
                        ->label('Amount (₦)')
                        ->numeric()
                        ->prefix('₦')
                        ->required()
                        ->minValue(0)
                        ->disabled(fn (?Expense $record) => $record?->isPosted() ?? false),

                    DatePicker::make('incurred_at')
                        ->label('Date This Happened')
                        ->required()
                        ->default(now())
                        ->helperText('When the cost was incurred — not necessarily the same day you pay it.'),

                    Select::make('financial_account_id')
                        ->label('Paid From')
                        ->options(fn () => FinancialAccount::where('vendor_id', filament()->getTenant()?->id)->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Leave blank — not paid yet')
                        ->helperText(fn (?Expense $record) => $record?->isPosted()
                            ? 'Already paid from this account — locked in and can\'t be changed. If this was a mistake, let us know rather than trying to edit it.'
                            : 'Pick the account once you\'ve actually paid this. The moment you save with an account selected, the amount is deducted from that account\'s balance right away. Leave it blank if you haven\'t paid yet — you can come back and add it later.')
                        ->disabled(fn (?Expense $record) => $record?->isPosted() ?? false)
                        ->columnSpanFull(),

                    Textarea::make('description')
                        ->label('Note (optional)')
                        ->placeholder('e.g. which campaign, which rider, what it was for')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Expense::CATEGORIES[$state] ?? $state),

                TextColumn::make('amount')
                    ->money('NGN')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('NGN')),

                TextColumn::make('incurred_at')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('description')
                    ->limit(50)
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('financialAccount.name')
                    ->label('Paid From')
                    ->placeholder('—'),

                TextColumn::make('posted_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? 'Paid' : 'Unpaid')
                    ->color(fn (?string $state) => $state ? 'success' : 'warning'),

                TextColumn::make('creator.name')
                    ->label('Recorded By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('incurred_at', 'desc')
            ->filters([
                SelectFilter::make('category')
                    ->options(Expense::CATEGORIES),

                Filter::make('incurred_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('incurred_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('incurred_at', '<=', $date));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'edit'   => EditExpense::route('/{record}/edit'),
        ];
    }
}
