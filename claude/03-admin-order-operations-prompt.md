# Prompt 3 of 3 — Admin order operations: per-vendor orders, logistics-only delivery, platform dispatch, global WhatsApp routing

Copy everything below into Claude Code in VS Code inside the GadgetPlug repo.

> Run after Prompt 2 (catalogue/SEO) is finished. Prompt 2 adds `products.online_name`, `Product::displayName()` and the `order_items.product_name` / `product_display_name` snapshots — reuse them here, don't add parallel versions.

## Branch & testing rules (read first)

- Work on **`feature/admin-order-ops`**, branched from `feature/catalogue-seo` (which sits on `hotfix/paystack-messaging`).
- Commit locally per phase. **Do NOT push and do NOT merge to `main`.** I will test all three branches end to end before anything is pushed.
- Test baseline: use the failure list from the end of Prompt 2 (after the "visible-product fixtures get a photo" test commit). "No new failures" against that baseline is the bar. List any pre-existing failures by name in every phase summary; don't fix them without asking.
- Never run migrations or commands against production. Local only.

---

## Context

GadgetPlug — Laravel 12, Filament 5.4 (Schemas API), Livewire 4, Volt, Spatie (teams, permissions, activity-log, media-library), Filament Shield, Pest 4, MySQL. Storefront on `gadgetplug.ng`; admin and vendor panels on `gadgetplug.com.ng`. Pay-on-delivery (POD) dominates; Paystack for prepaid. Delivery is currently only within Uyo, Mkpat Enin and Eket (`orders.local_government`).

The platform (admin) is taking over online-order operations: one global WhatsApp number, platform-owned dispatch and delivery, vendors and stores only pack and hand over.

Findings from the read-only recon (re-verify before editing):

- **One `Order` per checkout**, `order_items` carry `vendor_id`. Mixed-vendor carts are allowed. `OrderObserver` reads `items()->value('vendor_id')`, so only the **first** vendor is notified, and revenue (and its reversal) is booked to the first vendor for the **whole** total (`RecognizeOrderRevenueAction.php:168-190`).
- **Statuses** (MySQL ENUM, no state machine): `pending, confirmed, paid, shipped, delivered, cancelled, paid_but_failed_stock`. UI shows `shipped` as "Dispatched".
- **Side effects all live in `OrderObserver::updated`** and fire from four vendor-panel entry points (ViewOrder, OrdersTable, ViewOrderItem, ListOrders):
  - `confirmed`/`paid`: stock reservation; Meta `Purchase` CAPI; customer/storekeeper/vendor notices; Paystack revenue recognised at `paid`.
  - `shipped`: `DispatchStockAction` — stock out, FIFO cost layers, `order_items.cost_total`.
  - `delivered`: POD revenue recognition (needs `payment_channel`), `BookSupplierPayableAction` (POD only), affiliate commission → `return_window`.
  - `cancelled`: release reservations, reverse revenue, reject affiliate commission.
- `ReleaseStaleReservationsJob` releases holds after 24h without cancelling the order.
- **Riders** (`DeliveryPerson`) and `LogisticsCompany` are vendor-scoped, with no login. Assignment is in the vendor panel. No proof of delivery. `/track` looks up by reference only.
- **Admin OrderResource** is read-only.
- **Messaging:** `MessagingService` with drivers Wawp (current, QR-linked), Cloud API (plain text only), `wa_link`, Termii. One global sender. `message_templates` holds free-text bodies per vendor. `delivery_messages.vendor_id` is NOT NULL. No template registry, no Meta webhooks. Storekeeper number is one per vendor (`vendor_notification_settings.storekeeper_whatsapp`) with a platform fallback; `stores.phone` exists but is unused. Vendor gets an in-app notice only.

## Locked decisions

**Orders**
- **Split checkout into one order per vendor under a parent `checkouts` record.** The checkout holds the customer, address, payment method, Paystack reference, idempotency key, the delivery fee and the grand total. Each child `Order` holds one vendor's lines and subtotal. The customer sees one checkout reference; vendors and stores see only their own order.
- Paystack pays the **checkout**; confirming payment marks every child order `paid`.
- **Meta `Purchase` fires once per checkout**, deduplicated on the checkout reference (not per child order).
- **Delivery fee belongs to the checkout and is platform income**, never vendor revenue.
- Historical orders: backfill one checkout per existing order (1:1). **Do not split historical mixed-vendor orders** — report how many exist and leave them for me to decide.

