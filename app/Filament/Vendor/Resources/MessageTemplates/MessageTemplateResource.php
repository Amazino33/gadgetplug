<?php

namespace App\Filament\Vendor\Resources\MessageTemplates;

use App\Models\MessageTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static string|null|\UnitEnum  $navigationGroup = 'Logistics';
    protected static ?string                $navigationLabel = 'Message Templates';
    protected static ?int                   $navigationSort  = 30;

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_logistics');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Template Details')->schema([
                TextInput::make('key')
                    ->required()
                    ->maxLength(100)
                    ->helperText('A unique identifier, e.g. "customer_dispatched".'),

                Select::make('recipient_type')
                    ->options(['customer' => 'Customer', 'rider' => 'Rider'])
                    ->required(),

                Select::make('channel')
                    ->options(['whatsapp' => 'WhatsApp', 'sms' => 'SMS'])
                    ->required(),

                Toggle::make('is_active')->label('Active')->default(true),

                Textarea::make('body')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull()
                    ->helperText('Placeholders: {{customer_name}}, {{customer_phone}}, {{order_number}}, {{rider_name}}, {{rider_phone}}, {{company_name}}, {{status}}, {{total}}, {{delivery_address}}'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->searchable()->sortable()->weight('bold'),
                TextColumn::make('recipient_type')->badge()->sortable(),
                TextColumn::make('channel')->badge()->sortable(),
                TextColumn::make('body')->limit(60)->wrap(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('recipient_type')->options(['customer' => 'Customer', 'rider' => 'Rider']),
                SelectFilter::make('channel')->options(['whatsapp' => 'WhatsApp', 'sms' => 'SMS']),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMessageTemplates::route('/'),
        ];
    }
}
