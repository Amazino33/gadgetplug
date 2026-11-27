<?php

namespace App\Filament\Resources\Vendors\Schemas;

use App\Filament\Resources\Vendors\Support\VendorFeatures;
use App\Models\AssistantGlobalSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A shop in three parts: who it is, whether it can get in, and what is
 * switched on for it. The switches are VendorFeatures', the same list the
 * Vendors table's Features window and bulk switch use.
 */
class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Shop')
                    ->schema([
                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->required()
                            ->label('Shop Owner'),
                        TextInput::make('name')
                            ->required(),
                        TextInput::make('slug')
                            ->required()
                            ->helperText('Use lowercase letters and dashes (e.g., supreme-gadgets)'),
                        Toggle::make('is_verified')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Access')
                    ->schema([
                        Toggle::make('dashboard_blocked')
                            ->label('Block dashboard access')
                            ->helperText('Use for unpaid fees or a terms breach. The vendor and their whole team lose the vendor panel AND the Sales Point — existing till logins stop working immediately. Their storefront listings are not touched; that is the Online sales feature.')
                            ->live(),
                        Textarea::make('dashboard_blocked_reason')
                            ->label('Reason shown to the vendor')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('e.g. Outstanding platform commission for August. Contact accounts to settle.')
                            // Required once the block is on: the vendor sees this text on
                            // the lockout screen, and a blank one turns every block into a
                            // support ticket asking what happened.
                            ->required(fn ($get) => (bool) $get('dashboard_blocked'))
                            ->visible(fn ($get) => (bool) $get('dashboard_blocked')),
                    ]),

                Section::make('Ask GadgetPlug')
                    ->schema([
                        TextInput::make('assistant_daily_cap')
                            ->label('Ask GadgetPlug: questions per day')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->placeholder(fn () => (string) (AssistantGlobalSetting::current()->daily_vendor_cap ?? 100))
                            ->helperText('Leave empty for the platform default (Assistant settings). 0 = unlimited. All staff together; resets at midnight Lagos time.'),
                    ]),

                Section::make('Features')
                    ->description('What this shop has switched on. The same switches are under Features on the Vendors list.')
                    ->schema(VendorFeatures::toggles())
                    ->columns(2),
            ])
            ->columns(1);
    }
}
