<?php

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\Affiliates\Schemas\AffiliateInfolist;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewAffiliate extends ViewRecord
{
    protected static string $resource = AffiliateResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AffiliateInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
