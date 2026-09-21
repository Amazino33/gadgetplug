<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Filament\Vendor\Pages\HelpCenter;
use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use App\Services\ActiveStore;
use App\Services\Procurement\ProcurementReview;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProcurements extends ListRecords
{
    protected static string $resource = ProcurementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new_procurement')
                ->label('New Procurement')
                ->icon('heroicon-o-plus')
                ->url(route('procurement.create'))
                ->color('warning')
                // Tour hook. The guided tour tells the vendor to press this
                // exact button, so it needs a name that survives restyling.
                ->extraAttributes(['data-tour' => 'new-procurement']),

            HelpCenter::tourAction('record-procurement'),

            HelpCenter::helpAction('how-do-i-record-a-procurement'),
        ];
    }

    /**
     * Receive a delivery without leaving the list.
     *
     * A page action taking the record as an argument, rather than a table row
     * action: the button lives inside the expanded panel, which is ordinary
     * markup and not the row's action slot, and mounting a page action by name
     * is the one route there that does not depend on how Filament happens to
     * key its rows.
     *
     * The record is re-read and re-authorised here rather than trusted from
     * the argument. It arrives from the browser, so it is a request, not a
     * fact.
     */
    public function approveFromListAction(): Action
    {
        return Action::make('approveFromList')
            ->requiresConfirmation()
            ->modalHeading('Approve Procurement')
            ->modalDescription(function (array $arguments): string {
                $record = $this->procurementFromArguments($arguments);

                if (! $record) {
                    return 'That delivery is no longer available.';
                }

                return sprintf(
                    'Receiving %s will put %d units into %s at a total of %s, and update cost/selling prices.',
                    $record->reference,
                    $record->verifiedQuantity(),
                    $record->store->name ?? 'the default store',
                    "\u{20a6}" . number_format($record->verifiedTotal(), 2),
                );
            })
            ->action(function (array $arguments, ProcurementReview $review): void {
                $record = $this->procurementFromArguments($arguments);

                if (! $record) {
                    Notification::make()->title('That delivery is no longer available.')->danger()->send();

                    return;
                }

                try {
                    $review->approve($record, auth()->user(), ActiveStore::currentId());

                    Notification::make()->title('Procurement Approved, Inventory Updated.')->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('Error: ' . $e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * The delivery named by a mounted action, or null.
     *
     * Resolved through the resource's own scoped query, so an id belonging to
     * another vendor, or to a branch this person cannot reach, comes back as
     * null rather than as somebody else's delivery.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function procurementFromArguments(array $arguments): ?Procurement
    {
        $id = $arguments['record'] ?? null;

        if ($id === null) {
            return null;
        }

        return ProcurementResource::getEloquentQuery()
            ->with(['items.product', 'items.corrections', 'store'])
            ->find((int) $id);
    }
}