**Stock and money ("delivered is logistics only")**
- **Handover to the rider is the store's sale.** The storekeeper marks the order "Handed to rider" (keep the existing `shipped` enum value; relabel it). That action runs the existing `DispatchStockAction` — stock leaves the allocated store(s), COGS books — exactly as `shipped` does today. It is recorded with the store and the staff user. The store must **not** ring the same goods on POS.
- **Money books when it is actually collected.**
  - Paystack: revenue at `paid` (unchanged), but **per child order** on each vendor's own account.
  - POD: a separate admin action **"Record payment received"** (cash or transfer) sets `payment_collected_at` / `payment_channel` on the order and recognises revenue for that vendor order. Reuse the existing "Record Payment Received" logic (`ViewOrder.php:107`) rather than writing a new path.
  - VendorLink supplier payable books at the **same event as revenue** for both POD and Paystack (today it's POD-at-delivered only).
- **`delivered` does nothing to stock, revenue, COGS, supplier payable or any ledger.** It only records that the customer received the goods.
- The affiliate commission moving to `return_window` **stays at `delivered`** — it's a commission hold, not a book entry, and the return window should start when the customer has the item.
- Cancellation keeps its current reversal behaviour, applied per child order.
- These replace the earlier locked rules "POD revenue recognised at delivered" and "VendorLink cost booked at POD delivery". Cash-basis recognition is unchanged in principle.
- **Online sales do not enter Checkmate's expected cash.** POD cash is collected by the platform's rider, not the store till; Paystack is electronic. The handover must not create anything that the store cash reconciliation treats as cash-tender sales.

**One status path**
- All order status changes go through **one `OrderStatusService`** (or a small set of explicit actions) that owns allowed transitions, the actor's permission, and the side effects. The observer stops performing business side effects; the four vendor-panel entry points call the service or lose the transition.
- Who can do what:
  - Store/storekeeper (vendor panel, store-scoped): mark **Packed**, mark **Handed to rider**.
  - Admin dispatch: assign/reassign rider, cancel, record payment received, override delivered (requires a reason, logged).
  - Rider (signed link): mark **Delivered** with the customer's delivery code.
  - Vendors can **no longer** set `delivered`.
- Add a `packed` status between `confirmed`/`paid` and `shipped`.
- **Stale reservations:** never auto-release on `paid` orders. For unpaid POD orders still `confirmed` after 24h, alert admin instead of silently releasing; admin cancels manually (which releases).

**Dispatch**
- **One platform rider pool.** Reuse `DeliveryPerson`: `vendor_id` becomes nullable and `null` = platform rider. Existing vendor riders stay in the table but aren't offered for online orders.
- Riders have **no login or panel**. On assignment the rider gets a WhatsApp message with a **signed, expiring link** (48h) to a one-screen page: pickup store name/address/phone, customer name/phone/address, items, amount to collect for POD, and a **"Mark delivered"** form.
- **Delivery code:** a 4-digit code generated when the order is handed to the rider, stored **hashed**, sent to the customer in the dispatch message. "Mark delivered" requires it. 5 wrong attempts → link locks and admin is alerted. The code is never shown on `/track` or in the rider page.
- Admin can override "delivered" without the code, with a mandatory reason, logged.
- Rider assignment is per child order (a mixed checkout may have several pickups); each child order has its own code.
- New global permission `manage_dispatch` and a global role `dispatcher` (team_id null) that can access only Orders (dispatch) and Riders in the admin panel. `canAccessPanel('admin')` admits it. `super_admin` passes everything.

**Admin dispatch UI**
- Turn the read-only admin OrderResource into a dispatch board: tabs or filters for Needs packing / Packed / Handed to rider / Delivered / Payment pending / Cancelled; filters for vendor, store, area (`local_government`), payment method, rider.
- Row and view actions: assign/reassign rider, cancel, record payment received, override delivered, resend customer/rider message.
- Order view shows the checkout, the child order, store allocation, a status timeline (who/when for each step), messages sent, and payment state.
- Riders admin resource: create/edit platform riders (name, phone, WhatsApp, vehicle, active).
- Mobile-friendly card layout on small screens (admins will run dispatch from a phone).

**`/track`**
- Look up by checkout reference; show each child order's timeline (Confirmed → Packed → With rider → Delivered). No delivery code, no rider phone until the order is with the rider.

**Messaging**
- Keep **one global sender**. Move it to the **WhatsApp Cloud API** with Meta-approved templates; keep Wawp as a config-selectable fallback driver during transition.
- Add a template registry (table or config) mapping each internal template key → Meta template name, language, category (UTILITY), and ordered variables. The Cloud API driver sends template messages using it. Driver selection stays in config.
- Add the Meta webhook: verify token handshake + signature check; update `delivery_messages` status (sent/delivered/read/failed) from status callbacks; log inbound messages only (no auto-replies, no chat UI).
- `delivery_messages.vendor_id` becomes nullable for platform-level messages (rider, admin).
- **Routing:**
  - Customer: one message per **checkout** for confirmation (all items), then per child order for dispatch (with rider name and delivery code) and delivered.
  - Storekeeper: per child order, to the **store holding the allocation**: store's assigned storekeeper user phone(s) → `stores.phone` → vendor `storekeeper_whatsapp` → platform fallback. Uses the **internal** product name.
  - Vendor: per child order — in-app notice (existing) **plus** a WhatsApp `vendor_new_order` to the owner's WhatsApp.
  - Rider: assignment message with the signed link. Item names show the **internal name first, with the online name in brackets when it differs** (e.g. "ITEL 2160 OLD ONE (itel 2160 Dual SIM Keypad Phone)") — the rider collects from the shelf but hands over to a customer who knows the online name. Same rule on the rider page.
  - Admin ops: new-order alert to the platform ops number (reuse the platform fallback number setting or add `ops_whatsapp`).
- All automated sends use the queued job from Prompt 1.

## Out of scope

- Rider GPS/live location, delivery zones and fee-by-area pricing, route optimisation.
- Rider app, rider login or rider panel.
- **Rider/POD cash custody and remittance** (who holds the cash between delivery and "payment received") — v2.
- **Platform-to-vendor payouts/settlement** for money the platform collects — separate feature; report what exists, don't build it.
- Inbound WhatsApp chat handling or auto-replies.
- Splitting historical mixed-vendor orders.
- Checkmate changes, POS SPA, storefront redesign.

## Phase 0 — Recon (read-only)

1. Re-verify the recon findings above.
2. Every reader of `orders` that assumes one order = one checkout: customer account order history, `/track`, Meta CAPI, affiliate attribution, exports, reports, dashboards, daily summary, Checkmate, the daily backup pack. List them with file:line.
3. How online orders currently appear in **store** reports and in Checkmate (do they count toward a store's sales or cash?). Confirm the handover design won't put online POD money into a store's expected cash.
4. Any existing **commission / vendor payout / platform settlement** logic (the admin product resource has commission overrides): where commission is calculated and booked, and whether anything pays vendors out. Report only.
5. How `payment_channel` is captured today and every path that recognises POD revenue.
6. Store ↔ storekeeper user mapping (`store_user`?) and phone fields, for routing.
7. Signed-URL / magic-link pattern already in the codebase (e.g. the WhatsApp digest) to reuse for the rider link.
8. Report, flag any conflict with the locked decisions, then **stop for my review**.

## Phase 1 — Checkout split

- `checkouts` table + `orders.checkout_id`, backfill 1:1, checkout flow creates one order per vendor, per-vendor reservation, Paystack confirmation on the checkout (reuse Prompt 1's idempotent action), delivery fee on checkout, Purchase CAPI once per checkout, customer order history and `/track` by checkout.
- Pest: mixed cart → one checkout, N vendor orders, each with only its lines and subtotal; Paystack success marks all child orders paid exactly once; Purchase fires once; single-vendor checkout still works end to end; historical orders resolve through backfilled checkouts.
- **Stop for review.**

## Phase 2 — Status service and money decoupling

- `OrderStatusService`, `packed` status, transition rules and actor permissions, observer side effects moved into it, vendor-panel entry points rewired, "Handed to rider" = dispatch stock-out, delivered = logistics only, "Record payment received" recognises POD revenue + supplier payable per vendor order, Paystack revenue per child order, cancellation per child order, stale-reservation change.
- Pest: delivered changes no stock rows, ledger rows, revenue or payable; handover decrements the allocated store's stock and writes COGS; POD revenue appears only after "payment received", on the right vendor's account, for that vendor's subtotal only; Paystack revenue split correctly across vendors; supplier payable books with revenue for both channels; vendor user cannot set delivered; paid orders never auto-release; online POD sales don't appear in Checkmate expected cash.
- **Stop for review.**

## Phase 3 — Platform dispatch

- Nullable `DeliveryPerson.vendor_id`, Riders admin resource, `dispatcher` role + `manage_dispatch`, dispatch board and order view, assign/reassign, signed rider page, hashed delivery code with attempt lock, admin override with reason, `/track` timeline.
- Pest: dispatcher sees only Orders and Riders; rider link expires and is single-order; wrong code ×5 locks; correct code marks delivered and nothing financial happens; override requires reason and is logged; code never appears on `/track`.
- **Stop for review.**

## Phase 4 — Messaging: Cloud API templates and routing

- Template registry, Cloud API template sends, Meta webhook (verify + status updates + inbound log), nullable `delivery_messages.vendor_id`, routing rules above, vendor WhatsApp, admin ops alert, rider assignment and customer dispatch-with-code templates.
- **Print in chat a list of every template I must submit in Meta Business Manager**: internal key, suggested Meta name, category, language, body text with `{{1}}`-style variables, and a sample value for each variable. I'll submit them; keep Wawp as the active driver until I confirm approval.
- Pest: storekeeper message goes to the allocated store's storekeeper, not another branch; fallback chain works; mixed checkout sends one customer confirmation and one vendor message per vendor; webhook with bad signature is rejected; status callback updates `delivery_messages`.

## Gates

- Hard stop after Phases 0, 1, 2 and 3 for my review.
- Flag-before-fix for any bug or unexpected finding.
- Reuse existing actions (`ReserveStockAction`, `DispatchStockAction`, `ReleaseReservationAction`, `RecognizeOrderRevenueAction`, `BookSupplierPayableAction`, `MessagingService`) — no parallel versions.
- Authorization in policies, not just UI visibility.
- Full Pest suite passes before every commit.

## Deliverable

Working code plus a short summary per phase: migrations, files changed, the new status/transition table (who can move what), and for Phase 4 the Meta template list and the webhook URL + verify token setup steps.

## Final: combined manual test checklist

After Phase 4, print in chat **one manual test checklist covering all three prompts together**, in the order I should run it locally before pushing:

1. **Hotfix:** Paystack test payment with a reseller listing ends `paid` (not `paid_but_failed_stock`); closing the tab after paying still confirms via webhook (use Paystack test mode + a tunnel such as ngrok/Expose for the webhook); messages queue and send; redirect banner shows when `MESSAGING_REDIRECT_ALL_TO` is set.
2. **Catalogue:** reference Prompt 2's checklist rather than repeating it.
3. **Order ops:** mixed-vendor checkout → one checkout, one order per vendor, one customer confirmation, one storekeeper message per allocated store, one vendor message per vendor; storekeeper packs and hands over → stock drops at the right store; dispatcher assigns a platform rider → rider gets the link, customer gets the code; wrong code ×5 locks; right code marks delivered and **no** stock/ledger/revenue row changes; admin records POD payment → revenue and supplier payable appear on the right vendor only; cancel a paid child order → reversals for that vendor only; `/track` timeline shows no code; dispatcher can't reach catalogue or other admin pages; Checkmate expected cash unchanged by online POD orders.
4. A short **"what to watch after pushing"** list: queue worker running, Paystack webhook registered, Meta webhook verified, templates approved before switching off Wawp, and which env vars must be set in production (names only).
