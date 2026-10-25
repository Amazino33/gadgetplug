<?php

namespace App\Filament\Resources\LegalPolicies\Pages;

use App\Filament\Resources\LegalPolicies\LegalPolicyResource;
use App\Models\LegalPolicy;
use Database\Seeders\LegalPolicySeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ListLegalPolicies extends ListRecords
{
    protected static string $resource = LegalPolicyResource::class;

    public function mount(): void
    {
        parent::mount();

        if (LegalPolicy::count() === 0) {
            (new LegalPolicySeeder())->run();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New policy'),
            Action::make('seed_defaults')
                ->label('Restore Default Policies')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Load Default Policies')
                ->modalDescription('This will insert any missing default policies (Privacy Policy, Terms, Refund Policy, Affiliate Agreement) without overwriting your existing policies.')
                ->action(function () {
                    (new LegalPolicySeeder())->run();
                    Notification::make()
                        ->title('Default policies checked and loaded')
                        ->success()
                        ->send();
                })
                ->visible(fn () => LegalPolicy::count() < 4),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Order')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_published')->label('Published'),
            ])
            ->recordActions([
                Action::make('view_page')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (LegalPolicy $record): string => route('legal.show', $record->slug))
                    ->openUrlInNewTab(),

                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
