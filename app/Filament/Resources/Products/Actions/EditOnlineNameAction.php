<?php

namespace App\Filament\Resources\Products\Actions;

use App\Models\Product;
use App\Services\Catalogue\OnlineNameSuggester;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Gate;

// "Online name & SEO" on All Products: the customer-facing name and the search
// description, and nothing else. The internal name stays the store's.
//
// Policy-checked (ProductPolicy::updateOnlineName) on mount, on call and again
// in before(). Writes exactly two columns; Product's activity log records both
// with the causer. A resold listing has no online name of its own — it shows
// its supplier's — so the action is offered on the supplier's row instead.
class EditOnlineNameAction
{
    public static function make(): Action
    {
        return Action::make('editOnlineName')
            ->label('Online name & SEO')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->modalHeading(fn (Product $record): string => 'Online name — '.$record->name)
            ->modalDescription('What customers see on the storefront and search engines index. The store keeps its own name for the till, receipts and stock.')
            ->visible(fn (Product $record): bool => ! $record->isLinked())
            ->authorize(fn (Product $record): bool => ! $record->isLinked() && Gate::allows('updateOnlineName', $record))
            ->fillForm(fn (Product $record): array => [
                'online_name'      => $record->online_name,
                'meta_description' => $record->meta_description,
            ])
            ->schema([
                TextInput::make('online_name')
                    ->label('Online name')
                    ->maxLength(150)
                    ->placeholder('Blank shows the internal name')
                    ->helperText('e.g. "itel 2160 Dual SIM Keypad Phone – Black". Leave blank to show the internal name.')
                    // Fills the field only — the editor reviews it, and nothing
                    // is saved until they press Save.
                    ->suffixAction(
                        Action::make('suggestName')
                            ->icon('heroicon-o-light-bulb')
                            ->tooltip('Suggest a name from brand, specs and category')
                            ->action(fn (Set $set, Product $record) => $set(
                                'online_name',
                                app(OnlineNameSuggester::class)->suggest($record),
                            )),
                    ),

                Textarea::make('meta_description')
                    ->label('Meta description')
                    ->maxLength(300)
                    ->rows(3)
                    ->helperText('Optional. The snippet search engines show under the name. Blank uses the product description.'),
            ])
            ->before(function (Product $record): void {
                Gate::authorize('updateOnlineName', $record);
                abort_if($record->isLinked(), 403, 'Resold listings show their supplier\'s online name.');
            })
            ->action(function (array $data, Product $record): void {
                $record->update([
                    'online_name'      => filled($data['online_name'] ?? null) ? trim($data['online_name']) : null,
                    'meta_description' => filled($data['meta_description'] ?? null) ? trim($data['meta_description']) : null,
                ]);

                Notification::make()->title('Online name saved')->success()->send();
            });
    }
}
