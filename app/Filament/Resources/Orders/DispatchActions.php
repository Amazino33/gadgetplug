<?php

namespace App\Filament\Resources\Orders;

use App\Models\Order;
use App\Services\Orders\DispatchService;
use App\Services\Orders\InvalidOrderTransition;
use App\Services\Orders\OrderStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * GadgetPlug's actions on an online order, shared by the dispatch board's rows
 * and the order page so both do exactly the same thing. Each is visible only
 * to dispatch staff (OrderPolicy::dispatch — manage_dispatch; super_admin
 * passes) and runs through OrderStatusService or DispatchService, which check
 * the same permission again.
 */
class DispatchActions
{
    /** The form value for "Collected by vendor (legacy)". */
    public const VENDOR_LEGACY = 'collected_by_vendor';

    /** @return array<int, Action> */
    public static function all(): array
    {
        return [
            self::assign(),
            self::resendLink(),
            self::markCompanyPaid(),
            self::recordPaymentReceived(),
            self::markDelivered(),
            self::newDeliveryCode(),
            self::returnToStore(),
            self::cancel(),
        ];
    }

    private static function canDispatch(Order $record): bool
    {
        return (bool) auth()->user()?->can('dispatch', $record);
    }

    public static function assign(): Action
    {
        return Action::make('assignCompany')
            ->label(fn (Order $record) => $record->logistics_company_id ? 'Reassign company' : 'Assign company')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->modalHeading(fn (Order $record) => 'Logistics for '.$record->reference)
            ->schema([
                Select::make('logistics_company_id')
                    ->label('Logistics company')
                    ->options(fn (Order $record) => app(DispatchService::class)->companyOptionsFor($record)->all())
                    ->helperText('Companies that cover this area are listed first.')
                    ->required(),
                TextInput::make('rider_name')
                    ->label('Rider\'s name (optional)')
                    ->maxLength(120),
                TextInput::make('rider_phone')
                    ->label('Rider\'s phone (optional)')
                    ->tel()
                    ->maxLength(30)
                    ->helperText('The link goes to the company\'s dispatch WhatsApp, and to this number too if you enter one.'),
                TextInput::make('company_cost')
                    ->label('What we pay the company (₦)')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('₦')
                    ->disabled(fn (Order $record) => $record->company_paid_at !== null)
                    ->helperText(fn (Order $record) => $record->company_paid_at !== null
                        ? 'Already paid to the company — locked.'
                        : 'Filled in from the delivery area\'s expected cost. Change it if this run costs something else.'),
                Toggle::make('notify')
                    ->label('Send the dispatch link now')
                    ->default(true),
            ])
            ->fillForm(fn (Order $record) => [
                'logistics_company_id' => $record->logisticsCompany?->vendor_id === null ? $record->logistics_company_id : null,
                'rider_name'           => $record->rider_name,
                'rider_phone'          => $record->rider_phone,
                'company_cost'         => $record->company_cost ?? app(DispatchService::class)->expectedCostFor($record),
                'notify'               => true,
            ])
            ->visible(fn (Order $record) => in_array($record->status, DispatchService::ASSIGNABLE, true) && self::canDispatch($record))
            ->action(function (Order $record, array $data) {
                $done = self::attempt(fn () => app(DispatchService::class)->assign(
                    $record,
                    auth()->user(),
                    (int) $data['logistics_company_id'],
                    $data['rider_name'] ?? null,
                    $data['rider_phone'] ?? null,
                    array_key_exists('company_cost', $data) && $data['company_cost'] !== null && $data['company_cost'] !== '' ? (float) $data['company_cost'] : null,
                    (bool) ($data['notify'] ?? true),
                ));

                if ($done !== false) {
                    Notification::make()->title(($data['notify'] ?? true) ? 'Assigned — dispatch link sent.' : 'Assigned.')->success()->send();
                }
            });
    }

    public static function resendLink(): Action
    {
        return Action::make('resendLink')
            ->label('Resend dispatch link')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Sends the link again to the company (and the rider, if entered), good for another 48 hours.')
            ->visible(fn (Order $record) => $record->logistics_company_id !== null
                && in_array($record->status, DispatchService::ASSIGNABLE, true)
                && self::canDispatch($record))
            ->action(function (Order $record) {
                if (self::attempt(fn () => app(DispatchService::class)->resendDispatchLink($record, auth()->user())) !== false) {
                    Notification::make()->title('Dispatch link sent again.')->success()->send();
                }
            });
    }

