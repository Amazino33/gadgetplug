<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\ImportLogs;

use App\Filament\Vendor\Resources\ImportLogs\Pages;
use App\Models\ImportLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every import this vendor's catalogue has been through, and who ran it.
 *
 * The rows have existed since imports did; nothing ever showed them. That was
 * survivable while the only person who could import was someone the vendor had
 * hired themselves — they could ask down the corridor. It stops being
 * survivable now that platform staff can onboard a catalogue on a vendor's
 * behalf: "three hundred products appeared on Tuesday and nobody here put them
 * there" is a true statement a vendor can now make, and they are owed a screen
 * that answers it rather than a support ticket.
 *
 * Read-only throughout. An import log is a record of something that already
 * happened; editing one would only make it a worse record.
 */
class ImportLogResource extends Resource
{
    protected static ?string $model = ImportLog::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon   = 'heroicon-o-clock';
    protected static string|null|UnitEnum    $navigationGroup  = 'Products';
    protected static ?string                 $navigationLabel  = 'Import History';
    protected static ?string                 $modelLabel       = 'import';
    protected static ?string                 $pluralModelLabel = 'imports';
    protected static ?int                    $navigationSort   = 4;

    /**
     * The same gate as importing itself.
     *
     * Owners pass it unconditionally (hasVendorPermission short-circuits on
     * ownership), so the person who most needs to know their catalogue was
     * rewritten always can, whether or not they do the importing themselves.
     */
    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        if ($vendor === null || $user === null) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->hasVendorPermission($vendor->id, 'import_products') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    private const STATUS_COLOURS = [
        'completed' => 'success',
        'running'   => 'warning',
        'pending'   => 'gray',
        'failed'    => 'danger',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('file_name')
                    ->label('File')
                    ->wrap()
                    ->searchable()
                    // The branch sits on the same line as the file because those
                    // two facts together are the whole of "what landed where",
                    // and the branch is the half people only think to ask about
                    // once it is already wrong.
                    ->description(fn (ImportLog $r): ?string => $r->store?->name
                        ? 'into '.$r->store->name
                        : null),

                // Not user.name: an import run by platform staff is attributed
                // to the platform, because the vendor has no way to evaluate an
                // individual name on our side and every way to hold us to it.
                TextColumn::make('actor')
                    ->label('Run by')
                    ->state(fn (ImportLog $r): string => $r->actorLabel())
                    ->badge()
                    ->color(fn (ImportLog $r): string => $r->performed_by_admin ? 'info' : 'gray')
                    ->icon(fn (ImportLog $r): ?string => $r->performed_by_admin ? 'heroicon-m-lifebuoy' : null)
                    ->description(fn (ImportLog $r): ?string => $r->performed_by_admin ? 'on your behalf' : null),

                TextColumn::make('created_count')
                    ->label('New')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('updated_count')
                    ->label('Updated')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),

                TextColumn::make('skipped_count')
                    ->label('Skipped')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),

                TextColumn::make('status')
                    ->label('Outcome')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => self::STATUS_COLOURS[$state] ?? 'gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Outcome')
                    ->options(array_combine(
                        array_keys(self::STATUS_COLOURS),
                        array_map('ucfirst', array_keys(self::STATUS_COLOURS)),
                    )),

                Filter::make('performed_by_admin')
                    ->label('Run by '.config('app.name').' support')
                    ->query(fn (Builder $query): Builder => $query->where('performed_by_admin', true)),
            ])
            ->recordActions([
                Action::make('problems')
                    ->label('Skipped rows')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->modalHeading(fn (ImportLog $r): string => 'Rows skipped from '.$r->file_name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (ImportLog $r) => view(
                        'filament.vendor.partials.import-log-problems',
                        ['log' => $r],
                    ))
                    ->visible(fn (ImportLog $r): bool => filled($r->errors)),

                Action::make('snapshot')
                    ->label('Catalogue before')
                    ->icon('heroicon-o-arrow-down-tray')
                    // The one thing a database rollback cannot give back once
                    // the import's transaction has committed, so it is offered
                    // wherever the import is visible — not only on the screen
                    // the vendor happened to be looking at when it ran.
                    ->visible(fn (ImportLog $r): bool => $r->hasSnapshot())
                    ->action(function (ImportLog $r) {
                        if (! $r->hasSnapshot()) {
                            Notification::make()
                                ->title('That snapshot is no longer on the server.')
                                ->warning()
                                ->send();

                            return null;
                        }

                        return response()->download($r->snapshot_path);
                    }),
            ])
            ->emptyStateHeading('No imports yet')
            ->emptyStateDescription('Every catalogue import shows up here — the file, the branch it landed in, and who ran it.')
            ->emptyStateIcon('heroicon-o-clock');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportLogs::route('/'),
        ];
    }
}
