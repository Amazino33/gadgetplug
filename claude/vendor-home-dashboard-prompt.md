## Context

**GadgetPlug** — multi-vendor, multi-branch gadget retail platform for the Nigerian market. Stack: Laravel 12, Filament 5.4 (Schemas API), Livewire 4 + Volt, Blade, Tailwind, Spatie (teams-based tenancy, permissions, activity-log, media-library), Pest 4, MySQL. Vendors are Spatie teams and are the Filament tenant boundary. **Store is a sub-scope of Vendor** (multi-branch); per-store stock lives in `product_store_stock`. A separate React SPA handles the POS/counter till — **out of scope here except to deep-link into it.**

I'm rebuilding the **vendor panel landing/home page** so that when a vendor logs in — almost always on a phone — they get a warm, POS-cashier-style greeting and a set of **big, touch-friendly action cards**, instead of a wall of navigation. This is the screen they see first, every day.

Think like a senior UX designer building for a busy Nigerian gadget-shop owner on a mid-range Android phone: large tap targets, high contrast, no cramped tables, one clear icon per action.

---

## Locked decisions (do not re-litigate — build to these)

1. **This replaces the vendor panel's default landing page** — the first page a vendor lands on after login in the vendor panel.
2. **Greeting header**: time-of-day aware — "Good morning / afternoon / evening, {vendor display name} 👋 — what would you like to do today?". Hardcode the timezone to **Africa/Lagos (WAT)** — the whole platform is Nigeria; do not build per-vendor timezone handling.
3. **Tiered layout, top to bottom:**
   - a) Greeting header
   - b) **Live stat strip** — today's headline numbers (sales total, order count, low-stock count)
   - c) **Store-context switcher** — shown **only if the vendor has more than one branch**; it sets the active store that the actions and figures scope to
   - d) **Hero action cards** (biggest)
   - e) **Quick-action cards** (secondary, still big)
   - f) **Alerts section** (tappable chips)
4. **v1 scope is LEAN: selling + stock + price only.** Do **not** add debts, expenses, or cash-at-hand tiles in v1 — but leave a clean, obvious extension point (e.g. a config-driven tile registry) so they can be added later without a rewrite.
5. **Hero cards (v1):**
   - **Record Sale** → launches the existing **POS (React SPA)**. Find how the POS is entered today and deep-link to it. Do not build a new sale flow.
   - **Today's Sales & Profit** → the glance-and-go summary.
   - **New Orders** → online/POD orders awaiting fulfilment.
6. **Quick-action cards (v1):**
   - **Change Price** (see Phase 2 — the one genuinely new interactive flow)
   - **Add Product** → deep-link to the existing product create screen
   - **Record Purchase / Restock** → deep-link to the existing procurement/stock-in screen (if it exists — flag if not)
7. **Alerts section (v1):** low / out-of-stock count, and new unfulfilled orders count — each tappable through to the filtered list.
8. **Most tiles are deep-links to existing Filament resources — reuse, do not rebuild.** The only net-new UI is the home page shell itself, the stat strip / alerts aggregation, and the Change Price flow.
9. **Permission-gated at the policy layer**, not just hidden in the UI. A reduced-privilege role (e.g. storekeeper / counter staff) sees a smaller tile set (no price changes) while the owner sees everything. Enforce in policies/gates, consistent with the defense-in-depth principle already used across the app.
10. **Honest labeling** — any figure that cannot be scoped to the selected store must label itself as a whole-business figure rather than silently ignoring the store filter.
11. **Mobile-first**: 2-column grid on phones, large rectangular cards (min ~64px tall), generous spacing, high contrast, Tailwind utilities consistent with the existing panel.

---

## Out of scope (do not touch)

- **POS internals** (the React SPA) — only deep-link into it.
- **Debts, expenses, cash-at-hand** tiles — v2.
- Any new payment, ledger, or settlement logic.
- Schema changes to products/orders/stock **unless a real gap is found** — in which case flag it and propose a migration; do not silently work around it.
- Desktop-specific polish beyond normal responsive behaviour.
- No new package installs unless something genuinely required is missing (flag first).

---

## Phase 0 — Recon first (read-only, HARD STOP)

Do **not** write any code yet. Read the actual codebase and report back on:

