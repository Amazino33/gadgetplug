<?php

namespace App\Filament\Resources\FreeShopApplications\Schemas;

use App\Models\Vendor;
use App\Services\FreePlan\FreeShopApplications;
use App\Support\WhatsAppNumber;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class FreeShopApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Business')
                    ->columnSpan(['lg' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('name')->label('Business name')->weight(FontWeight::Bold),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (Vendor $record, $state): string => $record->isAwaitingInfo() ? 'pending — info requested' : (string) $state)
                            ->color(fn ($state): string => match ($state) {
                                Vendor::STATUS_PENDING   => 'warning',
                                Vendor::STATUS_APPROVED  => 'success',
                                Vendor::STATUS_REJECTED  => 'danger',
                                Vendor::STATUS_SUSPENDED => 'gray',
                                default                  => 'gray',
                            }),
                        TextEntry::make('user.name')->label('Owner'),
                        TextEntry::make('whatsapp')
                            ->label('WhatsApp')
                            ->formatStateUsing(fn ($state): string => WhatsAppNumber::local($state))
                            ->url(fn (Vendor $record): string => WhatsAppNumber::link($record->whatsapp), shouldOpenInNewTab: true)
                            ->color('success')
                            ->copyable()
                            ->copyableState(fn (Vendor $record): ?string => $record->whatsapp),
                        TextEntry::make('user.email')
                            ->label('Email')
                            ->formatStateUsing(fn ($state): string => FreeShopApplications::isPlaceholderEmail($state) ? '— (signs in with WhatsApp)' : $state),
                        TextEntry::make('category'),
                        TextEntry::make('city')->label('City / Area'),
                        TextEntry::make('state'),
                        TextEntry::make('slug')
                            ->label('Shop link')
                            ->formatStateUsing(fn (Vendor $record): string => $record->shopLinkLabel()),
                        TextEntry::make('social_link')
                            ->label('Social media')
                            ->placeholder('Not given')
                            ->url(fn (Vendor $record): ?string => $record->social_link, shouldOpenInNewTab: true)
                            ->color('primary'),
                        TextEntry::make('description')
                            ->placeholder('No description')
                            ->columnSpanFull(),
                    ]),

                Section::make('Proof of business')
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        ImageEntry::make('logo')
                            ->disk('public')
                            ->imageHeight(80)
                            ->placeholder('No logo'),
                        ViewEntry::make('verification_photo')
                            ->label('Shop photo')
                            ->view('filament.admin.free-shop.verification-photo'),
                    ]),

                Section::make('Review')
                    ->columnSpan(['lg' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('applied_at')->label('Applied')->dateTime('d M Y, g:ia'),
                        TextEntry::make('approved_at')
                            ->label('Approved')
                            ->dateTime('d M Y, g:ia')
                            ->placeholder('—')
                            ->helperText(fn (Vendor $record): ?string => $record->approver ? 'by '.$record->approver->name : null),
                        TextEntry::make('review_note')
                            ->label(fn (Vendor $record): string => match (true) {
                                $record->isAwaitingInfo()                       => 'Info requested',
                                $record->hasStatus(Vendor::STATUS_REJECTED)     => 'Rejection reason',
                                $record->hasStatus(Vendor::STATUS_SUSPENDED)    => 'Suspension reason',
                                default                                         => 'Note to shop',
                            })
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make(fn (Vendor $record): string => 'Products added ('.$record->products()->count().')')
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        ViewEntry::make('products_preview')
                            ->hiddenLabel()
                            ->view('filament.admin.free-shop.products'),
                    ]),

                Section::make('Status history')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('statusLogs')
                            ->hiddenLabel()
                            ->columns(['default' => 1, 'sm' => 4])
                            ->schema([
                                TextEntry::make('created_at')->label('When')->dateTime('d M Y, g:ia'),
                                TextEntry::make('to_status')
                                    ->label('Change')
                                    ->formatStateUsing(fn ($record): string => ($record->from_status ?? 'new').' → '.$record->to_status),
                                TextEntry::make('changedBy.name')->label('By')->placeholder('—'),
                                TextEntry::make('note')->placeholder('—'),
                            ]),
                    ]),
            ]);
    }
}
