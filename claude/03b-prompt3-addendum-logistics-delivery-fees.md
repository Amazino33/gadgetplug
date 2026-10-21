# Prompt 3 — Addendum: answers to Phase 0, external logistics companies, delivery locations & fees

Paste this into Claude Code as the reply to the Prompt 3 Phase 0 report. It **amends** Prompt 3 (`03-admin-order-operations-prompt.md`). Where this addendum and Prompt 3 disagree, this addendum wins.

Before pasting: put the supplied file `nigeria-locations.json` at `database/data/nigeria-locations.json` in the repo.

---

## 1. What changed and why

- **GadgetPlug does not have its own riders.** Deliveries go through **external logistics companies**. The "platform rider pool" in Prompt 3 is replaced by **platform logistics companies**.
- **Delivery fees become automatic, by location.** Customers have been ordering from local governments we can't cover at our cost. The fix: a delivery settings area where admin decides, for every state and LGA in Nigeria, whether we deliver there free, deliver for a fee, or don't deliver, with an interactive map showing distances from our stores. Checkout then charges the right fee automatically and blocks locations we don't serve.

## 2. Answers to the Phase 0 questions

1. **Delivery fee:** computed automatically from the delivery-location rules in section 4 below. It lives on the **checkout** (one fee per checkout).
2. **Platform income:** store the fee on the checkout only, plus the delivery cost we pay the logistics company (section 3). **No platform ledger yet** — that comes with the payouts/settlement feature.
3. **Vendor revenue account: option (b).** Each vendor gets a **"Due from GadgetPlug"** account (a `FinancialAccount` of a new type, vendor-owned). Online revenue posts there, not to the vendor's own cash/bank — POD when admin records "payment received", Paystack at `paid`. **Payout requests can only draw on the collected "Due from GadgetPlug" balance**; a delivered-but-uncollected POD order is not payable. Fix the payout "earned" calculation accordingly, and report every place that computes "earned"/"available for payout" before changing it.
4. **Reseller wallet purchases:** yes, they create a 1:1 checkout through the same flow.
5. **Vendor "Pay Delivery Cost":** hidden for online orders. GadgetPlug pays the logistics company.
6. **Legacy `pod` rows:** normalise to `pay_on_delivery` in the backfill migration and report the count.

## 3. Logistics companies (replaces "platform rider pool")

- Reuse `LogisticsCompany`: make `vendor_id` nullable; `null` = **platform logistics company**. Existing vendor-owned companies and `DeliveryPerson` rows stay untouched but are not offered for online orders.
- Platform company fields (add what's missing): name, contact person, dispatch phone / WhatsApp, active, notes. Optional: the areas they cover (a list of states/LGAs) so the assign dropdown can suggest companies that serve the order's LGA.
- **Assignment is per child order**: admin picks a platform logistics company and can optionally enter the rider's name and phone for that run (free text on the order; riders are the company's people, not our records).
- The **signed link and delivery code from Prompt 3 stay**: the link goes to the company's dispatch WhatsApp (and the rider's phone if entered). Whoever delivers enters the customer's 4-digit code to mark delivered. This matters more with outside companies, not less.
- **Delivery cost (what we pay the company):** on assignment, pre-fill from the destination's expected cost (section 4) and let admin adjust; store it on the child order. Add a "paid to company" flag + date. No ledger posting yet.
- Dispatch board shows per order: fee charged (from the checkout share) vs cost paid, so I can see whether a delivery made or lost money.
- `dispatcher` role and `manage_dispatch` permission stay as in Prompt 3; the Riders resource becomes a **Logistics companies** resource.

## 4. Delivery locations & fees (new)

### Data
- I've supplied `database/data/nigeria-locations.json`: **37 states (36 + FCT) and all 774 LGAs**, each LGA with a latitude/longitude reference point and Wikidata ID. Source: github.com/xosasx/nigerian-local-government-areas (MIT), coordinates from Wikidata. Already validated: per-state LGA counts match the official totals, every coordinate is inside Nigeria, no duplicates. **Don't re-fetch or regenerate it**; seed from it. Keep the source/licence note in the seeder.
- Tables: `states` (name, code, centroid lat/lng computed from its LGAs), `lgas` (state_id, name, lat, lng, wikidata_id). Seeded idempotently (re-running the seeder never duplicates or breaks references).
- Coordinates are one point per LGA, **approximate**. Distances are straight-line ("as the crow flies") and must be labelled that way everywhere — road distance is longer.

### Delivery hubs
- Our dispatch origins are the **stores**. Add `latitude`/`longitude` to `stores`, set by dropping a pin on the map (or typing coordinates). Only stores with coordinates count as hubs; the settings page warns if none are set.
- Distance for any LGA = straight-line km to the **nearest hub** (haversine), computed in a small service — not in the browser only.

### Rules
- One rule per **state** (the default for all its LGAs) plus optional **LGA overrides**. LGA override beats state rule.
- Each rule: `mode` = `free` | `fee` | `not_delivered`; `fee` (₦, required when mode = fee); `expected_cost` (₦, what we expect to pay the logistics company — optional, used to pre-fill dispatch cost); `delivery_days` text (e.g. "Same day", "2–4 days", optional, shown at checkout).
- **Seed so day-one behaviour doesn't change:** Uyo, Mkpat Enin and Eket (the three LGAs checkout allows today) = `free`; every other state = `not_delivered`. I'll open more areas myself.
- Every rule change is activity-logged (who, old → new).

