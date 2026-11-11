<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tips\Tables;

use App\Models\Tip;
use App\Models\Vendor;
use App\Support\Engagement\TipAdoption;
use App\Support\Engagement\TipCard;
use App\Support\Engagement\TipFeedback;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Livewire\Component;

class TipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (Tip $record): string => $record->title)
                    ->wrap(),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Tip::TYPE_ANNOUNCEMENT ? 'What’s new' : 'Tip')
                    ->color(fn (string $state): string => $state === Tip::TYPE_ANNOUNCEMENT ? 'warning' : 'gray'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        Tip::STATUS_ACTIVE => 'success',
                        Tip::STATUS_PAUSED => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('priority')->sortable()->alignEnd(),

                TextColumn::make('funnel')
                    ->label('Saw → Tapped → Used')
                    ->state(function (Tip $record): string {
                        $f = app(TipAdoption::class)->summary($record);
                        $used = $record->feature_key ? (string) $f['used'] : '–';

                        return "{$f['saw']} → {$f['tapped']} → {$used}";
                    })
                    ->description(function (Tip $record): ?string {
                        $already = $record->feature_key ? app(TipAdoption::class)->summary($record)['already_using'] : 0;

                        return $already > 0 ? "{$already} already using it" : null;
                    }),

                TextColumn::make('helpful')
                    ->label('👍')
                    ->state(function (Tip $record): string {
                        $f = TipFeedback::forTip($record->id);

                        return $f['percent'] === null ? '–' : "{$f['percent']}%";
                    })
                    ->description(function (Tip $record): ?string {
                        $f = TipFeedback::forTip($record->id);

                        return ($f['yes'] + $f['no']) > 0 ? "{$f['yes']} 👍 · {$f['no']} 👎" : null;
                    }),

                TextColumn::make('updated_at')->since()->sortable()->label('Changed'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Tip::STATUS_DRAFT => 'Draft',
                    Tip::STATUS_ACTIVE => 'Active',
                    Tip::STATUS_PAUSED => 'Paused',
                ]),
                SelectFilter::make('type')->options([
                    Tip::TYPE_TIP => 'Tip',
                    Tip::TYPE_ANNOUNCEMENT => 'What’s new',
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                self::previewAction(),
                self::toggleStatusAction(),
                ReplicateAction::make()
                    ->label('Duplicate')
                    ->excludeAttributes(['status', 'created_by'])
                    // Only the name changes; replicate() has copied the rest
                    // (the record data also carries the id, which must not).
                    ->mutateRecordDataUsing(fn (array $data): array => ['name' => $data['name'].' (copy)'])
                    ->beforeReplicaSaved(function (Tip $replica): void {
                        $replica->status = Tip::STATUS_DRAFT;
                        $replica->created_by = auth()->id();
                    })
                    ->after(function (Tip $replica, Tip $record): void {
                        foreach ([Tip::IMAGE_COLLECTION, Tip::VIDEO_COLLECTION, Tip::POSTER_COLLECTION] as $collection) {
                            $record->getFirstMedia($collection)?->copy($replica, $collection);
                        }
                    })
                    ->successNotificationTitle('Duplicated as a draft'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Shows the card as vendors would see it, and can open it for real in a
     * vendor's panel: ?gp_tip_preview shows it whatever its status and caps,
     * and logs nothing.
     */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview as me')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(fn (Tip $record): string => 'Preview: '.$record->name)
            ->modalWidth('md')
            ->modalContent(fn (Tip $record) => view('filament.admin.tips.phone-preview', [
                'card' => TipCard::fromTip($record, filled($record->button_label)),
            ]))
            ->schema([
                Select::make('vendor_id')
                    ->label('Open it in this business’s panel')
                    ->helperText('Shows the real pop-up on that business’s dashboard, as you. Nothing is recorded and no caps apply.')
                    ->options(fn (): array => Vendor::query()->orderBy('name')->limit(200)->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->modalSubmitActionLabel('Open in vendor panel')
            ->action(function (Tip $record, array $data, Component $livewire): void {
                $vendor = Vendor::find($data['vendor_id']);

                if (! $vendor) {
                    return;
                }

                $livewire->redirect(route('filament.vendor.pages.dashboard', [
                    'tenant' => $vendor->slug,
                    'gp_tip_preview' => $record->id,
                ]));
            });
    }

    public static function toggleStatusAction(): Action
    {
        return Action::make('toggleStatus')
            ->label(fn (Tip $record): string => $record->status === Tip::STATUS_ACTIVE ? 'Pause' : 'Activate')
            ->icon(fn (Tip $record): string => $record->status === Tip::STATUS_ACTIVE ? 'heroicon-o-pause' : 'heroicon-o-play')
            ->color(fn (Tip $record): string => $record->status === Tip::STATUS_ACTIVE ? 'warning' : 'success')
            ->requiresConfirmation(fn (Tip $record): bool => $record->status !== Tip::STATUS_ACTIVE)
            ->modalDescription('Active tips start showing to the people they target straight away.')
            ->visible(fn (Tip $record): bool => ! $record->trashed())
            ->action(function (Tip $record): void {
                $record->update([
                    'status' => $record->status === Tip::STATUS_ACTIVE ? Tip::STATUS_PAUSED : Tip::STATUS_ACTIVE,
                ]);

                Notification::make()->title($record->status === Tip::STATUS_ACTIVE ? 'Tip is live' : 'Tip paused')->success()->send();
            });
    }
}
