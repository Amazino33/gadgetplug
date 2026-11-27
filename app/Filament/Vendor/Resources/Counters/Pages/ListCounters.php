<?php

namespace App\Filament\Vendor\Resources\Counters\Pages;

use App\Filament\Vendor\Resources\Counters\CounterResource;
use App\Services\ActiveStore;
use App\Services\QrCode;
use App\Services\StockTake\StockTakeCounters;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListCounters extends ListRecords
{
    protected static string $resource = CounterResource::class;

    protected ?string $subheading = 'People who count stock but have no use for the site. Each signs in with the shop\'s QR code, their name and a 6-digit code.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Add counter')
                ->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(80)
                        ->helperText('As they will see it in the list when they sign in. Two people with the same first name need a surname or initial.'),
                    TextInput::make('phone')
                        ->label('Phone (optional)')
                        ->tel()
                        ->maxLength(30),
                    Select::make('stores')
                        ->label('Branches they may count at')
                        ->multiple()
                        ->options(fn () => filament()->getTenant()->stores()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => array_filter([ActiveStore::currentId()]))
                        ->required()
                        ->visible(fn () => filament()->getTenant()->stores()->count() > 1),
                ])
                ->modalSubmitActionLabel('Add and show code')
                ->action(function (array $data) {
                    CounterResource::run(function () use ($data) {
                        $vendor = filament()->getTenant();
                        $result = app(StockTakeCounters::class)->add(
                            auth()->user(),
                            $vendor,
                            $data['name'],
                            $data['phone'] ?? null,
                            $data['stores'] ?? array_filter([ActiveStore::currentId()]),
                        );

                        CounterResource::showCode($result['counter']->name, $result['code']);
                    });
                }),

            Action::make('qr')
                ->label('Sign-in QR code')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->modalHeading('The shop\'s Stock Take sign-in')
                ->modalContent(function () {
                    $url = app(StockTakeCounters::class)->joinUrl(filament()->getTenant());

                    return view('filament.vendor.stock-take-sign-in-qr', [
                        'url' => $url,
                        'svg' => new HtmlString(QrCode::svg($url, 280, 2)),
                    ]);
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            Action::make('newQr')
                ->label('Make a new QR code')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Make a new QR code?')
                ->modalDescription('The printed one stops working at once. Counters already signed in are not affected. Do this if the QR code has reached someone it should not have.')
                ->action(function () {
                    CounterResource::run(function () {
                        app(StockTakeCounters::class)->rotateJoinKey(auth()->user(), filament()->getTenant());
                        \Filament\Notifications\Notification::make()->title('New QR code made')->body('Open "Sign-in QR code" to see and print it.')->success()->send();
                    });
                }),
        ];
    }
}
