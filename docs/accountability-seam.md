# Accountability seam — contract for the financial layer

The stock accountability system records shortages, who is answerable, and what
money comes back. It does **not** post into the financial system. This document
is the contract the five-layer financial work should bind to.

Nothing here writes to `financial_ledger_entries`, `financial_accounts` or
`expenses`. Read it, or listen to the events, and post on your side.

---

## Conventions (aligned to `FinancialLedgerEntry`, not invented here)

- **All amounts are non-negative.** Direction carries the sign, never the number.
- **`direction`** is `in` or `out`, the same vocabulary `FinancialLedger::postEntry()`
  already uses.
- **Money figures are naira**, `decimal(12,2)`. Products store naira, not kobo.
- **Cost-sensitive fields** are marked. They must be gated behind the existing
  `view_cost_price` permission (`ProductForm::canSeeCostPrice()`). Do not add a
  new permission for this.

## The one rule that governs everything

**A shortage is only a loss once the value is actually gone.**

- A **charge** is a receivable from an employee. Nothing hits the P&L.
- A **recovery** reduces that receivable. Not income — no sale happened.
- A **write-off** is the only event that recognises an expense, and only ever
  **at cost**.

**Unrecovered margin is never an expense.** Margin that was never earned cannot
be lost. It is reported as `margin_forgone` for completeness and must not be
booked.

## Cost-first allocation

A recovery repairs replacement cost before it touches margin. Given a charge of
₦15,900 (cost ₦7,710, margin ₦8,190):

| Recovered | `recovered_cost` | `recovered_margin` |
|---|---|---|
| ₦5,000 | ₦5,000 | ₦0 |
| ₦7,710 | ₦7,710 | ₦0 |
| ₦10,000 | ₦7,710 | ₦2,290 |

If that case is then abandoned, the loss is the **unrecovered cost only** —
₦2,710 in the first row, not ₦7,710 and never ₦15,900.

---

## Events

Dispatched synchronously on each ledger write. Namespace
`App\Events\Accountability`.

### `ShortageCharged`

No `direction` — nothing posts. A receivable was created.

| Field | Type | Notes |
|---|---|---|
| `vendorId` `caseId` `productId` `storekeeperId` | int | |
| `chargedCost` | float | **cost-sensitive** |
| `chargedMargin` | float | **cost-sensitive** |
| `chargeTotal` | float | retail; safe to display |
| `priceFallback` | bool | true = no retail price, charged at cost |
| `occurredAt` | string | `Y-m-d` |

### `ShortageRecovered` — `direction: in`

| Field | Type | Notes |
|---|---|---|
| `entryType` | string | `recovery_cash` \| `recovery_salary` \| `recovery_manual` |
| `amount` | float | non-negative, this event only |
| `recoveredCost` | float | **cost-sensitive**, cumulative for the case |
| `recoveredMargin` | float | **cost-sensitive**, cumulative for the case |
| `outstandingAfter` | float | remaining on the case |

`recoveredCost` / `recoveredMargin` are **cumulative case totals after this
event**, not this event's slice — allocation is a property of the case, not of
one payment.

### `ShortageWrittenOff` — `direction: out`

| Field | Type | Notes |
|---|---|---|
| `lossAtCost` | float | **the expense.** Net of recoveries. **cost-sensitive** |
| `marginForgone` | float | **not an expense.** Reported only |
| `origin` | string | `disposition` (written off outright) \| `conversion` (charge abandoned) |

---

## Read model

`App\Services\Reporting\ShrinkageReadModel`

- `forCase(InventoryShortageCase $case): array` — per-case figures.
- `forVendor(int $vendorId, ?CarbonInterface $from, ?CarbonInterface $to): array`
  — store totals.

`forVendor()` returns, with a `direction` map alongside:

| Key | Direction | Meaning |
|---|---|---|
| `shrinkage_loss_at_cost` | `out` | The expense. Cost only |
| `recovered_cost` | `in` | Reduces the loss |
| `recovered_margin` | `in` | Reduces the loss. **Not income** |
| `net_shrinkage_at_cost` | — | `shrinkage − recovered_cost − recovered_margin`. What a P&L should carry |
| `margin_forgone` | — | Never earned. Do not book |
| `outstanding_from_staff` | — | A receivable, not a P&L line |

`from`/`to` filter on case creation date — when the loss was established, not
when it was disposed.

---

## What must exist before binding

Three gaps found while building this. None are blockers for the seam; all are
blockers for actually posting.

**1. No account can receive shrinkage.** `financial_accounts.type` is
`enum('bank','cash')`. Stock going missing moves no cash, so posting to either
misstates a real balance. Needs either a new account type or routing through
`expenses`.

**2. `expenses.category` has no shrinkage member.** It is
`enum('advertising','logistics_other','other')`, deliberately narrow — its
migration comment says so explicitly, to stop the report double-counting. Adding
`shrinkage` means a matching term in `FinancialReportService`.

**3. `FinancialReportService::report()` has no shrinkage line.** Net profit is
currently:

```
revenue − product_cost − inbound_logistics − outbound_delivery
        − advertising − logistics_other − other
```

Adding `− net_shrinkage_at_cost` is the whole change, *provided* point 2 does not
route it through an expense category that is already subtracted — that would
count it twice.

**Watch for double-counting.** When a count corrects stock downward, the goods
leave `products.stock_quantity` and the `inventory_ledgers` row records it. If
`product_cost` in the P&L is ever derived from stock movement rather than from
sold `order_items`, shrinkage would already be inside it and adding
`net_shrinkage_at_cost` would subtract it twice. It is currently derived from
sold order items, so there is no overlap today — re-check if that changes.

---

## Not built, by decision

- No posting into the financial system. Read model and events only.
- No automatic netting against remittance or cash held. Recoveries are entered
  manually in v1; the automatic pull is a future seam.
- No payroll integration. `recovery_salary` records an amount; it does not talk
  to payroll.
