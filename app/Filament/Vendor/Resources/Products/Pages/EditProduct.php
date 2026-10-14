<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\Pages\Concerns\ConfiguresProductPage;
use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use ConfiguresProductPage;

    protected static string $resource = ProductResource::class;

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
            // Draft/Published lives here rather than in the form: it is a
            // decision about the product, made on its own, not a field to fill
            // in alongside the price. (The create form keeps it as a field,
            // since a new product has no header to put it in.) Hidden while
            // archived - Restore is the way back from there.
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === 'draft')
                ->action(fn () => $this->setStatus('published', 'Product published.')),

            Action::make('unpublish')
                ->label('Move to draft')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === 'published')
                ->action(fn () => $this->setStatus('draft', 'Product moved to draft.')),

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

    private function setStatus(string $status, string $title): void
    {
        $this->record->update(['status' => $status]);
        $this->refreshFormData(['status']);

        Notification::make()->title($title)->success()->send();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Which unit the till opens the product in, as the form's radio
        // expresses it: 'base', or 'unit:{name}' for a bigger one.
        $default = $this->record->units()->find($this->record->default_unit_id);

        $data['default_unit_choice'] = ($default && ! $default->is_base) ? 'unit:'.$default->name : 'base';

        $data['images'] = $this->record
            ->getMedia('product-images')
            ->pluck('uuid')
            ->toArray();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ProductForm::stripTransientFields($data);
    }

    protected function afterSave(): void
    {
        $this->syncDefaultUnit();
    }
}
