<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\PosSale;
use App\Models\VendorReceiptSetting;
use Filament\Forms\Components\FileUpload;
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
    protected static string|null|\UnitEnum   $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 2;

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
            'show_qr', 'qr_caption', 'banner_image', 'banner_link', 'cta_label', 'cta_link',
            'loyalty_enabled', 'loyalty_goal', 'loyalty_reward_text',
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
            'show_qr'              => $settings->show_qr ?? true,
            'loyalty_enabled'      => $settings->loyalty_enabled ?? false,
            'loyalty_goal'         => $settings->loyalty_goal ?? 10,
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

                Section::make('QR code')
                    ->description('Prints a code the customer scans to open their own copy of the receipt on their phone, where they can save it as a PDF.')
                    ->schema([
                        Toggle::make('show_qr')
                            ->label('Print a QR code on the receipt')
                            ->helperText('The link is unguessable, and the customer\'s name and phone are never shown on that page.'),

                        TextInput::make('qr_caption')
                            ->label('Words under the code')
                            ->maxLength(60)
                            ->placeholder('Scan for your receipt'),
                    ])
                    ->columns(2),

                Section::make('On the customer\'s page')
                    ->description('What the customer sees after scanning — your promo and where you want to send them.')
                    ->schema([
                        FileUpload::make('banner_image')
                            ->label('Banner image')
                            ->image()
                            ->directory('receipt-banners')
                            ->maxSize(2048)
                            ->helperText('Wide and short works best — roughly 3:1. Shown full width above the buttons.'),

                        TextInput::make('banner_link')
                            ->label('Banner links to')
                            ->url()->maxLength(300)
                            ->placeholder('https://wa.me/2348012345678'),

                        TextInput::make('cta_label')
                            ->label('Button text')
                            ->maxLength(40)
                            ->placeholder('Chat with us on WhatsApp'),

                        TextInput::make('cta_link')
                            ->label('Button links to')
                            ->url()->maxLength(300),
                    ])
                    ->columns(2),

                Section::make('Loyalty card')
                    ->description('A card the customer marks on their receipt page. Progress is counted from their real purchase history at your store, so the number they see is one you can stand behind.')
                    ->schema([
                        Toggle::make('loyalty_enabled')
                            ->label('Offer a loyalty card')
                            ->live(),

                        TextInput::make('loyalty_goal')
                            ->label('Purchases needed')
                            ->numeric()->minValue(2)->maxValue(50)->default(10)
                            ->visible(fn ($get) => $get('loyalty_enabled')),

                        TextInput::make('loyalty_reward_text')
                            ->label('What they earn')
                            ->maxLength(80)
                            ->placeholder('a 10% discount')
                            ->helperText('Written into the sentence "only 3 more to earn ___", so a short phrase reads best.')
                            ->visible(fn ($get) => $get('loyalty_enabled'))
                            ->columnSpanFull(),
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

                'show_qr'              => (bool) ($data['show_qr'] ?? true),
                'qr_caption'           => $data['qr_caption'] ?: null,
                'banner_image'         => $data['banner_image'] ?: null,
                'banner_link'          => $data['banner_link'] ?: null,
                'cta_label'            => $data['cta_label'] ?: null,
                'cta_link'             => $data['cta_link'] ?: null,

                'loyalty_enabled'      => (bool) ($data['loyalty_enabled'] ?? false),
                'loyalty_goal'         => (int) ($data['loyalty_goal'] ?? 10),
                'loyalty_reward_text'  => $data['loyalty_reward_text'] ?: null,
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
