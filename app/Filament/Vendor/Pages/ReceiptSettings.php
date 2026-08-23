<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\PosSale;
use App\Models\VendorReceiptSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ReceiptSettings extends Page
{
    use InteractsWithForms;

    protected string $view = 'filament.vendor.pages.receipt-settings';

    protected static ?string $navigationLabel = 'Receipt Settings';
    protected static ?string $title           = 'Receipt Settings';
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;
    protected static string|null|\UnitEnum   $navigationGroup = 'Store';
    protected static ?int $navigationSort = 11;

    public ?array $data = [];

    // Whoever configures the store's paperwork, not whoever stands at the till.
    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return false;
        }

        return $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'edit_vendor');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = VendorReceiptSetting::forVendor(filament()->getTenant());

        $this->form->fill($settings->only([
            'header_name', 'header_tagline', 'header_address', 'header_phone', 'header_extra',
            'show_logo', 'header_alignment',
            'show_receipt_number', 'show_cashier', 'show_customer', 'show_datetime', 'show_item_unit_price',
            'footer_text', 'footer_alignment', 'feed_lines',
        ]) + [
            // firstOrNew gives an unsaved model, so column defaults are not
            // applied yet — spell them out so the form never opens blank.
            'header_alignment'     => $settings->header_alignment ?? 'center',
            'footer_alignment'     => $settings->footer_alignment ?? 'center',
            'show_receipt_number'  => $settings->show_receipt_number ?? true,
            'show_cashier'         => $settings->show_cashier ?? true,
            'show_customer'        => $settings->show_customer ?? true,
            'show_datetime'        => $settings->show_datetime ?? true,
            'show_item_unit_price' => $settings->show_item_unit_price ?? true,
            'show_logo'            => $settings->show_logo ?? false,
            'feed_lines'           => $settings->feed_lines ?? 2,
        ]);
    }

    public function form(Schema $form): Schema
    {
        $alignments = ['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'];

        return $form
            ->components([
                Section::make('Header')
                    ->description('Printed at the top of every receipt, above the items.')
                    ->schema([
                        TextInput::make('header_name')
                            ->label('Name on the receipt')
                            ->maxLength(120)
                            ->placeholder(filament()->getTenant()->name)
                            ->helperText('Leave empty to use your store name.'),

                        TextInput::make('header_tagline')->label('Tagline')->maxLength(120)
                            ->placeholder('e.g. Phones, laptops and accessories'),

                        TextInput::make('header_address')->label('Address')->maxLength(160),

                        TextInput::make('header_phone')->label('Phone')->maxLength(60),

                        TextInput::make('header_extra')->label('Extra line')->maxLength(120)
                            ->helperText('Anything else that must appear — RC number, TIN, a second phone.'),

                        Select::make('header_alignment')->label('Header alignment')
                            ->options($alignments)->default('center')->required(),

                        Toggle::make('show_logo')
                            ->label('Print your store logo')
                            ->helperText('Logos print as solid black on thermal paper. A simple, high-contrast mark works; a photo will not.'),
                    ])
                    ->columns(2),

                Section::make('What the receipt shows')
                    ->description('Every line you turn off is paper saved on each sale.')
                    ->schema([
                        Toggle::make('show_receipt_number')->label('Receipt number'),
                        Toggle::make('show_datetime')->label('Date and time'),
                        Toggle::make('show_cashier')->label('Cashier name'),
                        Toggle::make('show_customer')->label('Customer name'),
                        Toggle::make('show_item_unit_price')->label('Unit price under each item'),
                    ])
                    ->columns(2),

                Section::make('Footer')
                    ->schema([
                        Textarea::make('footer_text')
                            ->label('Footer message')
                            ->rows(4)
                            ->maxLength(400)
                            ->placeholder("Thank you for shopping with us\nGoods sold in good condition\nExchange within 7 days with receipt")
                            ->helperText('Each new line prints as its own line on the paper.'),

                        Select::make('footer_alignment')->label('Footer alignment')
                            ->options($alignments)->default('center')->required(),

                        TextInput::make('feed_lines')
                            ->label('Blank lines after the footer')
                            ->numeric()->minValue(0)->maxValue(10)->default(2)
                            ->helperText('Feeds the paper so your cut falls clear of the text.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data   = $this->form->getState();
        $vendor = filament()->getTenant();

        VendorReceiptSetting::updateOrCreate(
            ['vendor_id' => $vendor->id],
            [
                'header_name'          => $data['header_name'] ?: null,
                'header_tagline'       => $data['header_tagline'] ?: null,
                'header_address'       => $data['header_address'] ?: null,
                'header_phone'         => $data['header_phone'] ?: null,
                'header_extra'         => $data['header_extra'] ?: null,
                'show_logo'            => (bool) ($data['show_logo'] ?? false),
                'header_alignment'     => $data['header_alignment'] ?? 'center',
                'show_receipt_number'  => (bool) ($data['show_receipt_number'] ?? true),
                'show_cashier'         => (bool) ($data['show_cashier'] ?? true),
                'show_customer'        => (bool) ($data['show_customer'] ?? true),
                'show_datetime'        => (bool) ($data['show_datetime'] ?? true),
                'show_item_unit_price' => (bool) ($data['show_item_unit_price'] ?? true),
                'footer_text'          => $data['footer_text'] ?: null,
                'footer_alignment'     => $data['footer_alignment'] ?? 'center',
                'feed_lines'           => (int) ($data['feed_lines'] ?? 2),
            ]
        );

        Notification::make()->title('Receipt settings saved')->success()->send();
    }

    /** The most recent sale, so "See a real receipt" previews actual paper. */
    public function getPreviewSaleId(): ?int
    {
        return PosSale::where('vendor_id', filament()->getTenant()->id)
            ->latest('id')
            ->value('id');
    }
}
