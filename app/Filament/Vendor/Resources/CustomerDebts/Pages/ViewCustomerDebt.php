<?php

namespace App\Filament\Vendor\Resources\CustomerDebts\Pages;

use App\Filament\Vendor\Resources\CustomerDebts\CustomerDebtResource;
use App\Models\PosCustomer;
use App\Services\Pos\CustomerDebtService;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One customer's whole story, oldest first, with the balance after every line.
 *
 * A statement rather than a table of movements: the running figure is what makes
 * a single row legible — "paid ₦4,000" means nothing without what it left behind.
 */
class ViewCustomerDebt extends Page
{
    protected static string $resource = CustomerDebtResource::class;

    protected string $view = 'filament.vendor.pages.customer-debt-history';

    /**
     * The id, not the model. Livewire binds the route parameter to a public
     * property of the same name, so a typed `Model $record` blows up when it
     * arrives as an integer — the customer is resolved from this instead.
     */
    public int $customerId;

    public function mount(int|string $record): void
    {
        // Resolved through the resource's own query, so a customer belonging to
        // another vendor is a 404 rather than a page — the tenant scope is the
        // authorisation here, not an afterthought on top of it.
        $this->customerId = (int) CustomerDebtResource::getEloquentQuery()
            ->whereKey($record)
            ->firstOrFail()
            ->id;
    }

    protected function customer(): PosCustomer
    {
        return CustomerDebtResource::getEloquentQuery()
            ->whereKey($this->customerId)
            ->firstOrFail();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->customer()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->customer()->phone ?: null;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $debt = app(CustomerDebtService::class);

        return [
            'customer' => $this->customer(),
            'summary'  => $debt->summary($this->customerId),
            'history'  => $debt->history($this->customerId),
        ];
    }
}
