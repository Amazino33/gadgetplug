<?php

namespace App\Filament\Resources\ClientRequests\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client Details')
                    ->schema([
                        TextInput::make('client_name')
                            ->label('Client Name')
                            ->default('Guest')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('client_email')
                            ->label('Client Email')
                            ->email()
                            ->nullable()
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Request')
                    ->schema([
                        Textarea::make('request_text')
                            ->label('Task / Request')
                            ->required()
                            ->rows(5)
                            ->maxLength(5000)
                            ->columnSpanFull(),

                        Toggle::make('is_completed')
                            ->label('Mark as Completed')
                            ->default(false)
                            ->inline(false),
                    ]),
            ]);
    }
}
