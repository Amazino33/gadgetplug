<?php

namespace App\Filament\Resources\Procurements;

use App\Actions\Procurement\ApproveProcurementAction;
use App\Models\Procurement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProcurementResource extends Resource
{
    protected static ?string $model = Procurement::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-inbox-arrow-down';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string                $navigationLabel = 'Procurements';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool   { return false; }
    public static function canEdit($r): bool   { return false; }
    public static function canDelete($r): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->weight('bold')->copyable(),
                TextColumn::make('vendor.name')->label('Store')->searchable()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('items_count')->label('Items')->counts('items')->alignCenter(),
                TextColumn::make('total_cost')->money('NGN')->sortable(),
                TextColumn::make('amount_paid')->money('NGN'),

                TextColumn::make('payment_status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'full'         => 'success',
                        'part_payment' => 'warning',
                        'credit'       => 'danger',
                        default        => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'full'         => 'Fully Paid',
                        'part_payment' => 'Part-Payment',
                        'credit'       => 'Credit',
                        default        => $state,
                    }),

                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'voided'   => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('variance_flag')->label('⚠ Variance')
                    ->getStateUsing(function (Procurement $record): string {
                        return $record->items()->with('product')->get()->some(
                            fn ($i) => $i->hasCostVariance()
                        ) ? '⚠ Yes' : '—';
                    })
                    ->color(fn (string $state) => $state !== '—' ? 'warning' : 'gray')
                    ->alignCenter(),

                TextColumn::make('creator.name')->label('Logged By')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Submitted')->dateTime('d M Y, H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'voided' => 'Voided']),
                SelectFilter::make('vendor_id')->label('Store')->relationship('vendor', 'name'),
            ])
            ->recordAction('view')
            ->actions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Procurement $record) => Pages\ViewProcurement::getUrl(['record' => $record])),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Procurement')
                    ->modalDescription(fn (Procurement $record) =>
                        "Approving {$record->reference} will restock {$record->items()->count()} product(s) and update cost/selling prices."
                    )
                    ->visible(fn (Procurement $record) => $record->isPending())
                    ->action(function (Procurement $record, ApproveProcurementAction $action) {
                        try {
                            $action->execute($record);
                            Notification::make()->title('Procurement approved. Stock updated.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error: ' . $e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Void Procurement')
                    ->form([
                        Textarea::make('void_reason')
                            ->label('Void Reason')
                            ->placeholder('Explain why this record is being voided…')
                            ->required()
                            ->minLength(10),
                    ])
                    ->visible(fn (Procurement $record) => $record->isPending())
                    ->action(function (Procurement $record, array $data) {
                        $record->update(['status' => 'voided', 'void_reason' => $data['void_reason']]);
                        Notification::make()->title('Procurement voided.')->warning()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProcurements::route('/'),
            'view'  => Pages\ViewProcurement::route('/{record}'),
        ];
    }
}
