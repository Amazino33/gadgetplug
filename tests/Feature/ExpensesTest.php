<?php

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function expenseTestVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Expense Test Store ' . uniqid()]);

    return compact('owner', 'vendor');
}

function makeExpense(array $data, array $overrides = []): Expense
{
    return Expense::create(array_merge([
        'vendor_id'    => $data['vendor']->id,
        'category'     => 'advertising',
        'amount'       => 5000,
        'description'  => 'Facebook ads — August',
        'incurred_at'  => now()->toDateString(),
        'created_by'   => $data['owner']->id,
    ], $overrides));
}

test('an expense can be created for each of the three categories', function () {
    $data = expenseTestVendor();

    foreach (array_keys(Expense::CATEGORIES) as $category) {
        $expense = makeExpense($data, ['category' => $category]);
        expect($expense->category)->toBe($category);
    }

    expect(Expense::where('vendor_id', $data['vendor']->id)->count())->toBe(3);
});

test('amount, incurred_at, and posted_at are cast to real types', function () {
    $data = expenseTestVendor();
    $expense = makeExpense($data);

    expect((float) $expense->amount)->toBe(5000.0)
        ->and($expense->incurred_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($expense->posted_at)->toBeNull()
        ->and($expense->isPosted())->toBeFalse();
});

test('each vendor only ever sees its own expenses', function () {
    $dataA = expenseTestVendor();
    $dataB = expenseTestVendor();

    makeExpense($dataA);
    makeExpense($dataB);
    makeExpense($dataB);

    expect(Expense::where('vendor_id', $dataA['vendor']->id)->count())->toBe(1)
        ->and(Expense::where('vendor_id', $dataB['vendor']->id)->count())->toBe(2);
});

test('a posted expense\'s category and amount cannot be changed', function () {
    $data = expenseTestVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();
    $expense = makeExpense($data);
    $expense->update(['financial_account_id' => $account->id, 'posted_at' => now()]);

    expect(fn () => $expense->update(['amount' => 9999]))->toThrow(\LogicException::class);
    expect(fn () => $expense->update(['category' => 'other']))->toThrow(\LogicException::class);
});

test('setting financial_account_id and posted_at together for the first time does not trip the guard', function () {
    $data = expenseTestVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();
    $expense = makeExpense($data);

    // This is the real posting update — amount unchanged, only the posting
    // metadata is being set for the first time. Must not throw.
    $expense->update(['financial_account_id' => $account->id, 'posted_at' => now()]);

    expect($expense->fresh()->isPosted())->toBeTrue();
});

test('an unposted expense can still be freely edited', function () {
    $data = expenseTestVendor();
    $expense = makeExpense($data);

    $expense->update(['amount' => 7500, 'category' => 'logistics_other', 'description' => 'Bike repair']);

    expect((float) $expense->fresh()->amount)->toBe(7500.0)
        ->and($expense->fresh()->category)->toBe('logistics_other');
});

test('changes to an expense are recorded in the activity log', function () {
    $data = expenseTestVendor();
    $expense = makeExpense($data);
    $expense->update(['amount' => 6000]);

    $activity = Activity::where('subject_type', Expense::class)
        ->where('subject_id', $expense->id)
        ->where('event', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->vendor_id)->toBe($data['vendor']->id)
        ->and((float) ($activity->changes()['attributes']['amount'] ?? 0))->toBe(6000.0);
});
