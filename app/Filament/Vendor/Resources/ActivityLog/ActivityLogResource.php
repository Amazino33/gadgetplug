<?php

namespace App\Filament\Vendor\Resources\ActivityLog;

use App\Filament\Vendor\Resources\ActivityLog\Pages\ListActivities;
use App\Models\User;
use App\Models\VendorActivity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
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

        // Owner and super admin keep blanket access; everyone else needs the
        // permission, so oversight can be delegated to a store admin without
        // handing over the store itself.
        return $vendor && (
            $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->can('view_activity_log')
        );
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(mixed $record): bool { return false; }
    public static function canDelete(mixed $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $vendorId = filament()->getTenant()?->id;

        $query = parent::getEloquentQuery()
            ->where('vendor_id', $vendorId)
            ->with(['causer', 'store'])
            ->latest();

        /** @var User $user */
        $user = auth()->user();

        // A member assigned to specific stores sees only those stores, plus the
        // vendor-wide entries that belong to no single branch. The owner and
        // anyone unassigned sees everything — being in no store means "not
        // restricted to one", not "restricted to none".
        if ($vendorId && ! $user->isSuperAdmin() && ! filament()->getTenant()->isOwner($user)) {
            $storeIds = $user->storesForVendor((int) $vendorId)->pluck('id');

            if ($storeIds->isNotEmpty()) {
                $query->where(fn ($q) => $q->whereIn('store_id', $storeIds)->orWhereNull('store_id'));
            }
        }

        return $query;
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

                TextColumn::make('store.name')
                    ->label('Store')
                    ->placeholder('All stores')
                    ->toggleable(),

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

                // "Who did this?" is the question this page exists to answer,
                // so the people who actually appear in the feed are the options
                // — not every user on the platform.
                SelectFilter::make('causer_id')
                    ->label('Person')
                    ->options(fn () => VendorActivity::query()
                        ->where('vendor_id', filament()->getTenant()?->id)
                        ->whereNotNull('causer_id')
                        ->with('causer')
                        ->get()
                        ->pluck('causer.name', 'causer_id')
                        ->filter()
                        ->unique()
                        ->sort()
                        ->toArray())
                    ->searchable()
                    ->placeholder('Anyone'),

                SelectFilter::make('subject_type')
                    ->label('What')
                    ->options(fn () => VendorActivity::query()
                        ->where('vendor_id', filament()->getTenant()?->id)
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->pluck('subject_type', 'subject_type')
                        ->map(fn (string $t) => class_basename($t))
                        ->sort()
                        ->toArray())
                    ->placeholder('Everything'),

                SelectFilter::make('store_id')
                    ->label('Store')
                    ->relationship('store', 'name', fn ($query) => $query->where('vendor_id', filament()->getTenant()?->id))
                    ->placeholder('All stores'),

                Filter::make('when')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        if ($data['from'] ?? null)  { $out[] = 'From ' . $data['from']; }
                        if ($data['until'] ?? null) { $out[] = 'Until ' . $data['until']; }
                        return $out;
                    }),
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
