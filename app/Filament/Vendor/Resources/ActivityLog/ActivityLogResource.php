<?php

namespace App\Filament\Vendor\Resources\ActivityLog;

use App\Filament\Vendor\Resources\ActivityLog\Pages\ListActivities;
use App\Models\User;
use App\Models\VendorActivity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ActivityLogResource extends Resource
{
    protected static ?string $model = VendorActivity::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static string|null|UnitEnum   $navigationGroup = 'Settings';
    protected static ?string                $navigationLabel = 'Activity Log';
    protected static ?int                   $navigationSort  = 99;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        /** @var User $user */
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && ($user->isSuperAdmin() || $vendor->isOwner($user));
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(mixed $record): bool { return false; }
    public static function canDelete(mixed $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $vendorId = filament()->getTenant()?->id;

        return parent::getEloquentQuery()
            ->where('vendor_id', $vendorId)
            ->latest();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->width('160px'),

                TextColumn::make('causer.name')
                    ->label('By')
                    ->default('System')
                    ->searchable(),

                TextColumn::make('description')
                    ->label('Action')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state) => $state
                        ? class_basename($state)
                        : '—'
                    )
                    ->description(fn (VendorActivity $r) => $r->subject_id ? "#{$r->subject_id}" : null),

                TextColumn::make('properties')
                    ->label('Details')
                    ->formatStateUsing(function (VendorActivity $record): string {
                        $old = $record->properties->get('old');
                        $new = $record->properties->get('attributes');

                        if ($old && $new) {
                            $changes = collect($new)
                                ->map(fn ($val, $key) => isset($old[$key]) && $old[$key] !== $val
                                    ? "{$key}: {$old[$key]} → {$val}"
                                    : null
                                )
                                ->filter()
                                ->implode(', ');

                            return $changes ?: '—';
                        }

                        $props = $record->properties->except(['old', 'attributes'])->toArray();
                        return $props
                            ? collect($props)->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ')
                            : '—';
                    })
                    ->wrap()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ])
                    ->placeholder('All events'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
