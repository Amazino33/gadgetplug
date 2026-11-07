<?php

namespace App\Filament\Vendor\Pages;

use App\Services\Products\SkuGenerator;
use Filament\Forms\Components\Placeholder;
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
use Illuminate\Validation\ValidationException;

class StoreProfile extends Page
{
    protected static string|null|\UnitEnum $navigationGroup = 'Settings';

    use InteractsWithForms;

    protected string $view = 'filament.vendor.pages.store-profile';

    protected static ?string $navigationLabel = 'Store Profile';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    /** Nigeria's 36 states and the FCT, for the public location line. */
    private const STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue',
        'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu',
        'FCT - Abuja', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina',
        'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo',
        'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
    ];

    public function mount(): void
    {
        $vendor = filament()->getTenant();

        $this->form->fill([
            'name' => $vendor->name,
            'description' => $vendor->description,
            'whatsapp' => $vendor->whatsapp,
            'city' => $vendor->city,
            'state' => $vendor->state,
            'bank_name' => $vendor->bank_name,
            'account_number' => $vendor->account_number,
            'account_name' => $vendor->account_name,
            'pos_vat_enabled' => $vendor->pos_vat_enabled ?? true,
            'pos_vat_rate' => $vendor->pos_vat_rate ?? 7.5,
            'pos_blind_count_participants' => $vendor->pos_blind_count_participants ?? 2,
            'pos_min_margin_percent' => $vendor->pos_min_margin_percent ?? 0,
            'allow_negative_stock' => (bool) $vendor->allow_negative_stock,
            'pos_ask_quantity' => $vendor->pos_ask_quantity ?? true,
            'pos_allow_price_change' => (bool) $vendor->pos_allow_price_change,
            'allow_half_quantities' => (bool) $vendor->allow_half_quantities,
            'pos_blind_count_frequency' => $vendor->pos_blind_count_frequency ?? 'daily',
            'pos_blind_count_custom_days' => $vendor->pos_blind_count_custom_days ?? 3,
            'sku_style' => $vendor->sku_style ?: SkuGenerator::STYLE_CATEGORY_NAME,
            'sku_prefix' => $vendor->sku_prefix,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Store Information')
                    ->description('This is how customers see your store on GadgetPlug.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Store Name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('whatsapp')
                            ->label('WhatsApp Number')
                            ->tel()
                            ->placeholder('08012345678')
                            ->maxLength(20),

                        TextInput::make('city')
                            ->label('City / Town')
                            ->placeholder('e.g. Ikeja')
                            ->maxLength(100)
                            ->helperText('Shown to customers on your posts, as "City, State". Leave both blank to show only your store name.'),

                        // A list rather than a free text box: this is printed
                        // publicly on every post, and "Lagos", "lagos" and
                        // "LAG" typed by three different stores would read as
                        // three different places.
                        Select::make('state')
                            ->label('State')
                            ->options(array_combine(self::STATES, self::STATES))
                            ->searchable()
                            ->placeholder('Select a state'),

                        Textarea::make('description')
                            ->label('Store Description')
                            ->rows(4)
                            ->maxLength(1000)
                            ->placeholder('Tell customers what you sell and what makes your store unique…'),
                    ]),

                Section::make('Sales Point Settings')
                    ->description('Configure how VAT is applied on sales made at the Sales Point.')
                    ->schema([
                        Toggle::make('pos_vat_enabled')
                            ->label('Charge VAT on shop sales')
                            ->helperText('When off, VAT will not be calculated or shown on any Sales Point receipt.')
                            ->default(true)
                            ->live(),

                        Select::make('pos_blind_count_participants')
                            ->label('Inventory Count Participants')
                            ->options([
                                1 => '1 person — single counter (faster)',
                                2 => '2 people — dual verification (more accurate)',
                            ])
                            ->default(2)
                            ->helperText('With 2 people, a second storekeeper independently verifies the first count before stock is updated.')
                            ->required(),

                        Select::make('pos_blind_count_frequency')
                            ->label('Inventory Count Frequency')
                            ->options([
                                'none' => 'No restriction — count any time',
                                'daily' => 'Once a day',
                                'weekly' => 'Once a week',
                                'monthly' => 'Once a month',
                                'custom' => 'Custom number of days',
                            ])
                            ->default('daily')
                            ->helperText('How often the same person may count. This is set here, by you — not by the counter — so a storekeeper cannot shorten their own waiting period. A manager can still authorise an early re-count.')
                            ->required()
                            ->live(),

                        TextInput::make('pos_blind_count_custom_days')
                            ->label('Days Between Counts')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(3)
                            ->required()
                            ->visible(fn ($get) => $get('pos_blind_count_frequency') === 'custom'),

                        TextInput::make('pos_vat_rate')
                            ->label('VAT Rate (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.5)
                            ->suffix('%')
                            ->default(7.5)
                            ->visible(fn ($get) => $get('pos_vat_enabled'))
                            ->required(fn ($get) => $get('pos_vat_enabled')),

                        TextInput::make('pos_min_margin_percent')
                            ->label('Minimum Margin on Negotiated Sales (%)')
                            ->helperText('How much margin you insist on keeping when a cashier negotiates a price at the till. 0% lets them go down to cost, but never below it.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.5)
                            ->suffix('%')
                            ->default(0)
                            ->required(),

                        // Off is how every shop has always worked: a sale that
                        // would take stock below zero is refused. On, it goes
                        // through and the shortfall shows on the Negative Stock
                        // report until a delivery or count settles it. A product
                        // can override this either way from its own form.
                        Toggle::make('allow_negative_stock')
                            ->label('Allow selling below zero stock')
                            ->helperText('When on, the till can sell more than your records say you have — useful when goods arrive before they are entered. Shortfalls appear on the Negative Stock report.')
                            ->default(false),

                        // On is how search has always behaved. Scans follow it
                        // too, so a shop that sells in bulk is asked every
                        // time and one that sells singles is asked never.
                        Toggle::make('pos_ask_quantity')
                            ->label('Ask for quantity when adding items')
                            ->helperText('When on, the till asks how many every time a product is searched or scanned. When off, each product goes into the sale at 1 — change it from the cart if needed.')
                            ->default(true),

                        // Off: every sale is at the catalogue price, and the
                        // server ignores any other price a till sends.
                        Toggle::make('pos_allow_price_change')
                            ->label('Allow unit price change at sales point')
                            ->helperText('When on, cashiers can click a price in the cart to change it — never below your minimum margin. When off, items always sell at their retail or wholesale price.')
                            ->default(false),

                        // For the shops that split crates. Turning it off is
                        // refused while any product still holds a half — the
                        // Vendor model says which ones, and that shows here.
                        Toggle::make('allow_half_quantities')
                            ->label('Allow half quantities (e.g. half crate)')
                            ->helperText('When on, every product can be sold and counted in halves — ½, 1½, 2½. Each unit gets an optional half price on the product form. It cannot be turned off again while any product still holds a half.')
                            ->default(false),
                    ]),

                // How this shop's product codes are made. Only the owner can
                // change it: it decides what every code in the shop looks like,
                // and what is already printed on shelves and labels is not
                // rewritten when it changes - only products added afterwards.
                Section::make('Product codes (SKU)')
                    ->description('How a new product\'s SKU is made. Existing products keep the SKU they have.')
                    ->schema([
                        Select::make('sku_style')
                            ->label('SKU style')
                            ->options([
                                SkuGenerator::STYLE_CATEGORY_NAME => 'Category + name + number — e.g. ALC-CASTLI-0042',
                                SkuGenerator::STYLE_SERIAL => 'Serial number — 1, 2, 3 … continuing from your highest',
                                SkuGenerator::STYLE_PREFIX_SERIAL => 'Prefix + serial number — e.g. VWD-8226',
                                SkuGenerator::STYLE_MANUAL => 'I type every SKU myself',
                            ])
                            ->default(SkuGenerator::STYLE_CATEGORY_NAME)
                            ->required()
                            ->native(true)
                            ->live()
                            ->disabled(fn () => ! self::isOwner())
                            ->helperText(fn () => self::isOwner()
                                ? 'Only affects products you add from now on.'
                                : 'Only the store owner can change this.'),

                        TextInput::make('sku_prefix')
                            ->label('Prefix')
                            ->placeholder('e.g. VWD')
                            ->maxLength(6)
                            ->regex('/^[A-Za-z0-9]{1,6}$/')
                            ->validationMessages(['regex' => 'Letters and numbers only, up to 6.'])
                            ->required(fn ($get) => $get('sku_style') === SkuGenerator::STYLE_PREFIX_SERIAL)
                            ->visible(fn ($get) => $get('sku_style') === SkuGenerator::STYLE_PREFIX_SERIAL)
                            ->disabled(fn () => ! self::isOwner())
                            ->live(onBlur: true),

                        Placeholder::make('sku_example')
                            ->label('The next product would get')
                            ->content(function ($get): string {
                                $style = $get('sku_style');
                                $prefix = $get('sku_prefix');

                                if ($style === SkuGenerator::STYLE_MANUAL) {
                                    return 'Whatever you type — a SKU is then required on every product.';
                                }

                                if ($style === SkuGenerator::STYLE_PREFIX_SERIAL && blank($prefix)) {
                                    return 'Enter a prefix to see an example.';
                                }

                                return app(SkuGenerator::class)->example(filament()->getTenant()->id, (string) $style, $prefix);
                            }),
                    ]),

                Section::make('Bank Details for Payouts')
                    ->description('Your earnings will be transferred to this account.')
                    ->schema([
                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->placeholder('e.g. GTBank, UBA, Zenith')
                            ->maxLength(100),

                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->placeholder('10-digit account number')
                            ->maxLength(10)
                            ->minLength(10)
                            ->numeric(),

                        TextInput::make('account_name')
                            ->label('Account Name')
                            ->placeholder('Name on the bank account')
                            ->maxLength(255),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $vendor = filament()->getTenant();

        $updateData = [
            'description' => $data['description'],
            'whatsapp' => $data['whatsapp'],
            'city' => $data['city'] ?: null,
            'state' => $data['state'] ?: null,
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'account_name' => $data['account_name'],
            'pos_vat_enabled' => $data['pos_vat_enabled'] ?? false,
            'pos_vat_rate' => $data['pos_vat_enabled'] ? ($data['pos_vat_rate'] ?? 7.5) : 0,
            'pos_blind_count_participants' => (int) ($data['pos_blind_count_participants'] ?? 2),
            'pos_min_margin_percent' => (float) ($data['pos_min_margin_percent'] ?? 0),
            'allow_negative_stock' => (bool) ($data['allow_negative_stock'] ?? false),
            'pos_ask_quantity' => (bool) ($data['pos_ask_quantity'] ?? true),
            'pos_allow_price_change' => (bool) ($data['pos_allow_price_change'] ?? false),
            'allow_half_quantities' => (bool) ($data['allow_half_quantities'] ?? false),
            'pos_blind_count_frequency' => $data['pos_blind_count_frequency'] ?? 'daily',
            'pos_blind_count_custom_days' => ($data['pos_blind_count_frequency'] ?? 'daily') === 'custom'
                ? (int) ($data['pos_blind_count_custom_days'] ?? 3)
                : null,
        ];

        try {
            // If name changed, let the sluggable trait regenerate a unique slug
            if ($data['name'] !== $vendor->name) {
                $updateData['name'] = $data['name'];
                $vendor->fill($updateData);
                $vendor->generateSlug(); // spatie/laravel-sluggable — unique slug auto-handled
                $vendor->save();
            } else {
                $vendor->update($updateData);
            }
        } catch (ValidationException $e) {
            // Turning half quantities off while a product still holds a half.
            // Nothing was saved; say why on the toggle and at the top.
            $message = $e->errors()['allow_half_quantities'][0] ?? $e->getMessage();
            $vendor->refresh();

            Notification::make()
                ->title('Half quantities are still on')
                ->body($message)
                ->danger()
                ->persistent()
                ->send();

            throw ValidationException::withMessages(['data.allow_half_quantities' => $message]);
        }

        // Owner only. The fields are disabled for anyone else, but a disabled
        // field is only a hint to the browser, so this is checked here too.
        if (self::isOwner()) {
            $style = in_array($data['sku_style'] ?? null, SkuGenerator::STYLES, true)
                ? $data['sku_style']
                : SkuGenerator::STYLE_CATEGORY_NAME;

            $vendor->forceFill([
                'sku_style' => $style,
                'sku_prefix' => $style === SkuGenerator::STYLE_PREFIX_SERIAL
                    ? app(SkuGenerator::class)->cleanPrefix($data['sku_prefix'] ?? null)
                    : null,
            ])->save();
        }

        Notification::make()
            ->title('Store profile saved')
            ->success()
            ->send();
    }

    private static function isOwner(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $user && $vendor && $vendor->isOwner($user);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'edit_vendor')
        );
    }
}
