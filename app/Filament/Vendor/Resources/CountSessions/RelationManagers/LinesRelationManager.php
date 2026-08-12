<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\CountSessions\RelationManagers;

use App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\AuditSession;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// The lines of one count, with every action that applies to them.
//
// Actions come from AuditSessionResource::lineActions() rather than being
// redefined here — the owner-only and self-disposition checks live inside them,
// and a second copy would be a second place for those to go wrong.
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLines';

    protected static ?string $title = 'Counted lines';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product', 'shortageCase', 'storekeeperA', 'storekeeperB']))
            ->columns([
                ImageColumn::make('product_thumbnail')
                    ->label('')
                    ->getStateUsing(fn (AuditSession $r): ?string => $r->product?->getFirstMediaUrl('product-images', 'thumb') ?: null)
                    ->defaultImageUrl(fn () => asset('images/logo.svg'))
                    ->size(40)
                    ->rounded(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->wrap()
                    ->description(fn (AuditSession $r): string => 'SKU: '.($r->product?->sku ?? '—')),

                // The baseline frozen at count time, not today's stock — the
                // whole point of recording it.
                TextColumn::make('system_quantity')
                    ->label('System')
                    ->alignCenter()
                    ->placeholder('—'),

                TextColumn::make('counted')
                    ->label('Counted')
                    ->alignCenter()
                    ->getStateUsing(fn (AuditSession $r): int => $r->countedQuantity()),

                TextColumn::make('variance')
                    ->label('Variance')
                    ->alignCenter()
                    ->badge()
                    ->getStateUsing(function (AuditSession $r): string {
                        $diff = $r->countedVariance();

                        return $diff === null ? '—' : ($diff > 0 ? '+' : '').$diff;
                    })
                    ->color(function (AuditSession $r): string {
                        $diff = $r->countedVariance();

                        return match (true) {
                            $diff === null => 'gray',
                            $diff < 0      => 'danger',
                            $diff > 0      => 'warning',
                            default        => 'success',
                        };
                    }),

                TextColumn::make('status')
                    ->label('Line')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'              => 'Pending',
                        'verified'             => 'Verified',
                        'discrepancy'          => 'Review needed',
                        'resolved_by_override' => 'Resolved',
                        default                => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'verified'             => 'success',
                        'discrepancy'          => 'warning',
                        'resolved_by_override' => 'info',
                        default                => 'gray',
                    }),

                TextColumn::make('case_status')
                    ->label('Case')
                    ->badge()
                    ->getStateUsing(fn (AuditSession $r): string => match ($r->shortageCase?->status) {
                        'pending_disposition' => 'Awaiting decision',
                        'investigating'       => 'Investigating',
                        'charged'             => 'Charged',
                        'recovered'           => 'Recovered',
                        'written_off'         => 'Written off',
                        default               => 'Balanced',
                    })
                    ->color(fn (AuditSession $r): string => match ($r->shortageCase?->status) {
                        'pending_disposition' => 'warning',
                        'investigating'       => 'info',
                        'charged'             => 'danger',
                        'recovered'           => 'success',
                        'written_off'         => 'gray',
                        default               => 'success',
                    }),

                // Cost-derived, so behind the same gate as every other cost surface.
                TextColumn::make('cost_component')
                    ->label('At cost (₦)')
                    ->alignRight()
                    ->visible(fn (): bool => ProductForm::canSeeCostPrice())
                    ->getStateUsing(function (AuditSession $r): string {
                        $case = $r->shortageCase;

                        return $case ? '₦'.number_format((float) $case->cost_component, 2) : '—';
                    }),
            ])
            ->filters([
                // The default view on a big count: only the lines that need a human.
                Filter::make('needs_attention')
                    ->label('Needs attention')
                    ->query(fn ($query) => $query->where(function ($q) {
                        $q->where('status', 'discrepancy')
                            ->orWhereHas('shortageCase', fn ($c) => $c->whereIn('status', ['pending_disposition', 'investigating']));
                    }))
                    ->default(),

                SelectFilter::make('status')
                    ->label('Line status')
                    ->options([
                        'pending'              => 'Pending',
                        'verified'             => 'Verified',
                        'discrepancy'          => 'Review needed',
                        'resolved_by_override' => 'Resolved',
                    ]),
            ])
            ->recordActions(AuditSessionResource::lineActions())
            ->defaultSort('id')
            ->emptyStateHeading('Nothing to audit here')
            ->emptyStateDescription('Every line in this count balanced, or none is linked to it.');
    }

    // Read-only relation: lines come from counting, never from typing them in here.
    public function canCreate(): bool { return false; }

    public function isReadOnly(): bool { return false; }
}
