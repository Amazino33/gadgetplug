<?php

namespace App\Filament\Vendor\Resources\CountSessions\Widgets;

use App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock take tasks on the Audit Sessions page, beside the inventory counts:
 * one row per task whose results have been approved, in the same columns.
 * A row opens its audit lines.
 *
 * A separate table rather than rows in the inventory-count list, because those
 * rows are blind-count sessions — and a completed one also decides when that
 * counter is next due to count, and what an account close may adopt.
 */
class StockTakeAudits extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Stock takes';

    public function table(Table $table): Table
    {
        $vendorId = filament()->getTenant()?->id;

        return $table
            ->query(fn () => StockTakeTask::query()
                ->where('vendor_id', $vendorId)
                ->whereHas('auditLines')
                ->with(['assignee:id,name', 'stockTake:id,reference,name', 'store:id,name'])
                ->withCount('auditLines')
                ->withSum('auditLines', 'loss_value'))
            ->defaultSort('submitted_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No approved stock takes yet')
            ->columns([
                TextColumn::make('submitted_at')->label('Counted')->dateTime('d M Y, g:ia', 'Africa/Lagos')->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (StockTakeTask $record) => ($record->isRecount() ? 'Stock take recount' : 'Stock take') . ' · ' . $record->stockTake?->reference)
                    ->description(fn (StockTakeTask $record) => $record->stockTake?->name),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        StockTakeTask::STATUS_APPROVED           => 'Completed',
                        StockTakeTask::STATUS_PARTIALLY_APPROVED => 'Partly recounted',
                        default                                  => 'Being decided',
                    })
                    ->color(fn ($state) => $state === StockTakeTask::STATUS_APPROVED ? 'success' : 'warning'),
                TextColumn::make('assignee.name')->label('Counted by')->description(fn (StockTakeTask $record) => $record->store?->name),
                TextColumn::make('audit_lines_count')->label('Products')->alignCenter(),
                TextColumn::make('variances')
                    ->label('Variances')
                    ->alignCenter()
                    ->badge()
                    ->state(fn (StockTakeTask $record) => $record->auditLines()->where('status', 'resolved_by_override')->count())
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('shortfall')
                    ->label('Shortfall?')
                    ->badge()
                    ->state(fn (StockTakeTask $record) => (float) $record->audit_lines_sum_loss_value > 0 ? 'Yes' : '—')
                    ->color(fn ($state) => $state === 'Yes' ? 'danger' : 'gray'),
                TextColumn::make('audit_lines_sum_loss_value')
                    ->label('Shortage (₦)')
                    ->alignRight()
                    ->visible(fn () => ProductForm::canSeeCostPrice())
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? '₦' . number_format((float) $state, 2) : '—')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('unresolved')
                    ->label('Unresolved')
                    ->alignCenter()
                    ->badge()
                    ->state(fn (StockTakeTask $record) => StockTakeResult::query()
                        ->where('stock_take_task_id', $record->id)
                        ->whereIn('status', [StockTakeResult::STATUS_PENDING, StockTakeResult::STATUS_SENT_TO_RECOUNT])
                        ->count())
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
            ])
            ->recordUrl(fn (StockTakeTask $record) => AuditSessionResource::getUrl('index', [
                'tableFilters' => ['stock_take_task' => ['value' => $record->id]],
            ]));
    }
}
