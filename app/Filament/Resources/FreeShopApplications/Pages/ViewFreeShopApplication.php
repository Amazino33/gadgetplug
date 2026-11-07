<?php

namespace App\Filament\Resources\FreeShopApplications\Pages;

use App\Filament\Resources\FreeShopApplications\FreeShopApplicationResource;
use App\Models\Vendor;
use App\Services\FreePlan\FreeShopApplications;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * One free shop's application and the review actions on it. Every action goes
 * through FreeShopApplications, which re-checks the status under a lock, logs
 * who did what, and emails the shop where it has an email. Each success ends
 * with a "Message on WhatsApp" button holding the matching message, since most
 * of these shops will see WhatsApp long before they see an email.
 */
class ViewFreeShopApplication extends ViewRecord
{
    protected static string $resource = FreeShopApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'The shop goes live at '.$this->vendor()->shopLinkLabel().'.')
                ->visible(fn (): bool => $this->vendor()->hasStatus(Vendor::STATUS_PENDING) || $this->vendor()->hasStatus(Vendor::STATUS_REJECTED))
                ->action(fn () => $this->done(
                    app(FreeShopApplications::class)->approve($this->vendor(), auth()->user()),
                    'Shop approved',
                    'approved',
                )),

            Action::make('requestInfo')
                ->label('Request more info')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('warning')
                ->schema([
                    Textarea::make('message')
                        ->label('What do you need from them?')
                        ->helperText('Shown to the shop on its dashboard, word for word.')
                        ->required()
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->visible(fn (): bool => $this->vendor()->hasStatus(Vendor::STATUS_PENDING))
                ->action(fn (array $data) => $this->done(
                    app(FreeShopApplications::class)->requestInfo($this->vendor(), trim($data['message']), auth()->user()),
                    'More info requested',
                    'more_info',
                )),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->helperText('Shown to the shop, which can then edit and resubmit.')
                        ->required()
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->visible(fn (): bool => $this->vendor()->hasStatus(Vendor::STATUS_PENDING))
                ->action(fn (array $data) => $this->done(
                    app(FreeShopApplications::class)->reject($this->vendor(), trim($data['reason']), auth()->user()),
                    'Application rejected',
                    'rejected',
                )),

            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->visible(fn (): bool => $this->vendor()->hasStatus(Vendor::STATUS_APPROVED))
                ->action(fn (array $data) => $this->done(
                    app(FreeShopApplications::class)->suspend($this->vendor(), trim($data['reason']), auth()->user()),
                    'Shop suspended',
                    'suspended',
                )),

            Action::make('reinstate')
                ->label('Reinstate')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->vendor()->hasStatus(Vendor::STATUS_SUSPENDED))
                ->action(fn () => $this->done(
                    app(FreeShopApplications::class)->reinstate($this->vendor(), auth()->user()),
                    'Shop reinstated',
                    'approved',
                )),

            // For an owner locked out of their shop. Most free shops have no
            // email, so the usual reset link cannot reach them; the admin
            // passes the temporary password on over WhatsApp instead.
            Action::make('resetPassword')
                ->label('Reset password')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Reset the owner\'s password?')
                ->modalDescription('Their current password stops working at once. You will see the new temporary password one time only, with a button to send it on WhatsApp.')
                ->modalSubmitActionLabel('Reset password')
                ->visible(fn (): bool => FreeShopApplicationResource::canAccess() && $this->vendor()->user !== null)
                ->action(function (): void {
                    abort_unless(FreeShopApplicationResource::canAccess(), 403);

                    $vendor    = $this->vendor();
                    $temporary = app(FreeShopApplications::class)->resetPassword($vendor, auth()->user());

                    if ($temporary === null) {
                        Notification::make()->title('Nothing changed')->body('This shop has no owner account to reset.')->warning()->send();

                        return;
                    }

                    // Shown here and nowhere else: only the hash is kept, and
                    // this notification lives for this one page load.
                    Notification::make()
                        ->title('Temporary password: '.$temporary)
                        ->body('Shown once — copy it or send it now. They sign in with '.\App\Support\WhatsAppNumber::local($vendor->whatsapp).' and this password, then change it under Shop Profile.')
                        ->success()
                        ->persistent()
                        ->actions([
                            Action::make('sendPassword')
                                ->label('Send on WhatsApp')
                                ->button()
                                ->color('success')
                                ->url(FreeShopApplications::passwordResetWhatsAppLink($vendor, $temporary), shouldOpenInNewTab: true),
                        ])
                        ->send();
                }),

            Action::make('whatsapp')
                ->label('Message on WhatsApp')
                ->icon('heroicon-o-chat-bubble-oval-left')
                ->color('gray')
                ->url(fn (): string => FreeShopApplications::whatsAppLink($this->vendor()), shouldOpenInNewTab: true),
        ];
    }

    private function vendor(): Vendor
    {
        /** @var Vendor */
        return $this->record;
    }

    private function done(bool $ok, string $title, string $whatsAppKind): void
    {
        if (! $ok) {
            Notification::make()
                ->title('Nothing changed')
                ->body('This application was already reviewed by someone else. The page has been refreshed.')
                ->warning()
                ->send();

            $this->record->refresh();

            return;
        }

        $vendor = $this->vendor()->refresh();

        Notification::make()
            ->title($title)
            ->body('Let '.($vendor->user?->name ?? 'the owner').' know on WhatsApp.')
            ->success()
            ->persistent()
            ->actions([
                Action::make('whatsapp')
                    ->label('Message on WhatsApp')
                    ->button()
                    ->color('success')
                    ->url(FreeShopApplications::whatsAppLink($vendor, $whatsAppKind), shouldOpenInNewTab: true),
            ])
            ->send();
    }
}
