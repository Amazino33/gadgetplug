<?php

declare(strict_types=1); // Forces PHP to strictly enforce variable types in this file

namespace App\Filament\Vendor\Resources;

use App\Filament\Vendor\Resources\AuditSessionResource\Pages;

use App\Models\AuditSession;
use App\Actions\Inventory\ProcessAuditCountAction;
use App\Actions\Inventory\AdjustStockAction;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Exception;
use BackedEnum; // For strict typing of our icon enum, if we choose to use one
use Filament\Actions\Action as ActionsAction;
use UnitEnum;

class AuditSessionResource extends Resource
{
    protected static ?string $model = AuditSession::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    // You can use a string, or if you install a Blade Icon Enum package, 
    // you would use something like: protected static string|BackedEnum|null $navigationIcon = HeroIcon::ClipboardDocumentList;
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-clipboard-document-list';
    
    protected static string|null|UnitEnum $navigationGroup = 'Inventory';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->searchable()
                    ->sortable(),
                
                TextColumn::make('status')
                    ->badge()
                    // Strict typing: We promise this closure returns a string
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'discrepancy' => 'danger',
                        'verified' => 'success',
                        'resolved_by_override' => 'primary',
                        default => 'gray',
                    }),

                TextColumn::make('storekeeperA.name')
                    ->label('Initiated By'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->actions([
                
                // --- ACTION 1: STOREKEEPER B VERIFIES ---
                ActionsAction::make('verify_count')
                    ->label('Submit My Count')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Verify Physical Stock')
                    ->slideOver()
                    ->form([
                        TextInput::make('count_b')
                            ->label('My Physical Count')
                            ->numeric()
                            ->required()
                            ->minValue(0), // Prevent negative counts
                    ])
                    // Strict typing: Promise this closure returns a boolean
                    ->visible(fn (AuditSession $record): bool => $record->status === 'pending' && $record->storekeeper_a_id !== auth()->id())
                    // Strict typing: Promise this closure returns nothing (void)
                    ->action(function (AuditSession $record, array $data, ProcessAuditCountAction $processAudit): void {
                        try {
                            // Cast the form data to a strict integer before passing it to our Action class
                            $audit = $processAudit->execute($record, auth()->id(), (int) $data['count_b']);
                            
                            if ($audit->status === 'verified') {
                                Notification::make()
                                    ->title('Match! Stock Updated.')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Discrepancy Logged')
                                    ->body('Count did not match. Manager alerted.')
                                    ->danger()
                                    ->send();
                            }
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // --- ACTION 2: MANAGER OVERRIDES ---
                ActionsAction::make('manager_override')
                    ->label('Resolve Discrepancy')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Manager Override')
                    // Strict typing: Promise this returns a string
                    ->modalDescription(fn (AuditSession $record): string => "A counted {$record->count_a}. B counted {$record->count_b}. Enter the absolute truth.")
                    ->slideOver()
                    ->form([
                        TextInput::make('manager_override_count')
                            ->label('Final Correct Stock')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->visible(fn (AuditSession $record): bool => $record->status === 'discrepancy' && auth()->user()->hasRole(['owner', 'inventory_manager']))
                    ->action(function (AuditSession $record, array $data, AdjustStockAction $adjustStock): void {
                        
                        // Strict Casting to integers
                        $finalCount = (int) $data['manager_override_count'];
                        $currentSystemStock = (int) $record->product->stock_quantity;
                        $difference = $finalCount - $currentSystemStock;

                        if ($difference !== 0) {
                            $adjustStock->execute(
                                productId: $record->product_id,
                                quantityChanged: $difference,
                                transactionType: 'audit_correction',
                                userId: auth()->id(),
                                reference: "Audit Override #{$record->id}",
                                description: "Manager override forced stock to {$finalCount}."
                            );
                        }

                        $record->update([
                            'manager_id' => auth()->id(),
                            'manager_override_count' => $finalCount,
                            'status' => 'resolved_by_override',
                        ]);
                    })
                    ->successNotificationTitle('Discrepancy officially resolved.'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    // Required for the --simple resource to know how to route itself
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAuditSessions::route('/'),
        ];
    }
}