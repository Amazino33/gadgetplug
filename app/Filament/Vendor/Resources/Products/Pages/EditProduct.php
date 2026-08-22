<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    // Matches CreateProduct — see the note there for why the panel default
    // is too narrow for this particular form.
    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }

    // Matches CreateProduct — same long form, same reason.
    public function areFormActionsSticky(): bool
    {
        return true;
    }

    // Archiving lives here rather than as a third button in the form's status
    // control: it is a different kind of decision from draft-vs-live (it pulls
    // the product out of every channel and report at once), it deserves a
    // confirmation, and it is meaningless while creating a product — which is
    // why CreateProduct has no equivalent.
    //
    // Reaching this page already requires edit_products (ProductResource::
    // canEdit), so neither action needs its own permission check.
    protected function getHeaderActions(): array
    {
        return [
            Action::make('archive')
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Archive this product?')
                ->modalDescription('It stops showing on the storefront and at the till, and drops out of restock and inventory reports. Its stock and sales history stay intact, and you can restore it at any time.')
                ->modalSubmitActionLabel('Archive')
                ->visible(fn (): bool => $this->record->status !== 'archived')
                ->action(function (): void {
                    $this->record->update(['status' => 'archived']);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title('Product archived.')
                        ->body('It is now hidden from the storefront and the till.')
                        ->success()
                        ->send();
                }),

            // Deliberately returns to draft, never straight to published — a
            // product coming back from the archive gets a deliberate look
            // before it is live to customers again.
            Action::make('restore')
                ->label('Restore')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Restore this product?')
                ->modalDescription('It comes back as a draft, so nothing goes live until you publish it.')
                ->modalSubmitActionLabel('Restore as draft')
                ->visible(fn (): bool => $this->record->status === 'archived')
                ->action(function (): void {
                    $this->record->update(['status' => 'draft']);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title('Product restored as a draft.')
                        ->body('Publish it when you are ready for customers to see it.')
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['images'] = $this->record
            ->getMedia('product-images')
            ->pluck('uuid')
            ->toArray();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ProductForm::stripTransientAiFields($data);
    }
}
