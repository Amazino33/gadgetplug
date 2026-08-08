<?php

namespace App\Filament\Resources\AffiliateApplications\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AffiliateApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Applicant')
                ->columns(2)
                ->schema([
                    TextEntry::make('user.name')->label('Full Name'),
                    TextEntry::make('user.email')->label('Email'),
                    TextEntry::make('created_at')->label('Applied')->dateTime('d M Y, g:ia'),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'pending'  => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default    => 'gray',
                        }),
                ]),

            Section::make('Application')
                ->columns(2)
                ->schema([
                    TextEntry::make('whatsapp')->label('WhatsApp')->copyable(),
                    TextEntry::make('reason')->label('Why they want to be an affiliate')->columnSpanFull(),
                ]),

            Section::make('Admin Review')
                ->schema([
                    TextEntry::make('admin_notes')->label('Notes')->placeholder('No notes yet'),
                ]),
        ]);
    }
}
