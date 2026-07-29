<?php

namespace App\Filament\Resources\VendorApplications\Tables;

use App\Models\VendorApplication;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

class VendorApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Applicant')
                    ->searchable()
                    ->description(fn(VendorApplication $r) => $r->user->email ?? ''),

                TextColumn::make('store_name')
                    ->label('Store Name')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('business_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                TextColumn::make('whatsapp')
                    ->label('WhatsApp')
                    ->copyable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn($state) => match($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Applied')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->form([
                        TextInput::make('admin_notes')
                            ->label('Welcome note (optional)')
                            ->placeholder('e.g. Welcome aboard! Your store is now live.'),
                    ])
                    ->action(function (VendorApplication $record, array $data): void {
                        // Locks the row and re-checks status inside the transaction so a
                        // double-click (or a retried request) can't create a second vendor
                        // for the same application — the ->visible() check below only hides
                        // the button after the page re-renders, it isn't a server-side guard.
                        $vendor = DB::transaction(function () use ($record, $data) {
                            $locked = VendorApplication::whereKey($record->id)->lockForUpdate()->first();

                            if ($locked->status !== 'pending') {
                                return null;
                            }

                            // Slug is auto-generated uniquely by spatie/laravel-sluggable
                            $vendor = Vendor::create([
                                'user_id'     => $locked->user_id,
                                'name'        => $locked->store_name,
                                'is_verified' => true,
                            ]);

                            // Attach user as owner in vendor_users
                            $vendor->users()->syncWithoutDetaching([
                                $locked->user_id => ['role' => 'owner'],
                            ]);

                            // Mark application approved
                            $locked->update([
                                'status'      => 'approved',
                                'admin_notes' => $data['admin_notes'] ?? null,
                            ]);

                            return $vendor;
                        });

                        if (! $vendor) {
                            Notification::make()
                                ->title('Already processed')
                                ->body('This application was already approved or rejected.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $panelUrl = route('filament.vendor.home', ['tenant' => $vendor->slug]);

                        Notification::make()
                            ->title('Application approved — ' . $record->store_name)
                            ->body('Vendor panel: ' . $panelUrl)
                            ->success()
                            ->send();
                    })
                    ->visible(fn(VendorApplication $r) => $r->status === 'pending'),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Textarea::make('admin_notes')
                            ->label('Reason for rejection')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (VendorApplication $record, array $data): void {
                        $record->update([
                            'status'      => 'rejected',
                            'admin_notes' => $data['admin_notes'],
                        ]);

                        Notification::make()
                            ->title('Application rejected')
                            ->warning()
                            ->send();
                    })
                    ->visible(fn(VendorApplication $r) => $r->status === 'pending'),
            ]);
    }
}
