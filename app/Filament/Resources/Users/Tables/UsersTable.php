<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Enables the full-width responsive card layout on screens below lg (mobile and tablet)
            // matching the dispatch card and products mobile pattern, eliminating horizontal scroll.
            ->extraAttributes(['class' => 'gp-card-table'])
            ->columns([
                // Mobile and tablet view: stacked vertical card with zero horizontal scrolling.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('filament.admin.users.mobile-card')
                    ->disabledClick()
                    ->hiddenFrom('lg'),

                // Desktop columns: full table layout visible from large screens up.
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (User $record): ?string => $record->phone ?: null)
                    ->visibleFrom('lg'),

                TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-m-envelope')
                    ->visibleFrom('lg'),

                TextColumn::make('stores')
                    ->label('Store / Branch')
                    ->getStateUsing(function (User $record): string {
                        $stores = $record->resolvedStores();
                        if ($stores->isEmpty()) {
                            return '—';
                        }
                        return $stores->pluck('name')->join(', ');
                    })
                    ->description(function (User $record): ?string {
                        $vendors = $record->vendors()->pluck('name')->join(', ');
                        return $vendors ? 'Shop: ' . $vendors : null;
                    })
                    ->icon(fn (User $record) => $record->resolvedStores()->isNotEmpty() ? 'heroicon-m-building-storefront' : null)
                    ->wrap()
                    ->visibleFrom('lg'),

                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->getStateUsing(function (User $record): array {
                        return $record->resolvedRolesWithContext()->map(function ($role) {
                            return $role['context'] ? "{$role['name']} ({$role['context']})" : $role['name'];
                        })->toArray();
                    })
                    ->color(function (string $state): string {
                        if (str_contains($state, 'Super Admin')) {
                            return 'danger';
                        }
                        if (str_contains($state, 'Store Owner')) {
                            return 'success';
                        }
                        if (str_contains($state, 'Catalogue Editor')) {
                            return 'warning';
                        }
                        if (str_contains($state, 'Customer')) {
                            return 'gray';
                        }
                        return 'info';
                    })
                    ->wrap()
                    ->visibleFrom('lg'),

                TextColumn::make('created_at')
                    ->label('Registered')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('role_type')
                    ->label('User Type')
                    ->options([
                        'super_admin' => 'Super Admin',
                        'owner' => 'Store Owner',
                        'staff' => 'Store Staff',
                        'customer' => 'Customer',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $type = $data['value'] ?? null;
                        if (! $type) {
                            return $query;
                        }

                        return match ($type) {
                            'super_admin' => $query->whereExists(function ($sub) {
                                $sub->selectRaw(1)
                                    ->from('model_has_roles')
                                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                                    ->whereColumn('model_has_roles.model_id', 'users.id')
                                    ->whereNull('model_has_roles.team_id')
                                    ->where('roles.name', 'super_admin');
                            }),
                            'owner' => $query->whereHas('ownedVendors'),
                            'staff' => $query->where(function ($sub) {
                                $sub->whereHas('memberVendors')
                                    ->orWhereHas('stores');
                            })->whereDoesntHave('ownedVendors'),
                            'customer' => $query->whereDoesntHave('ownedVendors')
                                ->whereDoesntHave('memberVendors')
                                ->whereDoesntHave('stores')
                                ->whereNotExists(function ($sub) {
                                    $sub->selectRaw(1)
                                        ->from('model_has_roles')
                                        ->whereColumn('model_has_roles.model_id', 'users.id');
                                }),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
