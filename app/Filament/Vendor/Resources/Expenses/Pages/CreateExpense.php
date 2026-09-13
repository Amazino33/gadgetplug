<?php

namespace App\Filament\Vendor\Resources\Expenses\Pages;

use App\Actions\Finance\RecordExpenseAction;
use App\Filament\Vendor\Resources\Expenses\ExpenseResource;
use App\Models\FinancialAccount;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['vendor_id']  = filament()->getTenant()->id;
        $data['created_by'] = auth()->id();

        return $data;
    }

    /**
     * Choosing an account and saving is what "records this expense as paid" —
     * no separate action, per how this form is scoped.
     *
     * The posting itself lives in RecordExpenseAction, which the till uses too.
     * It used to be written out here, and a second copy at the counter would
     * have been a second chance to forget the ledger.
     */
    protected function afterCreate(): void
    {
        $expense = $this->record;

        if (! $expense->financial_account_id || $expense->isPosted()) {
            return;
        }

        app(RecordExpenseAction::class)->postToLedger(
            $expense,
            FinancialAccount::findOrFail($expense->financial_account_id),
            auth()->user(),
        );
    }
}
