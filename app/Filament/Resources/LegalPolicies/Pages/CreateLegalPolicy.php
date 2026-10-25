<?php

namespace App\Filament\Resources\LegalPolicies\Pages;

use App\Filament\Resources\LegalPolicies\LegalPolicyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLegalPolicy extends CreateRecord
{
    protected static string $resource = LegalPolicyResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
