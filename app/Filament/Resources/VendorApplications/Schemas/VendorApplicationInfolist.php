<?php

namespace App\Filament\Resources\VendorApplications\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class VendorApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Applicant')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('user.name')->label('Full Name'),
                        TextEntry::make('user.email')->label('Email'),
                        TextEntry::make('created_at')->label('Applied')->dateTime('d M Y, g:ia'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn($state) => match($state) {
                                'pending'  => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default    => 'gray',
                            }),
                    ]),

                Section::make('Store Details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('store_name')->label('Store Name')->weight('bold'),
                        TextEntry::make('business_type')->label('Business Type')->badge()->color('info'),
                        TextEntry::make('whatsapp')->label('WhatsApp')->copyable(),
                        TextEntry::make('description')->label('Description')->columnSpanFull(),
                    ]),

                Section::make('Admin Review')
                    ->schema([
                        TextEntry::make('admin_notes')->label('Notes')->placeholder('No notes yet'),
                    ]),
            ]);
    }
}
