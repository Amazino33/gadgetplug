<?php

namespace App\Filament\Vendor\Resources\Products\Tables;

use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use App\Models\AuditSession;
use App\Models\Product;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\BulkActionGroup as ActionsBulkActionGroup;
use Filament\Actions\DeleteBulkAction as ActionsDeleteBulkAction;
use Filament\Actions\EditAction as ActionsEditAction;
// 1. FIXED IMPORT: We must use Tables\Actions\Action, not the standalone Action
use Filament\Tables\Actions\Action; 
use Filament\Forms\Components\TextInput;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(), 

                TextColumn::make('category.name')
                    ->sortable(),

                TextColumn::make('price')
                    ->money('NGN')
                    ->sortable(),

                TextColumn::make('stock_quantity')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('brand')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            // 2. FIXED PLACEMENT: Row-level actions go here!
            ->recordActions([
                ActionsEditAction::make(),
                
                ActionsAction::make('start_audit')
                    ->label('Start Blind Count')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Initiate Inventory Audit')
                    ->modalDescription('Enter your physical count. A second storekeeper will verify this.')
                    ->slideOver()
                    ->form([
                        TextInput::make('count_a')
                            ->label('My Physical Count')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->visible(fn (?Product $record): bool => $record !== null && !AuditSession::where('product_id', $record->id)->where('status', 'pending')->exists())
                    ->action(function (Product $record, array $data): void {
                        AuditSession::create([
                            'vendor_id' => $record->vendor_id,
                            'product_id' => $record->id,
                            'storekeeper_a_id' => auth()->id(),
                            'count_a' => $data['count_a'],
                            'status' => 'pending',
                        ]);
                    })
                    ->successNotificationTitle('Audit Started, Waiting for Storekeeper B to verify.'),
            ])
            ->toolbarActions([
                ActionsBulkActionGroup::make([
                    ActionsDeleteBulkAction::make(),
                ]),
            ]);
    }
}