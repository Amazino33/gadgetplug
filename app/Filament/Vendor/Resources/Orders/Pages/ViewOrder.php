<?php

namespace App\Filament\Vendor\Resources\Orders\Pages;

use App\Filament\Vendor\Resources\Orders\OrderResource;
use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Orders\InvalidOrderTransition;
use App\Services\Orders\OrderStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('callCustomer')
                ->label('Call')
                ->icon('heroicon-o-phone')
                ->color('info')
                ->url(fn () => 'tel:'.$this->record->customer_phone),

            // The store's part only: pack, then hand to the rider. Delivered,
            // cancelled and "payment received" are GadgetPlug's to record (admin
            // panel); stock and money move inside OrderStatusService.
            Action::make('updateStatus')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->schema([
                    Select::make('status')
                        ->label('New Status')
                        ->options(fn () => OrderStatusService::storeOptionsFor($this->record))
                        ->live()
                        ->required(),
                    // No "notify customer" switch: at handover the customer always
                    // gets their delivery code — the order cannot be delivered without it.
                ])
                ->action(function (array $data): void {
                    try {
                        OrderStatusService::applyStoreStep($this->record, auth()->user(), $data['status']);
                    } catch (InvalidOrderTransition|AuthorizationException $e) {
                        Notification::make()->title($e instanceof AuthorizationException ? 'Only staff of the store holding this order can update it.' : $e->getMessage())->danger()->send();

                        return;
                    }

                    $this->record->refresh();
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title($data['status'] === 'shipped' ? 'Order handed to rider — stock deducted' : 'Order marked as packed')
                        ->success()
                        ->send();
                })
                ->visible(fn () => OrderStatusService::storeOptionsFor($this->record) !== []
                    && auth()->user()->can('fulfil', $this->record)),

            // No "Assign & Notify Rider" or "Pay Delivery Cost" here any more:
            // GadgetPlug assigns its own logistics companies to online orders and
            // pays them (admin dispatch board). A vendor's own riders and
            // companies are not offered for online orders.

            Action::make('sendMessage')
                ->label('Send Message')
                ->icon('heroicon-o-chat-bubble-oval-left')
                ->color('success')
                ->schema([
                    Select::make('recipient_type')
                        ->label('Send to')
                        ->options(fn () => array_filter([
                            'customer' => 'Customer',
                            // The rider GadgetPlug assigned (or their company's dispatch
                            // line); a vendor's own rider only on older orders.
                            'rider' => $this->record->riderContactNumber() ? 'Rider' : null,
                        ]))
                        ->default('customer')
                        ->live()
                        ->required(),

                    Select::make('template_key')
                        ->label('Template')
                        ->options(fn ($get) => MessageTemplate::query()
                            ->where('vendor_id', filament()->getTenant()->id)
                            ->where('recipient_type', $get('recipient_type'))
                            ->where('is_active', true)
                            ->pluck('key', 'key'))
                        ->searchable()
                        ->placeholder('Choose a template (optional)')
                        ->live()
                        ->afterStateUpdated(function ($state, $set): void {
                            $template = MessageTemplate::query()
                                ->where('vendor_id', filament()->getTenant()->id)
                                ->where('key', $state)
                                ->first();

                            if ($template) {
                                $set('channel', $template->channel);
                                $set('body', app(TemplateRenderer::class)->render(
                                    $template->body,
                                    TemplateRenderer::contextForOrder($this->record, $template->recipient_type === 'customer' ? TemplateRenderer::AUDIENCE_CUSTOMER : TemplateRenderer::AUDIENCE_RIDER),
                                ));
                            }
                        }),

                    Select::make('channel')
                        ->options(['whatsapp' => 'WhatsApp', 'sms' => 'SMS'])
                        ->default('whatsapp')
                        ->required(),

                    Textarea::make('body')
                        ->label('Message')
                        ->rows(5)
                        ->required()
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    $order = $this->record;
                    $to = $data['recipient_type'] === 'customer'
                        ? $order->customer_phone
                        : $order->riderContactNumber();

                    if (! $to) {
                        Notification::make()
                            ->title('No phone number available for the selected recipient.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $message = DeliveryMessage::create([
                        'vendor_id' => filament()->getTenant()->id,
                        'order_id' => $order->id,
                        'recipient_type' => $data['recipient_type'],
                        'channel' => $data['channel'],
                        'to_number' => $to,
                        'body' => $data['body'],
                        'status' => 'queued',
                        'sent_by' => auth()->id(),
                    ]);

                    $this->sendAndNotify($message);
                }),
        ];
    }

    private function sendAndNotify(DeliveryMessage $message): void
    {
        $result = app(MessagingService::class)->send($message);

        match ($result->status) {
            'sent' => Notification::make()
                ->title('Message sent')
                ->success()
                ->send(),

            'failed' => Notification::make()
                ->title('Message failed to send')
                ->body($result->provider_response['error'] ?? 'Unknown error')
                ->danger()
                ->send(),

            'link_generated' => Notification::make()
                ->title('WhatsApp link ready — tap to send')
                ->body('No automated WhatsApp provider is configured, so this needs one manual tap.')
                ->warning()
                ->persistent()
                ->actions([
                    Action::make('open')
                        ->label('Open WhatsApp')
                        ->url($result->provider_response['url'] ?? '#')
                        ->openUrlInNewTab(),
                ])
                ->send(),

            default => null,
        };
    }
}
