<?php

namespace App\Filament\Vendor\Resources\Expenses\Pages;

use App\Filament\Vendor\Resources\Expenses\ExpenseResource;
use App\Models\FinancialAccount;
use App\Services\FinancialLedger;
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

    // Choosing an account and saving is what "records this expense as paid"
    // — no separate action, per how this form is scoped (unlike the
    // procurement-leg/order-delivery payment actions in the cost-capture
    // work, which deliberately kept the account picker out of the form).
    protected function afterCreate(): void
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