### Admin "Delivery settings" page (admin panel, `manage_delivery_settings` permission; super_admin passes)
- **Interactive map** (Leaflet + OpenStreetMap tiles — free, no API key; bundle Leaflet via npm/Vite if that's the project pattern, otherwise a pinned CDN version):
  - Store hubs as pins.
  - Every LGA as a circle marker coloured by its effective rule: green = free, amber = fee (show the ₦ amount on hover), grey = not delivered.
  - Click an LGA → popup: name, state, distance from nearest hub (km, straight-line), effective rule and where it comes from (state default or override), and quick edit (mode, fee, expected cost, days). Draw a line from the nearest hub to the LGA while selected.
  - Filter/zoom by state; toggle "show only areas we deliver to".
  - Distance rings around hubs (e.g. 10/25/50/100 km) that can be toggled — helps judge pricing at a glance.
  - Mobile-usable (I'll check this from my phone).
- **Table view** next to the map: state → expandable LGAs, columns: distance, effective mode, fee, expected cost, days, override yes/no. Bulk actions: select many LGAs → set mode/fee/cost/days; clear overrides.
- **"Price by distance" helper:** admin enters distance bands (e.g. 0–15 km free, 15–40 km ₦1,500, 40–100 km ₦3,500, beyond 100 km not delivered), picks a scope (one state or all), sees a **preview** of which LGAs would change and how, then applies. Applying writes ordinary LGA overrides — it's a shortcut, not a live formula, so customer prices stay predictable and editable one by one.
- OSM tiles are fine for low-volume admin use; set a proper attribution and don't hammer the tile server (no bulk pre-fetching).

### Checkout
- Address step: **State** (select) → **LGA** (searchable select, filtered by state) → street address. Replace the hard-coded Uyo/Mkpat Enin/Eket list.
- Not-delivered LGAs stay selectable but show a clear message ("We don't deliver to {LGA} yet") with a WhatsApp link to our number, and checkout can't proceed.
- The fee is shown live and added to the total; **recomputed server-side** on submit (never trust the browser). The checkout stores `state_id`, `lga_id`, `delivery_fee`, and a **snapshot of the rule used** (mode, fee, which rule id, distance) so later price changes don't alter past orders.
- Paystack charges items + delivery fee. POD: the customer pays items + fee at the door; the order messages and rider page show the full amount to collect.
- One fee per checkout even when items come from several stores/vendors (v1). The cost side (section 3) will show if multi-pickup orders lose money; we'll revisit with data.
- Customer WhatsApp confirmation and the checkout success screen show the delivery fee as its own line.
- Legacy `orders.local_government` strings: map Uyo / Mkpat Enin / Eket (and any other values found) to `lga_id` in the backfill; report anything unmapped. Keep the old column for history.

### Out of scope (this addendum)
- Road distance/routing APIs (Google Maps, OSRM), live tracking, LGA boundary polygons (points only for v1), ward-level pricing, weight/size-based pricing, free-delivery-above-₦X promotions, logistics company APIs/integrations, platform ledger for fees and company payments.

## 5. Revised phase plan for Prompt 3

Same gates as before: hard stop and review after every phase, flag-before-fix, local commits on `feature/admin-order-ops`, no push, no merge to `main`, "no new failures" against the Prompt 2 baseline.

1. **Phase 1 — Checkout split.** As in Prompt 3, plus: `checkouts` gets `state_id`, `lga_id`, `delivery_fee` (default 0), `delivery_rule_snapshot` (JSON). The fee is 0 until Phase 2 wires the rules. Reseller wallet purchases create checkouts. Legacy `pod` normalised.
2. **Phase 2 — Delivery locations & fees (new).** Seed states/LGAs from the supplied file, store coordinates, rules + seed, distance service, Delivery settings page with map + table + price-by-distance helper, checkout State → LGA → fee, server-side recompute, snapshot, legacy `local_government` mapping.
   - Pest: effective rule resolution (state default vs LGA override); not-delivered blocks checkout; tampered fee in the request is ignored; fee snapshot unchanged after a rule edit; Paystack amount includes the fee; seeder is idempotent and loads exactly 37 states / 774 LGAs; distance service returns ~0 km for a hub's own LGA and a sane value for a known pair (Uyo → Eket ≈ 43 km straight-line); price-by-distance preview changes nothing until applied.
3. **Phase 3 — Status service and money decoupling.** As Prompt 3's Phase 2, using the **"Due from GadgetPlug"** account (answer 3) and the payout-eligibility fix.
4. **Phase 4 — Dispatch with logistics companies.** As Prompt 3's Phase 3, but with platform logistics companies (section 3) instead of a rider pool; delivery cost + paid-to-company flag; fee vs cost on the dispatch board.
5. **Phase 5 — Messaging: Cloud API templates and routing.** As Prompt 3's Phase 4. "Rider" messages go to the logistics company's dispatch number (and the rider's phone when entered). Include the delivery fee line in customer templates.

Final combined manual test checklist (from Prompt 3) gains: set Uyo free / Ikot Ekpene ₦1,500 / Lagos not delivered, then check checkout for each; apply a price-by-distance preview; change a rule and confirm an existing order's fee doesn't change; assign a logistics company and confirm the cost pre-fills.

Go with Phase 1.
