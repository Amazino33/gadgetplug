# Till session / cash-up sync contract

What the React POS talks to for the cashier's trading day. This is the server
side's promise: the till implements against this, not against the server's
internals.

**One session is the whole shift.** `PosSession` is the single primitive — it is
opened with a counted float, it is what every sale is stamped with, it is what
gets counted at the end, and it is what the Z-report is written from. There is no
separate cash-up record and no second set of endpoints. A day has one open, one
close, and one cash count.

All routes sit under `/api/pos/`, behind `auth:sanctum` and `EnsurePosVendorAccess`.
Every request carries `vendor_id`; a vendor the token does not belong to is `403`.

## The rules that shape this contract

**Blind entry.** The server never returns `expected_cash`, `expected_terminal`,
`cash_variance`, `terminal_variance` or `breakdown` until both counts have been
submitted. Those keys are *absent* from the JSON, not null. The till must not
treat a missing key as zero — it means "not yet earned", and a till that displays
a zero there has quietly defeated the control.

The POS may show its own provisional figure from its local cache. The
authoritative numbers are the ones that come back from `close`.

**The cashier counts; the manager explains.** There is no rectification endpoint
on this API and there will not be one. A cashier who needs to account for money —
transport paid out of the drawer, a sale rung on the wrong button — says so in
`notes` on close. A manager turns that into a rectification from the panel. The
person a shortage names is never the person who writes it off.

**One open, one close, per cashier per branch per trading day.** Enforced by a
unique database key on (cashier, store, business_date), not merely checked.
Retries are safe.

**A second device joins the day, it does not end it.** `open` returns the session
already running rather than closing it and starting another — which is what it
used to do, silently, to whichever till opened first.

**The trading day is the store's wall clock** (`config('reporting.timezone')`,
Africa/Lagos), not UTC. A sale at 00:30 Lagos belongs to the day the shopkeeper
thinks it does. The till should display whatever `business_date` the server
returns rather than computing its own.

---

## `GET /api/pos/sessions/active`

The day in progress, plus anything left open behind it.

**Query:** `vendor_id`

```json
{
  "business_date": "2026-09-11",
  "store_id": 4,
  "session": { "id": 12, "status": "open", "opening_float": "20000.00", "...": "" },
  "unclosed": [ { "id": 9, "business_date": "2026-09-10", "status": "open" } ]
}
```

`session` is `null` when the day has not been opened. `unclosed` lists earlier
days this cashier opened and never closed — offer to close them rather than
letting them hang.

## `POST /api/pos/sessions/open`

| Field | Rules |
|---|---|
| `vendor_id` | required, integer |
| `opening_float` | **required**, numeric, `>= 0` |
| `terminal_id` | optional, string ≤ 100 — the cashier's Moniepoint terminal |
| `idempotency_key` | optional, string ≤ 120 |

`201` with `{ "session": {...}, "vendor_settings": {...} }` on success. Opening is
also the once-a-shift moment the receipt layout is refreshed, so cache
`vendor_settings` when it comes back.

`200` with the running session if the day is already open — a retry, or a second
till, gets the same day rather than a second one.

`409` if the day has already been cashed up.

`422` if the till is assigned to no branch. The message is displayable as-is.

## `POST /api/pos/sessions/{session}/close`

| Field | Rules |
|---|---|
| `counted_cash` | **required**, numeric, `>= 0` |
| `counted_terminal` | **required**, numeric, `>= 0` |
| `notes` | optional, string ≤ 1000 — the cashier's account of the day |
| `idempotency_key` | optional, string ≤ 120 |

Both counts are required in one request, deliberately: submitting them
separately would let a cashier see one leg's variance and tune the other.

`404` if the session belongs to another cashier. Closing your own drawer needs
no permission — it is the job — but emphatically not somebody else's.

`200` on success:

```json
{
  "session": {
    "id": 12, "status": "pending_review",
    "counted_cash": "97000.00", "expected_cash": "100000.00",
    "cash_variance": "-3000.00", "terminal_variance": "0.00"
  },
  "breakdown": {
    "expected_cash": 100000,
    "cash_lines": [
      { "key": "opening_float", "label": "Opening float", "amount": 20000 },
      { "key": "cash_sales",    "label": "Cash sales",    "amount": 80000 }
    ],
    "terminal_lines": [ "..." ],
    "context": { "debt_rung": 40000, "gross_sales": 140000, "sales_count": 12 },
    "warnings": []
  },
  "warnings": [],
  "report": { "terminal_counted": "48000.00", "terminal_variance": "-2000.00", "...": "" }
}
```

`report` is the Z-report, written by this call. It carries both legs and the
cashier's note, and it is the slip that gets signed — so the paper record is the
reconciliation rather than a list of system sales. Printing it later is
`GET /api/pos/sessions/{session}/z-report`; it is not generated anywhere else.

**Show the lines, not just the answer.** `cash_lines` and `terminal_lines` sum to
their leg's expected figure, in the order applied. A cashier told only "you are
short ₦3,000" cannot check the claim; the first thing they ask is why the drawer
does not equal the day's sales, and `context.debt_rung` is usually the answer.

`warnings` is a list of display-ready strings. Non-empty means the expected
figure may be understated — unassigned sales, or sales with no completion time.
Show them; a shortage caused by a data gap must not be put to a cashier as
missing money.

**Retries:** replaying the same `idempotency_key` returns `200` and the original
result. A *different* close against an already-closed day returns `409` with the
session as it stands. The offline queue can therefore replay safely and should
treat `409` as "done, stop retrying".

## `GET /api/pos/sessions/history`

**Query:** `vendor_id`. Last 14 cash-ups for this cashier at this branch.

Each row carries the frozen figures plus:

- `resolved_cash_variance` / `resolved_terminal_variance` — what is *still*
  unexplained after any manager rectifications. `null` until counts are in.

The frozen `cash_variance` is the gap as first presented and never moves. The
resolved figure is derived on read and shrinks as a manager accounts for parts of
it. Show the resolved one as the live number; keep the frozen one for history.

---

## Offline behaviour

Open and close both tolerate a till that has been disconnected. The expected
figures are computed server-side from whatever has actually synced, so **a sale
still sitting in IndexedDB will read as a shortage**. `breakdown.context.sales_count`
is how a manager tells an unsynced till from a light drawer — flush the sale queue
before closing, and surface `sales_count` next to the variance.

## Not in this contract

Bank / Moniepoint settlement matching (v2), multi-shift and mid-day handovers,
shared terminals, and physical stock counting. Cash-up closes *money*
reconciliation only: goods that leave without ever being rung into the POS
produce no sale, therefore no expected cash, therefore no variance.
