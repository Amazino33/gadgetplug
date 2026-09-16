<?php

use App\Filament\Vendor\Pages\StoreSettlement;
use App\Filament\Vendor\Resources\CashSubmissions\Pages\ListCashSubmissions;
use App\Models\TillExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/../../Cash/Helpers.php';

uses(RefreshDatabase::class);

// Named for this feature rather than the generic "settlement", which the
// supplier-settlement suite already declares globally.
function checkmatePanel(array $ctx): void
{
    test()->actingAs($ctx['owner']);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);
}

test('the settlement page shows the branch position for the chosen period', function () {
    $ctx = handoffContext();
    checkmatePanel($ctx);

    Livewire::test(StoreSettlement::class)
        ->set('filters.preset', 'this_month')
        ->assertOk()
        ->assertSee('Unexplained shortage')
        ->assertSee($ctx['store']->name);
});

test('freezing a statement from the page records one against the branch', function () {
    $ctx = handoffContext();
    checkmatePanel($ctx);

    Livewire::test(StoreSettlement::class)
        ->set('filters.preset', 'this_month')
        ->callAction('generate');

    expect(App\Models\StoreSettlementStatement::where('store_id', $ctx['store']->id)->count())->toBe(1);
});

test('a storekeeper can declare money spent out of the till', function () {
    $ctx = handoffContext();

    test()->actingAs($ctx['keeper']);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);

    Livewire::test(ListCashSubmissions::class)
        ->callAction('logExpense', data: [
            'amount' => 7500,
            'reason' => 'Diesel for the generator',
        ]);

    $expense = TillExpense::where('store_id', $ctx['store']->id)->first();

    expect($expense)->not->toBeNull()
        ->and((float) $expense->amount)->toBe(7500.00)
        ->and($expense->logged_by)->toBe($ctx['keeper']->id)
        ->and($expense->reason)->toBe('Diesel for the generator');
});

test('declared spending comes off what the branch is expected to hand over', function () {
    $ctx = handoffContext();

    app(App\Actions\Cash\LogTillExpenseAction::class)->execute(
        loggedBy: $ctx['keeper'], store: $ctx['store'], amount: 7500, reason: 'Diesel',
    );

    checkmatePanel($ctx);

    // 50,000 taken, 7,500 declared — 42,500 expected back.
    Livewire::test(StoreSettlement::class)
        ->set('filters.preset', 'this_month')
        ->assertSee('42,500.00');
});
