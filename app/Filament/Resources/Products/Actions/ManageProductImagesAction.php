<?php

namespace App\Filament\Resources\Products\Actions;

use App\Models\Product;
use App\Services\Catalogue\ProductMediaActivity;
use Filament\Actions\Action;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

// "Manage images" on All Products: add, remove and reorder a product's photos,
// and nothing else. The first photo is the primary one.
//
// Writes media only. It is a plain Action, not an EditAction, so no product
// column is ever filled into the form or written back — the upload field saves
// its own media relationship and that is the whole of the change.
//
// Authorization is the policy's (ProductPolicy::updateMedia), checked three
// times on purpose: Filament's authorize() on mount and on call, and again in
// before() — which runs BEFORE the upload field saves anything, so a refused
// request leaves the photos untouched.
class ManageProductImagesAction
{
    public static function make(): Action
    {
        $before = [];

        return Action::make('manageImages')
            ->label('Manage images')
            ->icon('heroicon-o-photo')
            ->color('gray')
            ->modalHeading(fn (Product $record): string => 'Photos — '.$record->name)
            ->modalDescription('The first photo is the main one shown to customers. Drag to reorder. Up to 8 photos, 10 MB each.')
            ->modalSubmitActionLabel('Save photos')
            // Linked (resold) listings show their supplier's photos, so there is
            // nothing of their own to manage.
            ->visible(fn (Product $record): bool => ! $record->isLinked())
            ->authorize(fn (Product $record): bool => ! $record->isLinked() && Gate::allows('updateMedia', $record))
            ->schema([
                SpatieMediaLibraryFileUpload::make('images')
                    ->label('')
                    ->collection(ProductMediaActivity::COLLECTION)
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->maxFiles(8)
                    ->maxSize(10240)
                    ->panelLayout('grid')
                    ->getUploadedFileNameForStorageUsing(
                        fn (TemporaryUploadedFile $file): string => Str::ulid().'.'.$file->getClientOriginalExtension()
                    ),
            ])
            ->before(function (Product $record) use (&$before): void {
                // Defence in depth, and the last point before anything is saved.
                Gate::authorize('updateMedia', $record);
                abort_if($record->isLinked(), 403, 'Linked listings show their supplier\'s photos.');

                $before = ProductMediaActivity::snapshot($record);
            })
            ->action(function (Product $record) use (&$before): void {
                // The upload field has saved by now (Filament saves relationships
                // between before() and action()), so this is the "after".
                ProductMediaActivity::log($record, $before, ProductMediaActivity::snapshot($record), auth()->user());

                Notification::make()->title('Photos saved')->success()->send();
            });
    }
}
