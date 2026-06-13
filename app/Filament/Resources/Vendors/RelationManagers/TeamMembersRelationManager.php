<?php

namespace App\Filament\Resources\Vendors\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
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

                TextColumn::make('roles.name')
                    ->label('Spatie Roles')
                    ->badge()
                    ->separator(',')
                    ->placeholder('— none —'),
            ])
            ->headerActions([])
            ->actions([
                Action::make('assign_spatie_role')
                    ->label('Permission')
                    ->icon('heroicon-o-shield-check')
                    ->color('primary')
                    ->form([
                        Select::make('roles')
                            ->label('Roles')
                            ->multiple()
                            ->options(fn () => Role::where('team_id', $this->getOwnerRecord()->id)->pluck('name', 'name'))
                            ->placeholder('Select roles to assign...'),
                    ])
                    ->fillForm(fn (User $record): array => [
                        'roles' => $record->roles
                            ->where('team_id', $this->getOwnerRecord()->id)
                            ->pluck('name')
                            ->toArray(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $vendor = $this->getOwnerRecord();
                        setPermissionsTeamId($vendor->id);
                        $record->unsetRelation('roles');
                        $record->syncRoles($data['roles'] ?? []);
                        $record->syncPermissions([]);
                    }),

                DetachAction::make()
                    ->label('Remove')
                    ->requiresConfirmation(),
            ]);
    }
}
