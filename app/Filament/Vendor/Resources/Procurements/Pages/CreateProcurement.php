<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Filament\Vendor\Resources\Procurements\Schemas\ProcurementForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;

// The single procurement creation path — a plain Schema form (repeater, no
// wizard), sharing ProcurementForm with EditProcurement the same way
// ProductForm::configure() is shared across the Products resource's pages.
//
// This file replaces the previous wizard-based CreateProcurement page class
// (git history has the original). The old ProcurementWizardController and
// its session-driven /procurement/* routes + Blade views have been removed
// entirely — every procurement now goes through this auto-pricing form,
// there is no manual-pricing path left.
class CreateProcurement extends CreateRecord
{
    protected static string $resource = ProcurementResource::class;

    public function form(Schema $schema): Schema
    {
        return ProcurementForm::configure($schema);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['vendor_id'] = filament()->getTenant()->id;
        $data['status'] = 'draft';
        $data['total_cost'] = 0;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->recalculate();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Procurement created — add items, then submit for logistics when ready.';
    }

    protected function getRedirectUrl(): string
    {
        return ProcurementResource::getUrl('edit', ['record' => $this->record]);
    }
}
