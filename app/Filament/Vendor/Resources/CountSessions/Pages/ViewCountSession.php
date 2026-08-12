<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\CountSessions\Pages;

use App\Filament\Vendor\Resources\CountSessions\CountSessionResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\AuditSession;
use App\Models\BlindCountSession;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

// One count, and everything that came of it.
//
// The lines and their actions already live on the Audit Sessions screen, so this
// page summarises the session and links through rather than duplicating that
// table — two copies of the same actions would be two places to keep correct.
class ViewCountSession extends ViewRecord
{
    protected static string $resource = CountSessionResource::class;

    public function getTitle(): string
    {
        return 'Count of '.$this->record->created_at->format('d M Y, g:ia');
    }

    public function infolist(Schema $schema): Schema
    {
        /** @var BlindCountSession $session */
        $session = $this->record;
        $session->loadMissing(['auditLines.product', 'auditLines.shortageCase', 'storekeeperA', 'storekeeperB']);

        return $schema->schema([
            Section::make('The count')->schema([
                Placeholder::make('counted_by')->label('Counted by')
                    ->content($session->storekeeperA?->name ?? '—'),

                Placeholder::make('verified_by')->label('Verified by')
                    ->content($session->storekeeperB?->name ?? 'Solo count — no second counter'),

                Placeholder::make('status')->label('Status')
                    ->content(match ($session->status) {
                        'a_counting' => 'First count still in progress',
                        'b_counting' => 'Waiting for a second counter',
                        'completed'  => 'Completed',
                        default      => ucfirst($session->status),
                    }),

                Placeholder::make('products')->label('Products counted')
                    ->content((string) count($session->product_order ?? [])),
            ])->columns(2),

            Section::make('What it found')->schema([
                Placeholder::make('variances')->label('Lines with a variance')
                    ->content((string) $session->varianceLines()->count()),

                Placeholder::make('unresolved')->label('Still needing a decision')
                    ->content((string) $session->unresolvedCount()),

                Placeholder::make('shortage_value')->label('Variance at cost')
                    // Cost-derived — same gate as everywhere else.
                    ->visible(fn (): bool => ProductForm::canSeeCostPrice())
                    ->content('₦'.number_format($session->shortageValueAtCost(), 2)),
            ])->columns(3),

            Section::make('Lines')->schema([
                Placeholder::make('lines')->label('')
                    ->content(fn (): HtmlString => new HtmlString($this->linesTable($session))),
            ]),
        ]);
    }

    private function linesTable(BlindCountSession $session): string
    {
        if ($session->auditLines->isEmpty()) {
            return '<p class="text-sm text-gray-500 dark:text-gray-400">'
                .'No lines are linked to this count. Counts taken before sessions and lines were linked '
                .'show their results on the Audit Sessions screen instead.</p>';
        }

        $showCost = ProductForm::canSeeCostPrice();

        $rows = '';

        foreach ($session->auditLines as $line) {
            $variance = $line->countedVariance();
            $case     = $line->shortageCase;

            $varianceLabel = $variance === null
                ? '—'
                : ($variance > 0 ? '+'.$variance : (string) $variance);

            $varianceColour = match (true) {
                $variance === null => 'text-gray-400',
                $variance < 0      => 'text-red-600 dark:text-red-400',
                $variance > 0      => 'text-amber-600 dark:text-amber-400',
                default            => 'text-green-600 dark:text-green-400',
            };

            $caseLabel = match ($case?->status) {
                'pending_disposition' => 'Awaiting decision',
                'investigating'       => 'Investigating',
                'charged'             => 'Charged',
                'recovered'           => 'Recovered',
                'written_off'         => 'Written off',
                default               => 'Balanced',
            };

            $costCell = $showCost && $case
                ? '<td class="px-3 py-2 text-right">₦'.number_format((float) $case->cost_component, 2).'</td>'
                : ($showCost ? '<td class="px-3 py-2 text-right text-gray-400">—</td>' : '');

            $rows .= '<tr class="border-b border-gray-100 dark:border-gray-800">'
                .'<td class="px-3 py-2">'.e($line->product?->name ?? '—').'</td>'
                .'<td class="px-3 py-2 text-center">'.e((string) ($line->system_quantity ?? '—')).'</td>'
                .'<td class="px-3 py-2 text-center">'.e((string) $line->countedQuantity()).'</td>'
                .'<td class="px-3 py-2 text-center font-semibold '.$varianceColour.'">'.e($varianceLabel).'</td>'
                .$costCell
                .'<td class="px-3 py-2">'.e($caseLabel).'</td>'
                .'</tr>';
        }

        $costHeader = $showCost ? '<th class="px-3 py-2 text-right font-medium">At cost</th>' : '';

        return '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">'
            .'<table class="w-full text-sm">'
            .'<thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">'
            .'<tr>'
            .'<th class="px-3 py-2 text-left font-medium">Product</th>'
            .'<th class="px-3 py-2 text-center font-medium">System</th>'
            .'<th class="px-3 py-2 text-center font-medium">Counted</th>'
            .'<th class="px-3 py-2 text-center font-medium">Variance</th>'
            .$costHeader
            .'<th class="px-3 py-2 text-left font-medium">Case</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .'<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">'
            .'Resolve disputes and decide cases on the Audit Sessions screen — the actions live there so there is only one place they can be done.</p>';
    }
}
