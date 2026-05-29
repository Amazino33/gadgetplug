<?php

namespace App\Filament\Vendor\Pages;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class StoreProfile extends Page
{
    use InteractsWithForms;

    protected string $view = 'filament.vendor.pages.store-profile';

    protected static ?string $navigationLabel = 'Store Profile';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public function mount(): void
    {
        $vendor = filament()->getTenant();

        $this->form->fill([
            'name'            => $vendor->name,
            'description'     => $vendor->description,
            'whatsapp'        => $vendor->whatsapp,
            'bank_name'       => $vendor->bank_name,
            'account_number'  => $vendor->account_number,
            'account_name'    => $vendor->account_name,
            'pos_vat_enabled'              => $vendor->pos_vat_enabled ?? true,
            'pos_vat_rate'                 => $vendor->pos_vat_rate ?? 7.5,
            'pos_blind_count_participants' => $vendor->pos_blind_count_participants ?? 2,
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

                        Textarea::make('description')
                            ->label('Store Description')
                            ->rows(4)
                            ->maxLength(1000)
                            ->placeholder('Tell customers what you sell and what makes your store unique…'),
                    ]),

                Section::make('POS Settings')
                    ->description('Configure how VAT is applied on Point of Sale transactions.')
                    ->schema([
                        Toggle::make('pos_vat_enabled')
                            ->label('Charge VAT on POS sales')
                            ->helperText('When off, VAT will not be calculated or shown on any POS receipt.')
                            ->default(true)
                            ->live(),

                        Select::make('pos_blind_count_participants')
                            ->label('Blind Count Participants')
                            ->options([
                                1 => '1 person — single counter (faster)',
                                2 => '2 people — dual verification (more accurate)',
                            ])
                            ->default(2)
                            ->helperText('With 2 people, a second storekeeper independently verifies the first count before stock is updated.')
                            ->required(),

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
        $data   = $this->form->getState();
        $vendor = filament()->getTenant();

        $updateData = [
            'description'     => $data['description'],
            'whatsapp'        => $data['whatsapp'],
            'bank_name'       => $data['bank_name'],
            'account_number'  => $data['account_number'],
            'account_name'    => $data['account_name'],
            'pos_vat_enabled'              => $data['pos_vat_enabled'] ?? false,
            'pos_vat_rate'                 => $data['pos_vat_enabled'] ? ($data['pos_vat_rate'] ?? 7.5) : 0,
            'pos_blind_count_participants' => (int) ($data['pos_blind_count_participants'] ?? 2),
        ];

        // If name changed, let the sluggable trait regenerate a unique slug
        if ($data['name'] !== $vendor->name) {
            $updateData['name'] = $data['name'];
            $vendor->fill($updateData);
            $vendor->generateSlug(); // spatie/laravel-sluggable — unique slug auto-handled
            $vendor->save();
        } else {
            $vendor->update($updateData);
        }

        Notification::make()
            ->title('Store profile saved')
            ->success()
            ->send();
    }
}
