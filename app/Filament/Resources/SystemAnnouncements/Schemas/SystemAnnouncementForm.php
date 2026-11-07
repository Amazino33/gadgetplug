<?php

namespace App\Filament\Resources\SystemAnnouncements\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class SystemAnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Announcement Details')->schema([
                    TextInput::make('title')
                        ->required()
                        ->columnSpanFull(),
                    RichEditor::make('message')
                        ->required()
                        ->columnSpanFull(),

                    Select::make('target_group')
                        ->options([
                            'all' => 'All Users (Including Guests)',
                            'users' => 'Logged-in Users Only',
                            'vendors' => 'All Vendors',
                            'specific_roles' => 'Specific Roles',
                        ])
                        ->required()
                        ->default('all')
                        ->live(),

                    Select::make('target_roles')
                        ->multiple()
                        ->options(function () {
                            // Fetch all Spatie roles
                            return Role::pluck('name', 'name')->toArray();
                        })
                        ->visible(fn ($get) => $get('target_group') === 'specific_roles')
                        ->required(fn ($get) => $get('target_group') === 'specific_roles'),

                    TextInput::make('target_path')
                        ->label('Target URL Path (Optional)')
                        ->helperText('Leave empty to show everywhere. Example: /vendor/*')
                        ->columnSpanFull(),
                ])->columns(2),

                Section::make('Action & Settings')->schema([
                    TextInput::make('action_text')
                        ->label('Button Text (Optional)'),
                    TextInput::make('action_url')
                        ->label('Button URL (Optional)')
                        ->url(),

                    Toggle::make('is_dismissible')
                        ->label('Is Dismissible (Show X button)')
                        ->default(true)
                        ->required(),
                    Toggle::make('requires_action')
                        ->label('Requires Action to Dismiss')
                        ->default(false)
                        ->required(),
                    Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                    DateTimePicker::make('expires_at')
                        ->label('Expires At (Optional)'),
                ])->columns(2),
            ]);
    }
}
