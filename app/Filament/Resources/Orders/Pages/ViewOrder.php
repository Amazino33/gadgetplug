<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\DispatchActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Schemas\OrderPage;
use App\Services\Orders\OrderMessages;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;

// GadgetPlug's side of an order. The store packs and hands over from the
// vendor panel; what happens after that is done here: assigning a logistics
// company, what we pay it, the money collected on a pay-on-delivery order,
// delivery by override, failed delivery, cancellation — every one of them
// DispatchActions, the same set the dispatch board rows use — and messaging
// the people involved.
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Order #'.$this->record->reference;
    }

    public function infolist(Schema $schema): Schema
    {
        return OrderPage::configure($schema, [
            $this->sendMessageAction(),
            $this->whatsappChatAction(),
        ]);
    }

    /**
     * The main steps as buttons on a wide screen. On a phone only the two
     * most-used stay out; the rest go under "More". (Filament has no
     * per-breakpoint switch for actions, so the admin theme's gp-only-wide /
     * gp-only-phone classes do it.)
     */
    protected function getHeaderActions(): array
    {
        $wide = ['class' => 'gp-only-wide'];

        return [
            DispatchActions::assign()->button(),
            DispatchActions::recordPaymentReceived()->button(),
            DispatchActions::markDelivered()->button()->color('success')->extraAttributes($wide, merge: true),
            DispatchActions::returnToStore()->button()->color('gray')->extraAttributes($wide, merge: true),
            DispatchActions::cancel()->button()->color('danger')->extraAttributes($wide, merge: true),

            ActionGroup::make([
                DispatchActions::resendLink(),
                DispatchActions::markCompanyPaid(),
                DispatchActions::newDeliveryCode(),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->button()
                ->color('gray')
                ->extraAttributes($wide, merge: true),

            ActionGroup::make([
                DispatchActions::markDelivered(),
                DispatchActions::returnToStore(),
                DispatchActions::cancel(),
                DispatchActions::resendLink(),
                DispatchActions::markCompanyPaid(),
                DispatchActions::newDeliveryCode(),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->button()
                ->color('gray')
                ->extraAttributes(['class' => 'gp-only-phone'], merge: true),
        ];
    }

    public function sendMessageAction(): Action
    {
        return Action::make('sendMessage')
            ->label('Send message')
            ->icon('heroicon-m-paper-airplane')
            ->visible(fn (): bool => (bool) auth()->user()?->can('dispatch', $this->record))
            ->modalHeading('Send a message about this order')
            ->modalSubmitActionLabel('Send')
            ->extraModalWindowAttributes(['class' => 'gp-phone-sheet'])
            ->stickyModalFooter()
            ->fillForm(fn (): array => ['recipient' => 'customer', 'channel' => 'whatsapp'])
            ->schema([
                Select::make('recipient')
                    ->label('To')
                    ->options(fn (): array => collect(app(OrderMessages::class)->recipients($this->record))
                        ->mapWithKeys(fn (array $r, string $key) => [$key => $r['label'].' ('.$r['number'].')'])
                        ->all())
                    ->required()
                    ->selectablePlaceholder(false)
                    ->native(false),

                ToggleButtons::make('preset')
                    ->label('Quick message')
                    ->options(OrderMessages::PRESETS)
                    ->inline()
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $state
                        ? $set('body', app(OrderMessages::class)->preset($this->record, $state))
                        : null)
                    ->dehydrated(false),

                Textarea::make('body')
                    ->label('Message')
                    ->rows(5)
                    ->required()
                    ->maxLength(1000),

                ToggleButtons::make('channel')
                    ->label('Send by')
                    ->options(['whatsapp' => 'WhatsApp', 'sms' => 'SMS'])
                    ->inline()
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    $message = app(OrderMessages::class)->send($this->record, auth()->user(), $data['recipient'], $data['channel'], $data['body']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                match ($message->status) {
                    'sent' => Notification::make()->title('Message sent')->success()->send(),

                    'link_generated' => Notification::make()
                        ->title('WhatsApp link ready — tap to send')
                        ->body('No automated WhatsApp provider is configured, so this needs one manual tap.')
                        ->warning()
                        ->persistent()
                        ->actions([
                            Action::make('open')->label('Open WhatsApp')->url($message->provider_response['url'] ?? '#')->openUrlInNewTab(),
                        ])
                        ->send(),

                    default => Notification::make()
                        ->title('Message not sent')
                        ->body(match ($message->provider_response['error'] ?? null) {
                            'whatsapp_paused'   => 'WhatsApp sending is paused. Try SMS, or resume it on the WhatsApp page.',
                            'marketing_refused' => 'Promotional messages can\'t go out on WhatsApp.',
                            default             => $message->provider_response['error'] ?? 'The provider did not accept it.',
                        })
                        ->danger()
                        ->send(),
                };
            });
    }

    public function whatsappChatAction(): Action
    {
        return Action::make('whatsappChat')
            ->label('WhatsApp chat')
            ->icon('heroicon-m-chat-bubble-oval-left')
            ->color('gray')
            ->url(fn (): ?string => OrderMessages::whatsappChatUrl($this->record), shouldOpenInNewTab: true)
            ->visible(fn (): bool => OrderMessages::whatsappChatUrl($this->record) !== null);
    }
}
