<?php

namespace App\Filament\Resources\LogisticsCompanies;

use App\Models\Lga;
use App\Models\LogisticsCompany;
use App\Support\Plural;
use App\Models\State;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * GadgetPlug's logistics companies — the outside firms online orders are sent
 * through (vendor_id null). Replaces the planned "riders" list: riders are the
 * companies' people, named per order. Vendors' own companies live in the
 * vendor panel and never appear here.
 *
 * For dispatch staff (manage_dispatch; super_admin passes). Companies are
 * deactivated rather than deleted, so past orders keep their company.
 */
class LogisticsCompanyResource extends Resource
{
    protected static ?string $model = LogisticsCompany::class;

    protected static string|BackedEnum|null $navigationIcon  = Heroicon::OutlinedTruck;
    protected static ?string                $navigationLabel = 'Logistics companies';
    protected static ?int                   $navigationSort  = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->hasGlobalPermission('manage_dispatch'));
    }

    public static function canViewAny(): bool { return static::canAccess(); }
    public static function canCreate(): bool { return static::canAccess(); }
    public static function canEdit(Model $record): bool { return static::canAccess() && $record->vendor_id === null; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('vendor_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Company')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('contact_person')->label('Contact person')->maxLength(255),
                TextInput::make('phone')
                    ->label('Dispatch phone')
                    ->tel()
                    ->required()
                    ->maxLength(30),
                TextInput::make('whatsapp')
                    ->label('Dispatch WhatsApp')
                    ->tel()
                    ->maxLength(30)
                    ->helperText('Where dispatch links are sent. Leave empty to use the dispatch phone.'),
                Toggle::make('is_active')->label('Active')->default(true),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ])->columns(2),

            Section::make('Areas covered')
                ->description('Used to suggest this company first when assigning an order going there. It can still be chosen for anywhere.')
                ->schema([
                    Select::make('coverage_state_ids')
                        ->label('Whole states')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => State::orderBy('name')->pluck('name', 'id')),
                    Select::make('coverage_lga_ids')
                        ->label('Individual LGAs')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Lga::query()
                            ->with('state')
                            ->where('name', 'like', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Lga $lga) => [$lga->id => $lga->name.' — '.$lga->state?->name]))
                        ->getOptionLabelsUsing(fn (array $values) => Lga::query()
                            ->with('state')
                            ->whereIn('id', $values)
                            ->get()
                            ->mapWithKeys(fn (Lga $lga) => [$lga->id => $lga->name.' — '.$lga->state?->name])
                            ->all()),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold')->wrap()
                    // On a phone the phone column is hidden; the number shows here.
                    ->description(fn (LogisticsCompany $record) => collect([$record->contact_person, $record->phone])->filter()->implode(' · ') ?: null),
                TextColumn::make('phone')
                    ->label('Dispatch phone')
                    ->placeholder('—')
                    ->url(fn (?string $state): ?string => $state ? 'tel:'.preg_replace('/[^\d+]/', '', $state) : null)
                    ->color('primary')
                    ->visibleFrom('md'),
                TextColumn::make('whatsapp')->label('WhatsApp')->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('coverage')
                    ->label('Covers')
                    ->getStateUsing(fn (LogisticsCompany $record) => collect([
                        ($states = count($record->coverage_state_ids ?? [])) ? $states.' '.str('state')->plural($states) : null,
                        ($lgas = count($record->coverage_lga_ids ?? [])) ? $lgas.' '.Plural::lgas($lgas) : null,
                    ])->filter()->implode(', ') ?: 'Not set')
                    ->visibleFrom('md'),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->counts('orders')
                    ->alignCenter()
                    ->visibleFrom('md'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            // Tapping a company opens it; the pencil is there too.
            ->recordAction('edit')
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->size('lg')
                    ->modalWidth('2xl')
                    ->successNotificationTitle('Saved'),
            ])
            ->emptyStateHeading('No logistics companies yet')
            ->emptyStateDescription('Add the companies GadgetPlug pays to deliver orders. Dispatch assigns them to orders.')
            ->emptyStateIcon('heroicon-o-truck')
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageLogisticsCompanies::route('/'),
        ];
    }
}
