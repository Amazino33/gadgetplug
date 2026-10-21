<?php

namespace App\Filament\Vendor\Resources\Orders\Pages;

use App\Filament\Vendor\Resources\Orders\OrderResource;
use App\Models\DeliveryMessage;
use App\Models\FinancialAccount;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Services\FinancialLedger;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Orders\InvalidOrderTransition;
use App\Services\Orders\OrderStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

                    Toggle::make('notify_customer')
                        ->label('Notify customer')
                        ->helperText('Sends the matching customer update automatically. Turn off to change status silently.')
                        ->default(true)
                        ->visible(fn ($get) => $get('status') === 'shipped'),
                ])
                ->action(function (array $data): void {
                    try {
                        OrderStatusService::applyStoreStep($this->record, auth()->user(), $data['status'], $data['notify_customer'] ?? true);
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

                    TextInput::make('delivery_cost')
                        ->label('What You Pay the Rider/Company (₦)')
                        ->numeric()
                        ->prefix('₦')
                        ->minValue(0)
                        ->disabled(fn () => $this->record->isDeliveryCostPosted())
                        ->helperText(fn () => $this->record->isDeliveryCostPosted()
                            ? 'Already paid and recorded — this amount is locked in. If it was entered wrong, contact support rather than trying to change it here.'
                            : 'What YOU pay the rider or logistics company to deliver this order — not any delivery fee you charged the customer.'),
                ])
                ->fillForm(fn () => [
                    'logistics_company_id' => $this->record->logistics_company_id,
                    'delivery_person_id' => $this->record->delivery_person_id,
                    'notify_rider' => true,
                    'delivery_cost' => $this->record->delivery_cost,
                ])
                ->action(function (array $data): void {
                    $order = $this->record;

                    $order->update([
                        'logistics_company_id' => $data['logistics_company_id'],
                        'delivery_person_id' => $data['delivery_person_id'],
                        // Disabled (and so absent from $data) once posted — the
                        // model guard would refuse this anyway, but skipping it
                        // here avoids relying on that as the only line of defense.
                        ...($order->isDeliveryCostPosted() ? [] : ['delivery_cost' => $data['delivery_cost'] ?? null]),
                    ]);

                    $this->refreshFormData(['logisticsCompany.name', 'deliveryPerson.name', 'delivery_cost']);

                    if (($data['notify_rider'] ?? false) && $data['delivery_person_id']) {
                        $this->sendRiderAssignmentMessage($order->refresh());

                        return;
                    }

                    Notification::make()
                        ->title('Logistics assignment updated')
                        ->success()
                        ->send();
                }),

            Action::make('recordDeliveryPayment')
                ->label('Pay Delivery Cost')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Pay Delivery Cost')
                ->modalDescription(fn () => 'Deducts ₦' . number_format((float) $this->record->delivery_cost, 2) . ' from the account you choose, right now. Once confirmed, this amount is locked in and can\'t be edited from here.')
                ->schema([
                    Select::make('financial_account_id')
                        ->label('Paid From')
                        ->options(fn () => FinancialAccount::where('vendor_id', filament()->getTenant()->id)->spendable()->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->visible(fn () => filled($this->record->delivery_cost)
                    && ! $this->record->isDeliveryCostPosted()
                    && auth()->user()->hasVendorPermission(filament()->getTenant()->id, 'manage_logistics'))
                ->action(function (array $data): void {
                    $order = $this->record;
                    $account = FinancialAccount::findOrFail($data['financial_account_id']);

                    FinancialLedger::postEntry(
                        account: $account,
                        direction: 'out',
                        amount: (float) $order->delivery_cost,
                        source: $order,
                        description: "Delivery cost — order {$order->reference}",
                        createdBy: auth()->id(),
                    );

                    $order->update(['financial_account_id' => $account->id, 'posted_at' => now()]);
                    $this->refreshFormData(['financial_account_id', 'posted_at']);

                    Notification::make()->title('Delivery payment recorded.')->success()->send();
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
            'body' => app(TemplateRenderer::class)->render($template->body, TemplateRenderer::contextForOrder($order, TemplateRenderer::AUDIENCE_RIDER)),
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
