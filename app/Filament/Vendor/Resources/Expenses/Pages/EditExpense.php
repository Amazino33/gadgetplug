<?php

namespace App\Filament\Vendor\Resources\Expenses\Pages;

use App\Filament\Vendor\Resources\Expenses\ExpenseResource;
use App\Models\FinancialAccount;
use App\Services\FinancialLedger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Deleting a posted expense would silently understate the ledger
            // it already fed — only an unposted (still-editable) record can
            // be removed at all.
            DeleteAction::make()
                ->visible(fn () => ! $this->record->isPosted()),
        ];
    }

    // Covers "paid from" being added on a later edit, not just at creation —
    // posting only fires the first time financial_account_id becomes set
    // (isPosted() guards re-firing on every subsequent save).
    protected function afterSave(): void
    {
        $expense = $this->record;

        if ($expense->financial_account_id && ! $expense->isPosted()) {
            $account = FinancialAccount::findOrFail($expense->financial_account_id);

            FinancialLedger::postEntry(
                account: $account,
                direction: 'out',
                amount: (float) $expense->amount,
                source: $expense,
                description: "Expense — {$expense->category}" . ($expense->description ? ": {$expense->description}" : ''),
                occurredAt: $expense->incurred_at,
                createdBy: auth()->id(),
            );

            $expense->update(['posted_at' => now()]);
        }
    }
}
