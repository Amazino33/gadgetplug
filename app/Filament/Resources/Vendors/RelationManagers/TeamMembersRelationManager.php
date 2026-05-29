<?php

namespace App\Filament\Resources\Vendors\RelationManagers;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DetachAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class TeamMembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Team Members & Roles';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable(),

                TextColumn::make('pivot.role')
                    ->label('Store Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'owner'             => 'success',
                        'product_manager'   => 'info',
                        'order_manager'     => 'warning',
                        'inventory_manager' => 'primary',
                        'storekeeper'       => 'gray',
                        default             => 'gray',
                    }),

                TextColumn::make('roles.name')
                    ->label('Spatie Roles')
                    ->badge()
                    ->separator(',')
                    ->placeholder('— none —'),
            ])
            ->headerActions([])
            ->actions([
                Action::make('change_store_role')
                    ->label('Store Role')
                    ->icon('heroicon-o-identification')
                    ->color('gray')
                    ->form([
                        Select::make('role')
                            ->label('Store Role')
                            ->options([
                                'owner'             => 'Owner',
                                'product_manager'   => 'Product Manager',
                                'order_manager'     => 'Order Manager',
                                'inventory_manager' => 'Inventory Manager',
                                'storekeeper'       => 'Storekeeper',
                                'member'            => 'Member (read-only)',
                            ])
                            ->required(),
                    ])
                    ->fillForm(fn (User $record): array => [
                        'role' => $this->getOwnerRecord()->users()
                            ->where('user_id', $record->id)
                            ->first()?->pivot?->role ?? 'member',
                    ])
                    ->action(function (User $record, array $data): void {
                        $this->getOwnerRecord()->users()->updateExistingPivot($record->id, [
                            'role' => $data['role'],
                        ]);
                    })
                    ->successNotificationTitle('Store role updated'),

                Action::make('assign_spatie_role')
                    ->label('Permissions')
                    ->icon('heroicon-o-shield-check')
                    ->color('primary')
                    ->form([
                        Select::make('roles')
                            ->label('Spatie Roles')
                            ->multiple()
                            ->options(Role::pluck('name', 'name'))
                            ->placeholder('Select roles to assign…'),
                    ])
                    ->fillForm(fn (User $record): array => [
                        'roles' => $record->roles->pluck('name')->toArray(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->syncRoles($data['roles'] ?? []);
                    })
                    ->successNotificationTitle('Spatie roles updated'),

                DetachAction::make()
                    ->label('Remove')
                    ->requiresConfirmation(),
            ]);
    }
}