1. **Vendor panel home**: what is the current landing page after vendor login? Default Filament dashboard, a custom Page, or widgets? What's the panel's route prefix and how is the home/dashboard registered?
2. **POS launch**: how is the React POS entered from the panel today — a route, a button, an external URL? This is what "Record Sale" will deep-link to.
3. **Products**: the product create screen/route (for "Add Product"), and — critically — **is selling price stored per-vendor (on the product) or per-store (on `product_store_stock`)?** Where does **cost** live? This decides the Change Price flow's branch behaviour.
4. **Procurement**: is there an existing Purchase / Restock / stock-intake resource for "Record Purchase"? If not, flag it — don't invent one in v1.
5. **Orders**: how are unfulfilled / new orders identified (status field, POD flow)? This drives the "New Orders" tile and its count.
6. **Sales & profit aggregation**: is there an existing service, query class, or widget that already computes today's sales and profit? **Reuse it.** What's the cost basis for profit (cost snapshot on `order_items` vs. live product cost)?
7. **Stock & low-stock**: where does stock quantity live (`product_store_stock`), and is there a reorder-point / low-stock-threshold column to drive the low-stock alert?
8. **Multi-branch context**: how is the vendor's active store currently selected/stored (session, tenant scope)? Is there already a store switcher to reuse?
9. **Roles/permissions**: what Spatie roles exist inside the vendor panel (owner vs. storekeeper/staff), and which existing policies/gates should the tiles hook into?
10. **Greeting name**: where is the vendor's display name / owner name stored, for the greeting.

**Then stop.** Give me a short findings summary and flag every gap (especially: no procurement resource, price scoping, no low-stock threshold column). Wait for my confirmation on the approach before Phase 1.

---

## Phase 1 — Build the home page (HARD STOP after this)

Build the landing page per the locked decisions:

- The **home page shell** (recommend a custom Filament Page rather than a stock widget dashboard, so we control the big-button phone layout — confirm in your Phase 0 findings).
- **Greeting header** with WAT time-of-day logic and the vendor's name.
- **Live stat strip** — today's sales, order count, low-stock count, scoped to the active store; reuse the aggregation found in Phase 0. If any figure can't be store-scoped, label it "whole business".
- **Store-context switcher** — only rendered when the vendor has >1 store; selecting a store re-scopes the strip, alerts, and store-scoped actions.
- **Hero + quick-action card grid** — driven by a small **tile registry** (an array/config of tiles with label, icon, target route/URL, and required permission) so tiles are added/removed/gated in one place. This is the extension point for v2 (debts/expenses/cash).
- Wire tiles to their targets: Record Sale → POS; Add Product / Record Purchase / New Orders → existing resources; Change Price → the Phase 2 flow (stub the route for now).
- **Alerts section** — low/out-of-stock and new-orders chips, each linking to the filtered list.
- **Permission-gate every tile at the policy/gate layer**, and have the registry only render tiles the current user is authorised for.
- Responsive, big-button Tailwind styling consistent with the existing panel.

Stop and let me review the page before Phase 2.

---

## Phase 2 — The Change Price flow

The one net-new interactive piece. Build it (Livewire/Volt component or a dedicated Filament page — match repo conventions):

1. **Search** — a large search box filtering the active store's catalogue by name / SKU / barcode, with fat, tappable result rows.
2. **Pick** — tapping a product shows a card with **current cost, current selling price, and current margin** (so the vendor never blindly sells below cost).
3. **Edit** — a large price field plus quick ±₦500 / ±₦1,000 stepper buttons for fast thumb edits.
4. **Guard** — if the new price is below cost, **warn clearly and require explicit confirmation** before saving (don't hard-block — vendors sometimes clear stock at a loss, but they must mean it).
5. **Branch scope** — behave according to the Phase 0 finding: if price is per-store, offer "this branch only / all my branches"; if price is per-vendor, apply once and say so plainly.
6. **Save** — update within a DB transaction and write to the Spatie **activity-log** (who changed what price, from → to, when) for auditing.
7. **Confirm state** — clear success feedback and an easy path back to change another price.

Gate the whole flow behind the price-change permission from Phase 0.

---

## Guardrails (apply throughout)

- **Recon before edit** — no blind code (that's Phase 0).
- **Flag before fix** — surface any bug or surprise and confirm with me before changing it.
- **Reuse over parallel** — reuse existing aggregation services, permission structures, resource routes, and the activity-log; do not build parallel equivalents.
- **Authorization at the policy layer**, not nav visibility alone.
- **Honest labeling** on any non-store-scoped figure.
- **Pest 4 suite must pass before any commit.**

---

## Deliverables

1. Phase 0 findings summary + flagged gaps (before any code).
2. The vendor home page: greeting, stat strip, store switcher, tiered card grid, alerts — driven by a permission-aware tile registry.
3. Deep-links wired to existing screens; Record Sale → POS.
4. The Change Price flow (search → pick → margin-aware edit → guarded save → activity-logged).
5. Any migration you had to propose (flagged, not silent).
6. Pest tests (or a manual test checklist) covering: greeting time-of-day, tile permission gating (owner vs. staff), store-scoped stat strip, low-price confirmation guard, and change-price activity-log entry.

Work step by step. Show me the Phase 0 findings and your recommended approach first, then stop for my confirmation before building.
