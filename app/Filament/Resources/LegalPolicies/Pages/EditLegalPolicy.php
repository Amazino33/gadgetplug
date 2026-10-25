<?php

namespace App\Filament\Resources\LegalPolicies\Pages;

use App\Filament\Resources\LegalPolicies\LegalPolicyResource;
use App\Models\LegalPolicy;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLegalPolicy extends EditRecord
{
    protected static string $resource = LegalPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_page')
                ->label('View on website')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => route('legal.show', $this->getRecord()->slug))
                ->openUrlInNewTab(),

            DeleteAction::make(),
        ];
    }
}