    public static function markCompanyPaid(): Action
    {
        return Action::make('markCompanyPaid')
            ->label('Mark paid to company')
            ->icon('heroicon-o-check-circle')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (Order $record) => 'Records that GadgetPlug has paid '
                .($record->logisticsCompany?->name ?? 'the company').' ₦'.number_format((float) $record->company_cost, 2)
                .' for this delivery. No ledger entry is made yet.')
            ->visible(fn (Order $record) => $record->logistics_company_id !== null
                && $record->company_cost !== null
                && $record->company_paid_at === null
                && self::canDispatch($record))
            ->action(function (Order $record) {
                if (self::attempt(fn () => app(DispatchService::class)->markCompanyPaid($record, auth()->user())) !== false) {
                    Notification::make()->title('Marked paid to the company.')->success()->send();
                }
            });
    }

    /**
     * Pay-on-delivery money only counts once GadgetPlug has it. Books this
     * vendor order's revenue (its own items, never the delivery fee) into the
     * vendor's Due from GadgetPlug account, with any supplier payable, once.
     */
    public static function recordPaymentReceived(): Action
    {
        return Action::make('recordPaymentReceived')
            ->label('Record payment received')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Record payment received')
            ->modalDescription(fn (Order $record) => 'Books ₦'.number_format((float) $record->total_amount, 2)
                .' to '.($record->items->first()?->vendor?->name ?? 'the vendor')
                .'\'s Due from GadgetPlug balance, as money collected on '.$record->reference
                .'. Do this only once the money is actually with GadgetPlug — it happens once and cannot be undone from here.')
            ->schema([
                Select::make('payment_channel')
                    ->label('How did the customer pay?')
                    // An order placed before the switch may have had its cash
                    // taken by the vendor's own rider: that books the old way,
                    // to the vendor's own cash account. Never offered after.
                    ->options(fn (Order $record) => ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer']
                        + ($record->predatesStatusCutover() ? [self::VENDOR_LEGACY => 'Collected by vendor (legacy) — to the vendor\'s own cash account'] : []))
                    ->required(),
            ])
            ->visible(fn (Order $record) => $record->payment_method === 'pay_on_delivery'
                && in_array($record->status, ['shipped', 'delivered'], true)
                && ! $record->isRevenueRecognized()
                && self::canDispatch($record))
            ->action(function (Order $record, array $data): void {
                $legacy = $data['payment_channel'] === self::VENDOR_LEGACY;

                $reason = self::attempt(fn () => app(OrderStatusService::class)
                    ->recordPaymentReceived($record, auth()->user(), $legacy ? 'cash' : $data['payment_channel'], collectedByVendor: $legacy));

                if ($reason === false) {
                    return;
                }

                if ($reason !== null) {
                    Notification::make()
                        ->title(match ($reason) {
                            'already_recognized' => 'This order was already counted — nothing changed.',
                            'no_account'         => 'This vendor has no active cash account to book it to.',
                            default              => 'Could not record this payment. Try again; if it keeps failing, check the log.',
                        })
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()->title($legacy
                    ? 'Payment recorded — booked to the vendor\'s own cash account.'
                    : 'Payment recorded — added to the vendor\'s Due from GadgetPlug balance.')->success()->send();
            });
    }

    /**
     * Override: delivered without the customer's code. Logistics only — no
     * stock or money moves.
     */
    public static function markDelivered(): Action
    {
        return Action::make('markDelivered')
            ->label('Mark delivered (override)')
            ->icon('heroicon-o-check-badge')
            ->color('primary')
            ->modalHeading('Mark delivered without the code')
            ->modalDescription('Use this only when the rider cannot enter the customer\'s code. Moves no stock or money — for a pay-on-delivery order, record the payment separately.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why are you marking this delivered?')
                    ->placeholder('e.g. Rider confirmed by phone; customer confirmed on WhatsApp.')
                    ->required()
                    ->maxLength(500),
                Toggle::make('notify_customer')
                    ->label('Notify customer')
                    ->default(true),
            ])
            ->visible(fn (Order $record) => OrderStatusService::canMove($record, 'delivered') && self::canDispatch($record))
            ->action(function (Order $record, array $data): void {
                if (self::attempt(fn () => app(OrderStatusService::class)
                    ->markDelivered($record, auth()->user(), $data['reason'], $data['notify_customer'] ?? true)) !== false) {
                    Notification::make()->title('Order marked delivered.')->success()->send();
                }
            });
    }

    public static function newDeliveryCode(): Action
    {
        return Action::make('newDeliveryCode')
            ->label(fn (Order $record) => $record->isDeliveryCodeLocked() ? 'Unlock: send a new code' : 'Send a new delivery code')
            ->icon('heroicon-o-key')
            ->color(fn (Order $record) => $record->isDeliveryCodeLocked() ? 'danger' : 'gray')
            ->requiresConfirmation()
            ->modalDescription('Sends the customer a new 4-digit code on WhatsApp. The old code stops working and the wrong-attempt count resets.')
            ->visible(fn (Order $record) => $record->status === 'shipped' && self::canDispatch($record))
            ->action(function (Order $record) {
                if (self::attempt(fn () => app(DispatchService::class)->issueNewCode($record, auth()->user())) !== false) {
                    Notification::make()->title('New delivery code sent to the customer.')->success()->send();
                }
            });
    }

    /** Failed delivery: the goods go back to the branch they came from. */
    public static function returnToStore(): Action
    {
        return Action::make('returnToStore')
            ->label('Failed delivery / returned to store')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->modalHeading('Failed delivery — returned to store')
            ->modalDescription('The goods go back on the shelf of the branch they left from, at the cost they left with. No money moves. Cancel the order afterwards if it will not be delivered again — that settles any payment.')
            ->schema([
                Textarea::make('reason')
                    ->label('What happened?')
                    ->placeholder('e.g. Customer unreachable after three calls; customer refused the item.')
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (Order $record) => OrderStatusService::canMove($record, 'returned') && self::canDispatch($record))
            ->action(function (Order $record, array $data): void {
                if (self::attempt(fn () => app(OrderStatusService::class)->returnToStore($record, auth()->user(), $data['reason'])) !== false) {
                    Notification::make()->title('Returned to store — stock is back on the shelf.')->success()->send();
                }
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelOrder')
            ->label('Cancel order')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Cancel order')
            ->modalDescription('Releases any stock held for this order, reverses any revenue and supplier debt booked for it, and refunds a reseller\'s wallet.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why is this order being cancelled?')
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (Order $record) => OrderStatusService::canMove($record, 'cancelled') && self::canDispatch($record))
            ->action(function (Order $record, array $data): void {
                if (self::attempt(fn () => app(OrderStatusService::class)->cancel($record, auth()->user(), $data['reason'])) !== false) {
                    Notification::make()->title('Order cancelled.')->success()->send();
                }
            });
    }

    /** Runs a service call; a refused move becomes a notification and false. */
    public static function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (InvalidOrderTransition $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title('You do not have permission to do that.')->danger()->send();
        }

        return false;
    }
}
