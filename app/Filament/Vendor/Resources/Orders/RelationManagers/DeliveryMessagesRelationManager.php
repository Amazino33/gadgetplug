<?php

namespace App\Filament\Vendor\Resources\Orders\RelationManagers;

use App\Models\DeliveryMessage;
use App\Services\Messaging\MessagingService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeliveryMessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveryMessages';

    protected static ?string $title = 'Delivery Messages';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                TextColumn::make('recipient_type')
                    ->label('To')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),

                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => strtoupper($state) === 'SMS' ? 'SMS' : ucfirst($state)),

                TextColumn::make('to_number')
                    ->label('Number'),

                TextColumn::make('body')
                    ->label('Message')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent', 'link_generated' => 'success',
                        'queued'                 => 'gray',
                        'failed'                 => 'danger',
                        default                  => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'link_generated' => 'Link Generated',
                        default          => ucfirst($state),
                    }),

                TextColumn::make('sentBy.name')
                    ->label('Sent By')
                    ->placeholder('Automated'),

                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('resend')
                    ->label('Resend')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Re-send this exact message now?')
                    ->action(function (DeliveryMessage $record): void {
                        $result = app(MessagingService::class)->send($record);

                        $notification = Notification::make()->title(match ($result->status) {
                            'sent'           => 'Message resent',
                            'link_generated' => 'WhatsApp link regenerated — tap to send',
                            'failed'         => 'Resend failed',
                            default          => 'Resend attempted',
                        });

                        $result->status === 'failed' ? $notification->danger()->send() : $notification->success()->send();
                    }),
            ]);
    }
}
