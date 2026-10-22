@php
    // "Take photo" / "Choose from gallery" above the Manage images upload, and
    // the per-photo "Main photo" label and move buttons (photo-tools-script).
    // Everything goes through the upload field itself: new files are handed to
    // its FilePond, a move calls the same reorderUploadedFiles method its own
    // drag-to-reorder calls. Nothing is saved until "Save photos".
    $uploadField = $getContainer()->getFlatFields(withHidden: true)['images'] ?? null;
@endphp

@if ($uploadField)
    <div x-data="gpPhotoTools" data-field-key="{{ $uploadField->getKey() }}" class="gp-photo-sources">
        <button type="button" x-on:click="takePhoto()" class="gp-photo-source">
            <x-filament::icon icon="heroicon-o-camera" class="gp-photo-source-icon" />
            Take photo
        </button>

        <button type="button" x-on:click="chooseFromGallery()" class="gp-photo-source">
            <x-filament::icon icon="heroicon-o-photo" class="gp-photo-source-icon" />
            Choose from gallery
        </button>
    </div>
@endif
