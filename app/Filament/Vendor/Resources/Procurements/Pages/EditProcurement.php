<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Actions\Procurement\ReconcileProcurementAction;
use App\Actions\Procurement\SubmitProcurementForLogisticsAction;
use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Filament\Vendor\Resources\Procurements\Schemas\ProcurementForm;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Override;
use Throwable;

class EditProcurement extends EditRecord
{
    protected static string $resource = ProcurementResource::class;

    public function form(Schema $schema): Schema
    {
        return ProcurementForm::configure($schema);
    }

    #[Override]
    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return [
            Action::make('submit_for_logistics')
                ->label('Submit for Logistics')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->size('lg')
                ->requiresConfirmation()
                ->modalHeading('Submit for logistics?')
                ->modalDescription(fn () => "This provisionally prices all {$this->record->items()->count()} line(s), restocks the products, and sends {$this->record->reference} to logistics. You won't be able to edit the lines afterwards.")
                ->visible(fn () => $this->record->isDraft() && $user->hasVendorPermission($vendor->id, 'submit_procurement'))
                ->action(function (SubmitProcurementForLogisticsAction $action) {
                    try {
                        $action->execute($this->record);
                        Notification::make()->title('Submitted for logistics — provisional pricing applied, stock is live.')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (Throwable $e) {
                        Notification::make()->title('Error: '.$e->getMessage())->danger()->send();
                    }
                }),

            Action::make('reconcile')
                ->label('Reconcile')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->size('lg')
                ->requiresConfirmation()
                ->modalHeading('Reconcile logistics?')
                ->modalDescription(function () {
                    $record = $this->record;
                    $tripValue = $record->items()->selectRaw('SUM(quantity * unit_cost) as total')->value('total') ?? 0;
                    $factor = $tripValue > 0 ? 1 + ((float) $record->logistics_cost / $tripValue) : 1;

                    return 'Logistics cost ₦'.number_format((float) $record->logistics_cost, 2)
                        .' on a trip value of ₦'.number_format($tripValue, 2)
                        .' (factor '.number_format($factor, 4).'). This finalizes landed cost and suggested price for all lines, and updates any non-overridden product prices.';
                })
                ->visible(fn () => $this->record->isAwaitingLogistics()
                    && $this->record->logistics_cost !== null
                    && $user->hasVendorPermission($vendor->id, 'record_procurement_logistics'))
                ->action(function (ReconcileProcurementAction $action) {
                    try {
                        $action->execute($this->record);
                        Notification::make()->title('Reconciled — final pricing applied.')->success()->send();
                        $this->refreshFormData(['status', 'reconciled_at', 'logistics_recorded_by']);
                    } catch (Throwable $e) {
                        Notification::make()->title('Error: '.$e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Saved.';
    }

    protected function afterSave(): void
    {
        // Keeps total_cost/payment_status in sync with the (possibly just
        // edited) line items and amount_paid — same recalculation Procurement
        // has always used, just now also triggered on every edit-save, not
        // only at creation.
        $this->record->recalculate();
    }
}
