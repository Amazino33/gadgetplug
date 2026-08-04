<?php

namespace App\Filament\Vendor\Resources\Orders\Pages;

use App\Filament\Vendor\Resources\Orders\OrderResource;
use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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

            Action::make('updateStatus')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->schema([
                    Select::make('status')
                        ->label('New Status')
                        ->options(fn () => match ($this->record->status) {
                            'pending', 'confirmed', 'paid' => [
                                'shipped' => 'Hand to Logistics / Rider',
                                'cancelled' => 'Cancel Order',
                            ],
                            'shipped' => [
                                'delivered' => 'Mark as Delivered',
                            ],
                            default => [],
                        })
                        ->live()
                        ->required(),

                    Toggle::make('notify_customer')
                        ->label('Notify customer')
                        ->helperText('Sends the matching customer update automatically. Turn off to change status silently.')
                        ->default(true)
                        ->visible(fn ($get) => in_array($get('status'), ['shipped', 'delivered'])),
                ])
                ->action(function (array $data): void {
                    $newStatus = $data['status'];
                    $order = $this->record;

                    // Physical stock deduction (on 'shipped') and reservation release (on
                    // 'cancelled') now happen centrally in OrderObserver::updated(), so
                    // they apply no matter which UI changed the status — not just here.
                    $order->skipCustomerNotification = ! ($data['notify_customer'] ?? true);
                    $order->update(['status' => $newStatus]);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title(match ($newStatus) {
                            'shipped' => 'Order handed to rider — stock deducted',
                            'delivered' => 'Order marked as delivered',
                            'cancelled' => 'Order cancelled — stock released',
                            default => 'Status updated',
                        })
                        ->success()
                        ->send();
                })
                ->visible(fn () => ! in_array($this->record->status, ['delivered', 'cancelled', 'paid_but_failed_stock'])),

            Action::make('assignLogistics')
                ->label('Assign & Notify Rider')
                ->icon('heroicon-o-truck')
                ->color('gray')
                ->schema([
                    Select::make('logistics_company_id')
                        ->label('Logistics Company')
                        ->relationship(
                            name: 'logisticsCompany',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query
                                ->where('vendor_id', filament()->getTenant()->id)
                                ->where('is_active', true),
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn ($set) => $set('delivery_person_id', null)),

                    Select::make('delivery_person_id')
                        ->label('Rider')
                        ->relationship(
                            name: 'deliveryPerson',
                            titleAttribute: 'name',
                            // Riders belonging to the chosen company, plus freelance riders (no company at all).
                            modifyQueryUsing: fn ($query, $get) => $query
                                ->where('vendor_id', filament()->getTenant()->id)
                                ->where('is_active', true)
                                ->where(function ($query) use ($get) {
                                    $query->whereNull('logistics_company_id');

                                    if ($get('logistics_company_id')) {
                                        $query->orWhere('logistics_company_id', $get('logistics_company_id'));
                                    }
                                }),
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->helperText('Shows riders from the selected company, plus any freelance riders.'),

                    Toggle::make('notify_rider')
                        ->label('Notify rider now')
                        ->helperText('Sends the "rider_assignment" template to the assigned rider immediately.')
                        ->default(true)
                        ->visible(fn ($get) => filled($get('delivery_person_id'))),
                ])
                ->fillForm(fn () => [
                    'logistics_company_id' => $this->record->logistics_company_id,
                    'delivery_person_id' => $this->record->delivery_person_id,
                    'notify_rider' => true,
                ])
                ->action(function (array $data): void {
                    $order = $this->record;

                    $order->update([
                        'logistics_company_id' => $data['logistics_company_id'],
                        'delivery_person_id' => $data['delivery_person_id'],
                    ]);

                    $this->refreshFormData(['logisticsCompany.name', 'deliveryPerson.name']);

                    if (($data['notify_rider'] ?? false) && $data['delivery_person_id']) {
                        $this->sendRiderAssignmentMessage($order->refresh());

                        return;
                    }

                    Notification::make()
                        ->title('Logistics assignment updated')
                        ->success()
                        ->send();
                }),

            Action::make('sendMessage')
                ->label('Send Message')
                ->icon('heroicon-o-chat-bubble-oval-left')
                ->color('success')
                ->schema([
                    Select::make('recipient_type')
                        ->label('Send to')
                        ->options(fn () => array_filter([
                            'customer' => 'Customer',
                            'rider' => $this->record->delivery_person_id ? 'Rider' : null,
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
                                    TemplateRenderer::contextForOrder($this->record),
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
                        : $order->deliveryPerson?->phone;

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

    private function sendRiderAssignmentMessage(Order $order): void
    {
        $template = MessageTemplate::query()
            ->where('vendor_id', filament()->getTenant()->id)
            ->where('key', 'rider_assignment')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            Notification::make()
                ->title('Rider assigned — no active "rider_assignment" template found, so no message was sent.')
                ->warning()
                ->send();

            return;
        }

        $to = $order->deliveryPerson?->phone;

        if (! $to) {
            Notification::make()
                ->title('Rider assigned, but the rider has no phone number on file.')
                ->warning()
                ->send();

            return;
        }

        $message = DeliveryMessage::create([
            'vendor_id' => filament()->getTenant()->id,
            'order_id' => $order->id,
            'recipient_type' => 'rider',
            'channel' => $template->channel,
            'to_number' => $to,
            'body' => app(TemplateRenderer::class)->render($template->body, TemplateRenderer::contextForOrder($order)),
            'status' => 'queued',
            'sent_by' => auth()->id(),
        ]);

        $this->sendAndNotify($message);
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
