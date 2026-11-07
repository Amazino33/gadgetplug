# AI Business Assistant ("Ask GadgetPlug") — Progress

Source prompt: pasted at session start (2026-11-06). Re-paste it in a new session and say
"Continue from docs/ai-assistant/PROGRESS.md".

## Phase status

| Phase | What it builds | Depends on | Status |
|---|---|---|---|
| 0 | Recon (read-only) + PROGRESS.md | — | done (reviewed 2026-11-06) |
| 1A | Backend: tool framework, guards, Anthropic loop, storage, logging, daily cap, `sales_today` tool | 0 | done (approved 2026-11-07, committed) |
| 1B | Slide-over chat UI + conversation history, wired to 1A | 1A | not started |
| 2 | Sales, products, prices, stock tools | 1 | not started |
| 3 | Margins + product movement tools | 2 | not started |
| 4 | "Who did what" + activity-logging gaps | 1 | not started |
| 5 | Purchase suggestions | 2, 3 | not started |
| 6 | Customers, debt, cash tools | 1 | not started |
| 7 | Business summaries + insight engine | 2–6 | not started |
| 8 | Alerts system + suspicious-activity alerts | 4, 7 | not started |
| 9 | Rising-cost watch | 3, 7, 8 | not started |
| 10 | "How do I…?" help | 1 | not started |
| 11 | Morning brief | 7, 8 | not started |
| 12 | Ask on WhatsApp | everything above | not started |

---

## Phase 0 findings

Recon was read-only. MySQL was not running locally, so no SELECTs were run. Schemas
come from migrations and models.

### 1. Existing AI integration: **there isn't one**

- I found no assistant, chat UI, conversation storage, system prompt or "sales today"
  AI path on any local or remote branch (`git grep` over every ref), in the Bizac fork,
  or in the sibling projects under `C:/laragon/www`.
- The only LLM call in the codebase is `ProductImageAiAnalyzer`, which drafts SEO
  metadata from a product photo:
  - SDK `anthropic-ai/sdk ^0.40.0` (composer.json), key `services.anthropic.key` → `ANTHROPIC_API_KEY` (config/services.php:48-50)
  - model `claude-haiku-4-5` (app/Services/ImageProcessing/ProductImageAiAnalyzer.php:14), `messages->create` with structured JSON output (…:55-84). No streaming, no tools.
- "How much was sold today" is answered today by the **dashboard**, not by AI:
  `HomeDashboard` (app/Livewire/Vendor/HomeDashboard.php:43) → `SalesReportService`.
- **Consequence:** I found no model-generated SQL and no context stuffing, so nothing
  needs replacing. Phase 1 has no existing "sales today" behaviour to move. It has to
  build the assistant service, the chat UI and the conversation storage from scratch.

### 2. Request scoping (what a new assistant must reuse)

- Vendor = Filament tenant `Vendor` (slug), `ownershipRelationship: 'vendors'` (app/Providers/Filament/VendorPanelProvider.php, `->tenant(...)`).
- Tenant guard: `EnsureUserBelongsToVendor` as persistent tenant middleware (same file, `->tenantMiddleware`).
- Owner = `vendors.user_id` (`Vendor::isOwner`, app/Models/Vendor.php:233).
- Permission check: `User::hasVendorPermission($vendorId, $perm)` short-circuits true for the owner, otherwise Spatie team = vendor (app/Models/User.php:195-205).
- Store access: `ActiveStore::accessibleFor()` / `canAccess()` (app/Services/ActiveStore.php:22-27, 37, 74). Owner/super admin get every store, a single-store vendor gives everyone that store, anyone else gets exactly their `store_user` rows. Active store is held in the session per vendor.
- No assistant exists, so no access rule exists for it yet.

### 3. Roles and permissions

- Default roles (app/Services/VendorRoles.php:16-96): `store_admin`, `product_manager`, `order_manager`, `inventory_manager`, `storekeeper`, `member`, plus the **owner** (not a role) and global `super_admin`.
- There is **no "manager" or "cashier" role** by name. Vendors can edit role permissions on their Roles screen.
- Relevant permissions (database/seeders/VendorPermissionsSeeder.php):
  `view_cost_price` (**granted to no default role → owner only by default**), `view_activity_log` (store_admin), `view_store_settlement` (store_admin, inventory_manager), `view_customer_debts` (store_admin, storekeeper), `view_reports_hub`, `manage_financial_reports`, `view_inventory_reports`, `view_restock_report`, `view_sales_history` (storekeeper = own sales only).
- Cost visibility is enforced by `ProductForm::canSeeCostPrice()` (app/Filament/Vendor/Resources/Products/Schemas/ProductForm.php:104-114): super admin, owner, or `view_cost_price`. It is reused across Products, StoresOverview, StoreSelector, AuditSessions and CountSessions.

### 4. Activity log coverage

Setup: Spatie activitylog with custom model `VendorActivity`, which auto-fills `vendor_id` and `store_id` from the subject (app/Models/VendorActivity.php:20-26). Retention is configured at 365 days (config/activitylog.php), but `activitylog:clean` is **not scheduled** (bootstrap/app.php:49-121), so nothing is pruned in practice.

Models with `LogsActivity` (all `logOnlyDirty`): AuditSession, DeliveryRule, Expense, InventoryShortageCase, Order, OrderItem, OrderNote, PosReturn, PosSale, PosSession, PosZReport, Procurement, Product, Store, Supplier, VendorPayout, plus the affiliate models.
Manual `activity()` calls: ChangePrice (unit prices), TeamMembers, PosSaleController void/return, PosSessionController, CashUp approve/rectify, Pickings, FinancialLedger, DispatchService, OrderStatusService, VendorObserver.

| Event | Coverage | Where it's recorded / notes |
|---|---|---|
| Price change | **Partly** | Base `price` logged on Product (app/Models/Product.php:33). Unit price is logged by hand only on the Change Price page (app/Filament/Vendor/Pages/ChangePrice.php:193). `ProductUnit` has no LogsActivity, so unit prices edited in the product form or by import are unlogged. `wholesale_price`/`half_price` are not in logOnly. |
| Cost change | **Logged** | `cost_price` in Product logOnly. Procurement approval writes it through Eloquent (app/Actions/Procurement/ApproveProcurementAction.php:106-115). Also `stock_cost_layers` gives an append-only cost history per receipt (unit_cost, received_at, source). |
| Product create/edit/delete | **Logged** (fields limited) | name, online_name, meta_description, sku, price, cost_price, stock_quantity, status, category_id. Importer saves via Eloquent (app/Services/Import/ProductImporter.php:272,277). |
| Discount given | **Logged** (sale record) | `pos_sales.discount_amount/type/scope/discount_approved_by` are in PosSale logOnly (app/Models/PosSale.php:256). Line discounts are in `pos_sale_items.discount_amount` (table only). |
| Stock adjustment | **Logged** (ledger) | `inventory_ledgers` (user_id, type `stock_adjustment`, required reason) (app/Filament/Vendor/Pages/StockAdjustment.php:206). The Product stock mirror change is also activity-logged. |
| Stock transfer | **Partly** | **No panel feature.** Only the artisan command `stock:transfer` (app/Console/Commands/TransferStockBetweenStoresCommand.php:38), which writes ledger type `store_transfer`. |
| Sale created | **Logged** | PosSale `created` + `cashier_id`. Sales are immutable (app/Models/PosSale.php:60-105). |
| Sale voided/reversed | **Logged** | Append-only `pos_sale_reversals` (performed_by, reason, type, amount) + activity entry (app/Http/Controllers/Pos/PosSaleController.php:472). Known gap: the till sends no void reason, so it is recorded as "No reason given". |
| Refund | **Logged** | PosReturn logOnly (app/Models/PosReturn.php:44) + activity entry (PosSaleController.php:604). |
| Debt created/paid/written off | **Logged** (ledger) | `pos_customer_ledger_entries` (created_by), `pos_debt_payments`. Not in the activity log. |
| Cash submission/confirmation/dispute | **Logged** (ledger) | `cash_submissions`: submitted_by, received_by, confirmed_at, disputed_at, dispute_note, resolved_by/at, resolution_outcome/note. Cash-up approve is activity-logged (app/Actions/CashUp/ApproveCashUpAction.php:81). |
| User login | **Not logged** | Only a feed listener is attached to `Login` (app/Providers/AppServiceProvider.php:48). |
| Permission/role change | **Partly** | Member added/invited/role assigned/removed are logged (app/Filament/Vendor/Pages/TeamMembers.php:157-231). Edits to a role's permissions on the Roles screen are **not** logged. |
| Store setting change | **Partly** | Store name/is_default/is_active/address/phone logged (app/Models/Store.php:127). Receipt settings, notification settings and most Vendor fields are not (VendorObserver logs only online_sales_enabled and dashboard_blocked). |

### 5. Sales

- `pos_sales`: vendor_id, store_id, pos_session_id, cashier_id, customer_id, subtotal, discount_*, vat_amount, total, payment_method (cash/card/bank_transfer/debt/split), status mirror (completed/voided/refunded/partial_refund), `completed_at` (sale time **sent by the till**, validated at app/Http/Controllers/Pos/PosSyncController.php:68), `synced_at` (server receipt).
- Split tenders: `pos_sale_payments` (method, amount, reference).
- Lines: `pos_sale_items` with unit_price, sold_unit_price, price_type, unit_cost, cost_total, cost_estimated, unit fields, half pricing.
- Reports filter on `COALESCE(completed_at, created_at)` (app/Services/Reporting/SalesReportService.php:144).
- Online orders are a second sales channel (order_items, revenue_recognized_at). `SalesReportService` and `StoreSalesQuery` already combine POS and online sales.

### 6. Products and prices

- `products`: `name` (internal/shelf name), `online_name` (customer/SEO name), price, cost_price, wholesale_price, half_price, brand, category_id, supplier_id, reorder_point, low_stock_threshold, preferred_quantity, allow_pos_price_override, pos_min_margin_percent. `product_units` carry their own prices.
- **Each product row belongs to exactly one branch** (`products.store_id`, migration 2026_08_18_100000). The same phone in two branches is two product rows, and **nothing links them**. Procurement refuses to receive a product into a branch other than its home (ApproveProcurementAction.php:82-86, 128-139).
- Price history comes only from the activity log. Cost history comes from the activity log plus `stock_cost_layers`.

### 7. Stock

- `product_store_stock` (product_id, store_id, quantity, reserved), mirrored to `products.stock_quantity` (app/Observers/ProductStoreStockObserver.php:62).
- Ledger `inventory_ledgers` types: online_sale, pos_sale, restock, audit_correction, refund, reserved, dispatched, reservation_released, pos_void, pos_return, store_transfer, stock_adjustment, picking_out, picking_return, online_return. It carries user_id, reason, cost_total.
- Restock is via Procurement. Each receipt records its cost (StockCostLayers::receive, ApproveProcurementAction.php:97-103).
- Incoming stock = procurements not yet approved (statuses pending/changes_requested/… in app/Models/Procurement.php:30-45).
- **No lead-time field.** `ProductVelocityService` takes `leadTimeDays` as a parameter (default 5).
- Stock out with traders = Pickings (separate ledger).

### 8. Existing services to reuse

| Need | Service |
|---|---|
| Sales totals, by cashier/tender/day, channels | `SalesReportService::summary/cashierBreakdown/paymentBreakdown/dailySeries/dailyPayments` (app/Services/Reporting/SalesReportService.php:82-414) |
| Per-store sales (POS + online allocations) | `StoreSalesQuery::totals` (app/Services/Reporting/StoreSalesQuery.php:30) |
| P&L / profit | `FinancialReportService::report` (app/Services/Reporting/FinancialReportService.php:53) |
| Period presets + previous period in Lagos time | `ReportPeriod::fromFilters/previous` (app/Services/Reporting/ReportPeriod.php:41,68) |
| Velocity, days of cover, dead stock, top sellers | `ProductVelocityService::forVendor/topSellers/forProduct` (app/Services/Reporting/ProductVelocityService.php:32-131) |
| Dashboard/report cards + whole-business label | `Reporting/Cards/*`, `CardSummary` ("Whole business — not this store", app/Services/Reporting/Cards/CardSummary.php:38) |
| Daily summary pack | `VendorDailySummary::build` (app/Services/Reporting/VendorDailySummary.php:40) |
| Stock value, store metrics | `StockValuation::forVendor`, `StoreStockMetrics::forStores` |
| Debt ledger + aging (read-time FIFO) | `CustomerDebtService::aged/outstandingByCustomer/history` (app/Services/Pos/CustomerDebtService.php:100-238) |
| Checkmate (built) | `StoreReconciliation::byBranch/forStore/position`, GRACE_DAYS=2 (app/Services/Cash/StoreReconciliation.php:48-142); `CashDrawer`; `MissingEndOfDays` |
| Shrinkage | `ShrinkageReadModel` |
| Cashier's own sales | `CashierSalesHistory::sales/summary` (app/Services/Pos/CashierSalesHistory.php:50,88) |

### 9. Store hours

None. Nothing like opening_hours exists on Store or Vendor. The "odd hours" rule will have to use each store's own trading window from history.

### 10. Notifications and messaging

- Filament database notifications are on, polling every 30s (VendorPanelProvider `->databaseNotifications()`). `app/Notifications` has only `NewOrderNotification`.
- Mail: `MAIL_MAILER=log` locally.
- WhatsApp: `MessagingService` chooses a driver (app/Services/Messaging/MessagingService.php:233-277). **The live driver is `wawp`** (a linked WhatsApp session, `.env MESSAGING_WHATSAPP_DRIVER=wawp`), not Meta Cloud API.
  - `WhatsAppCloudApiDriver` exists but sends **free-form text only, with no template support** (app/Services/Messaging/Drivers/WhatsAppCloudApiDriver.php:29-35).
  - Wawp refuses marketing/broadcast sends (ban risk) and can be paused (MessagingService.php:161-187, `WhatsAppGate`). Sends are throttled and queued in priority lanes.
  - "Templates" are the app's own `MessageTemplate` rows (`TemplateRenderer`), not Meta-approved templates. Every message is a `DeliveryMessage` row (status, provider_response).
- An owner daily summary already exists: `DailySummaryNotifier` (app/Services/Messaging/DailySummaryNotifier.php:28) via `vendor:daily-summary` every 15 min (bootstrap/app.php:97-99). Its settings are in `vendor_notification_settings` (owner_whatsapp, notify_daily_summary, daily_summary_time).

### 11. Inbound WhatsApp

- **No inbound webhook exists.** The only webhook is Paystack, which checks an HMAC signature (routes/web.php:63, app/Services/Payment/PaystackClient.php:51).
- Phone numbers: `users.phone`, `stores.phone`, `vendor_notification_settings.owner_whatsapp/storekeeper_whatsapp`, pos_customers.phone. They are stored as entered (local `080…`) and normalised at send time by `PhoneNumber::toNigerianInternational/toE164` (app/Services/Messaging/PhoneNumber.php:16,48).
- `MESSAGING_REDIRECT_ALL_TO` is set locally, so every outbound message is diverted to one number.

### 12. Help content

- A **DB-backed Help Center already exists**: `help_categories`, `help_articles` (title, excerpt, rich body, `tour_key`, published flags). MySQL FULLTEXT search with a LIKE fallback (app/Models/HelpArticle.php:97-138). Articles are authored in the admin panel and shown on the vendor page `HelpCenter`. Guided tours use driver.js (`<x-vendor-tours />`, app/Support/Tours/TourRegistry.php).
- The seeder has 4 articles: record a procurement, add a supplier, add a product, read the daily report (database/seeders/HelpCenterSeeder.php). Production may hold more.
- Articles are **not role-aware** (global, not per vendor or permission).
- Other docs: docs/till-guide-cashier.md, docs/vendor-roles.md (the latter is **outdated**: it doesn't mention the cash/count/settlement permissions).
- Vendor panel pages: AccountClose, BlindCount, ChangePrice, Dashboard, FinancialReport, HelpCenter, ImportProducts, InventoryPage, NegativeStockReport, NotificationSettings, PaymentMethodsReport, PayoutsPage, PriceList, ReceiptSettings, ReportsHub, RestockReport, SalesReport, StockAdjustment, StoreProfile, StoreSelector, StoreSettlement, StoresOverview, SupplierPayablePage, TeamMembers, TopSellersReport, VendorLinkPage.
- Vendor panel resources: ActivityLog, AuditSessions, CashSubmissions, CashUps, CountSessions, CustomerDebts, DeliveryPersons, Expenses, FinancialAccounts, ImportLogs, InventoryLedgers, LogisticsCompanies, MessageTemplates, OrderItems, Orders, Pickers, PosSales, Procurements, Products, Roles (Shield), StockAccountability, Stores, Suppliers, Vendors.
- The Sales Point Terminal is a nav link to `/sales-point/{slug}`.

### 13. Infrastructure

- Queue: `database`. **There is no persistent worker** on the host. `schedule:run` drains the queues every minute with `queue:work --stop-when-empty --max-time=50` (bootstrap/app.php:63-65).
- Scheduler: bootstrap/app.php:49-121. The crontab is shared with another app (see memory), so changes go through the hosting panel.
- AI usage or cost tracking: **none**.
- Timezone: `app.timezone=UTC` (config/app.php:78). Report boundaries use `reporting.timezone` = Africa/Lagos (config/reporting.php:20) and messaging uses `services.messaging.timezone`. There is **no per-vendor timezone**.
- Currency: Naira only; helper `app/Support/Naira.php`.

### 14. Product search

LIKE only, with no fuzzy or typo handling: `Product::scopeSearchCatalogue` covers name, online_name, brand, source online_name and category (app/Models/Product.php:545-572), and the POS search matches barcode, SKU and `name LIKE` (app/Http/Controllers/Pos/PosProductController.php:145-173). No Scout and no FULLTEXT index on products.

---

## Conflicts with the prompt / locked decisions (need your call)

1. **The prompt assumes an assistant already exists. It doesn't.** Phase 1 grows to include the assistant service, chat UI, conversation storage, system prompt and the Anthropic tool-use loop. It also needs a model choice; the image analyzer uses claude-haiku-4-5.
2. **WhatsApp is wawp, not Cloud API.** Meta-approved templates and the 24h customer-service window don't exist on wawp, the Cloud API driver has no template or inbound support, and wawp's bulk-send ban risk is already guarded in code. Phases 11–12 need either (a) a move to Cloud API for the assistant number (new webhook, templates, Meta verification), or (b) a design built on wawp's inbound webhooks and limits.
3. **Help content already lives in the DB Help Center** (admin-authored, with tours), not `docs/help/*.md`. Two sources would drift apart. My recommendation: `help_search` reads `HelpArticle`, adding audience/permission fields, and Phase 10 drafts articles into it, or into md files seeded into it.
4. **Role mapping.** There is no "manager" or "cashier" role, vendors can edit role permissions, and `view_cost_price` is owner-only by default. My recommendation: gate tools on **existing permissions**, not role names (cost/margin → `view_cost_price`; activity → `view_activity_log`; cash → `view_store_settlement`; debts → `view_customer_debts`), plus new permissions `use_ai_assistant` and `view_alerts`. As a result, store_admins won't see margins unless the owner grants `view_cost_price`.
5. **Products are per-branch rows with no link between the copies.** "How many POP 20 and in which branch" and "transfer before buying" both depend on matching rows across branches by name/SKU, which is heuristic. There is also **no stock-transfer feature in the panel** (only `stock:transfer` CLI), so a suggested transfer can't be acted on and "How do I transfer stock?" has no honest answer today.
6. **Two purchase formulas.** The Restock report already uses `ProductVelocityService` (30-day window, 5-day lead, 30-day cover, its own tiers). The prompt locks 14 days of cover and a different formula, so the assistant and the Restock page would disagree. The locked "reuse existing services" rule argues for building on ProductVelocityService with configurable defaults.
7. **"Full Pest suite passes" can't be met literally.** main has a known baseline of 17 failures. The memory standard is "no new failures", checked by stash and re-run.
8. **No per-vendor timezone.** I'll use `reporting.timezone` (Africa/Lagos) and won't add a vendor column unless you want one.
9. **Sale time comes from the till clock** (`completed_at`). The odd-hours rule inherits any till clock skew.

## Logging gaps (input for the Phase 4 proposal, not added)

- Unit / wholesale / half price changes outside the Change Price page (needs `ProductUnit` LogsActivity, and wholesale_price/half_price added to Product logOnly).
- Role permission edits (Roles/Shield screen).
- User login.
- Receipt, notification and vendor settings changes.
- Void reason from the till (client sends none).
- Stock transfer has no UI, so there is nothing to log until it exists.

## Locked decisions (updated after the Phase 0 review, 2026-11-06)

These replace or refine the matching locked decisions in the source prompt.

1. **Model.** The assistant chat uses Sonnet (`claude-sonnet-5-5`). The model name lives in `config/assistant.php` (`ASSISTANT_MODEL`), so it can be switched without a code change. `ProductImageAiAnalyzer` stays as it is.
2. **Placement.** A slide-over chat panel on every vendor-panel page, opened from a topbar button. Conversations are saved per user, with a list of past conversations inside the slide-over. No full page in v1.
3. **WhatsApp.** The assistant uses **WhatsApp Cloud API on a dedicated number**, not wawp. wawp and everything using it stay untouched. Phases 11–12 extend the existing `WhatsAppCloudApiDriver` with templates and an inbound webhook.
   **The Meta setup is on the owner:** business verification, the dedicated number, and template submission/approval.
4. **Help.** Use the existing DB Help Center (`help_articles`). `docs/help/*.md` is dropped from the plan. Phase 10 adds an optional required-permission field to articles, so answers can be role-aware. New articles are created as unpublished drafts for review. Phase 10 will propose how article content is versioned in the repo (e.g. a seeder).
5. **Visibility follows permissions, not role names.**
   - Cost, profit and margin follow the existing `view_cost_price` permission.
   - Three new permissions: `use_ai_assistant`, `view_ai_alerts`, `view_staff_activity`. All three are owner-only by default (granted to no default role), and vendors grant them to roles like any other permission.
   - Store access follows `ActiveStore::accessibleFor()`. Store-wide sales follow `view_inventory_reports`, the same gate as the dashboard "Today's Sales" tile and the Sales Report page. Without it, a till user (`access_pos`) sees only their own sales.
6. **Cross-branch matching.** Match the same product across branches by exact SKU first, then by exact normalized name (case, spacing, trimmed). No loose matching for anything that drives a suggestion. Loose matches may appear only as "possible match" candidates in search answers.
   There is no transfer screen, so purchase suggestions show branch surplus **as information** ("Itel Home has 20 spare"), not as a transfer step. The help answer for "how do I transfer stock" must say honestly that it isn't available in the panel yet.
7. **Days of cover: 30.** Reuse the Restock report's calculation (`ProductVelocityService`) rather than writing a second formula, with one per-vendor setting driving both. Recon follow-up: vendors **already have** `restock_window_days`, `restock_lead_time_days`, `restock_target_cover_days` and `restock_safety_buffer_days` (app/Models/Vendor.php fillable), so the assistant reads those. Dead-stock days still needs a setting (Phase 5).
8. **Test gate: "no new failures".** A phase passes only if the failing list is the same as the baseline below, or smaller. The baseline tests are not fixed as part of this build.
9. **Branch.** The homepage work was committed on `feat/homepage-settings` as `316bd5b` ("WIP: homepage settings, saved before AI assistant work"). This build lives on `feat/ai-assistant`, branched from `main` at `f098b9f`.
10. **Daily cap.** 100 questions per vendor per day (Africa/Lagos midnight reset), adjustable per vendor by the platform admin. At the limit the user gets a friendly message saying when it resets, and usage is visible to the admin.
11. **Phase 1 is split.** 1A is the backend with no UI, plus Pest tests. 1B is the slide-over UI.
12. **Standing rule:** any vendor-panel UI change updates the matching Help Center article in the same commit.

## Test baseline (main @ f098b9f)

Captured 2026-11-07 from a full run on a clean `main` worktree (`C:/laragon/www/gadgetplug-ai-baseline`): **17 failed, 3 skipped, 2931 passed**, 61.5 min. A phase passes only if its failing list is this list or a subset of it.

| # | File | Test |
|---|---|---|
| 1 | tests/Feature/Auth/AuthenticationTest.php | users can authenticate using the login screen |
| 2 | tests/Feature/Auth/EmailVerificationTest.php | already verified user visiting verification link is redirected without firing event again |
| 3 | tests/Feature/Auth/EmailVerificationTest.php | email can be verified |
| 4 | tests/Feature/Auth/PasswordResetTest.php | password can be reset with valid token |
| 5 | tests/Feature/Auth/RegistrationTest.php | new users can register |
| 6 | tests/Feature/DashboardTest.php | authenticated users can visit the dashboard |
| 7 | tests/Feature/DashboardTest.php | guests are redirected to the login page |
| 8 | tests/Feature/Filament/Vendor/BlindCountSoloTest.php | a manager can cancel a session they are not part of |
| 9 | tests/Feature/Filament/Vendor/BlindCountSoloTest.php | cancelling a session deletes it and frees the store for another counter |
| 10 | tests/Feature/Filament/Vendor/ProcurementLogisticsLegsTest.php | creating a procurement through the wizard also creates its logistics legs |
| 11 | tests/Feature/Http/AffiliateEngagementRewardTest.php | a second page load qualifies the click and credits the reward |
| 12 | tests/Feature/ProcurementWizardLogisticsTest.php | the procurement wizard records transport stages as logistics legs on submit |
| 13 | tests/Feature/ProcurementWizardLogisticsTest.php | the procurement wizard submits fine when the transport-cost step is skipped entirely |
| 14 | tests/Feature/Procurement/ProcurementWizardDestinationTest.php | choosing a branch carries it through to the saved order |
| 15 | tests/Feature/Services/SalesReportServiceTest.php | it reports online order counts by status, including ones not yet earning revenue |
| 16 | tests/Feature/Settings/SecurityTest.php | password can be updated |
| 17 | tests/Feature/Settings/SecurityTest.php | security settings page renders without two factor when feature is disabled |

These are not fixed as part of this build (decision 8).

**Time-of-day caveat (found 2026-11-07).** Between 00:00 and 01:00 Lagos (23:00–00:00 UTC), `tests/Feature/CashUp/TillDebtRepaymentTest.php` fails 3 more tests on clean `main` ("a cashier collects a debt repayment from any branch", "a cash repayment raises what the drawer should hold", "a card repayment raises the terminal leg instead of cash"). Verified by running that file on the baseline worktree at 00:41 Lagos. A full run that crosses that hour is not comparable to the list above, so gate runs should finish outside it.


## Decisions made during build

### Phase 1A
- **Conversation history replays text only.** Only the user's and the assistant's text turns are resent on a follow-up, never earlier tool calls or results. A follow-up ("what about last week?") therefore calls the tools again and gets fresh figures, rather than reusing numbers that may since have changed. This also keeps token use flat. The full tool trail is kept in `assistant_tool_calls`.
- **The LLM sits behind an interface** (`App\Services\Assistant\Llm\LanguageModel`), and the Anthropic SDK implementation is bound in the container. Tests swap in a scripted fake, so no test calls the API.
- **Unanswered questions:** the model gets one internal tool, `flag_unanswered`, which it calls when no tool can answer. Errors, refusals and running out of tool rounds are also logged to `assistant_unanswered_questions`.
- **Defence in depth on tool input.** Tool schemas never accept a vendor. The executor also drops any key not in a tool's schema (e.g. a model-supplied `vendor_id`) and records what it dropped in the tool-call log.
- **Field stripping by key name.** For users without `view_cost_price`, any result key matching cost/profit/margin is removed before the model sees the result.
- **Default scope when no store is named:** every store the user can access. Whole-business figures come from the same `SalesReportService::summary(null)` call the dashboard's "All Stores" uses, and carry the existing "Whole business" label.
- **Model call:** Sonnet with adaptive thinking and effort `medium` (configurable), and server-side refusal fallbacks on (`fallbacks: 'default'`, beta `server-side-fallback-2026-07-01`; switch off with `ASSISTANT_FALLBACKS=false`). Prompt caching is described in the next item.
- **Prompt caching is on, and covers the tool definitions and the fixed rules.**
  - The API renders tools → system → messages. One `cache_control: ephemeral` breakpoint sits on the first system block (the rules), so the cached prefix is *tool definitions + rules*. The per-request context (date/time, business, user, stores, permissions) is the second system block, after the breakpoint and outside the cache key.
  - Verified against the SDK's real request body (tests/Feature/Assistant/PromptCachingTest.php, via a recording PSR-18 transport): exactly one breakpoint, in that place; the prefix is byte-identical across users, vendors and times of day; and the API's cache read/write counts reach `assistant_questions`.
  - The tool list is the same for every user (refusal happens at run time, not by hiding tools), so **one cache entry serves the whole platform**.
  - Size: rules ≈ 3.4k chars, tool JSON ≈ 1.6k chars, so about 1,250 tokens by a chars/4 estimate, plus the API's own tool-use preamble. That's comfortably above Sonnet 5.5's 512-token minimum. An exact count needs `count_tokens`, which needs the API key.
  - **Why the logged cache numbers are 0 so far:** no live request has been made. The tests use a scripted model that reports 0, and there is no local API key. Live, expect `cache_write ≈ prefix` and `read 0` on the first question after 5 quiet minutes, then `read ≈ prefix` and `write 0` on later questions and on every later round of the same question. If a live run shows 0/0 on both, something is invalidating the prefix and needs investigating.
  - Not done, could be revisited with live usage data: a 1-hour TTL (2× write price; only worth it if traffic is sparse), and a second breakpoint on the conversation for long multi-round questions.

- **Daily-cap race accepted as-is (owner decision, 2026-11-07).** The cap is checked, then the question is logged, with no lock between, so two questions sent at the same instant can take a vendor one or two over the cap. Accepted at 100/day; revisit only if a hard limit is ever needed.

### Phase 1A — what was built

| Piece | Where |
|---|---|
| Config (model, effort, caps, rounds, tool list) | config/assistant.php |
| Tables: conversations, messages, questions log, tool-call log, unanswered questions; `vendors.assistant_daily_cap` | database/migrations/2026_11_06_100000_create_assistant_tables.php |
| Permissions `use_ai_assistant`, `view_ai_alerts`, `view_staff_activity` (created, granted to nobody) | database/migrations/2026_11_06_100001_create_assistant_permissions.php, VendorPermissionsSeeder |
| Channel-agnostic entry point `ask(user, vendor, question, ?conversation, channel)` | app/Services/Assistant/AssistantService.php |
| Server-built identity and permissions | app/Services/Assistant/AssistantContext.php |
| Guards: store scope, Lagos date range, cost stripping, row cap | app/Services/Assistant/Guards/*, Tools/ToolResult::cap |
| Registry: the single guarded path for every tool call | app/Services/Assistant/Tools/ToolRegistry.php |
| Tools: `sales_today`, `flag_unanswered` | app/Services/Assistant/Tools/ |
| System prompt (cached rules + per-request context) | app/Services/Assistant/SystemPrompt.php |
| Daily cap (Lagos midnight reset, per vendor) | app/Services/Assistant/DailyCap.php |
| Anthropic SDK call | app/Services/Assistant/Llm/AnthropicLanguageModel.php |
| Terminal test command: same `AssistantService::ask()` path, guards, cap and logging as the panel (channel `cli`); prints the answer, tools called (with input and status) and token usage | `php artisan assistant:ask "question" --user=<id or email> [--vendor=<id or slug>] [--conversation=<id>]` (app/Console/Commands/AssistantAskCommand.php, 7 tests) |
| Tests (49) | tests/Feature/Assistant/*, scripted model in tests/Support/Assistant |

### Phase 1A — requirements checklist

- [x] Vendor resolved server-side; another vendor's data can't be returned. Tested: a model-supplied `vendor_id` is dropped and logged, another vendor's store name gets "no access", and the other vendor's figures never appear in anything the model is shown.
- [x] Store permissions enforced (tested: branch-assigned staff can't name another branch, and default to their own branch, not "whole business").
- [x] Restricted roles get cost/profit/margin removed or refused. Tested: stripping for a manager without `view_cost_price`, restored when the role is granted it; a cashier calling a cost-gated tool is refused without the figure.
- [x] Results capped (tested, and the truncation is logged).
- [x] Money calculated in code (`SalesReportService` / `CashierSalesHistory`). The prompt forbids the model from calculating.
- [x] Answer states range + store(s). Every result carries `period` and `scope`, and the whole-business label reuses the panel's wording.
- [x] Vendor timezone for "today" (tested: a 00:30 Lagos sale counts today and a 23:30 Lagos sale the night before doesn't; the cap resets at Lagos midnight).
- [x] Reuses existing services. `sales_today` equals `SalesReportService::summary()` to the naira (tested), and per-store rows sum to the total.
- [x] Question and tool calls logged: tokens, cache, latency, rounds, status, dropped input, errors.
- [x] Record content treated as data (prompt-injection tests on business, store and product names, plus a quote/newline break-out test).
- [x] Help guide updated if panel UI changed: n/a, 1A has no UI.
- [x] Question checklist started: docs/ai-assistant/question-checklist.md (15 questions; not run live, see deviations).
- [x] Full Pest suite: no new failures. Run on 2026-11-07: 17 failed (the baseline 17, identical by name), 3 skipped, 2967 passed. The 13 tests added afterwards (terminal command, caching) pass in the assistant folder run (49/49). Confirmation run on the final state (2026-11-07, crossed 00:00–01:00 Lagos): 22 failed = the baseline 17 + 3 TillDebtRepaymentTest (fail on clean main in that hour too) + 2 SalesTodayToolTest parity tests (compare against the dashboard's unconverted Lagos bounds, see Findings). **Not committed; reported to the owner.** Fix approved by the owner (2026-11-07): the two parity tests now run at a pinned midday Lagos clock (`atLagosMidday()` in SalesTodayToolTest). They still compare against the dashboard's exact unconverted call, so they keep proving parity whenever the dashboard is correct. Proof: with the pin moved to 00:30 Lagos both fail, and at 12:00 both pass. **Gate run 2026-11-07 04:22–05:30 UTC: 17 failed (identical by name to the baseline), 3 skipped, 2980 passed. 1A committed.**
- [x] PROGRESS.md updated.

Mutation check: removing cost stripping, input sanitising, the permission check, or the row cap each turns at least one test red.

### Deploy notes (when 1A ships)
- `php artisan migrate --force` creates the five tables, `vendors.assistant_daily_cap` and the three permissions. No seeder run is needed.
- `.env`: `ANTHROPIC_API_KEY` is required (already used by the photo analyzer). Optional settings: `ASSISTANT_MODEL`, `ASSISTANT_EFFORT`, `ASSISTANT_MAX_TOKENS`, `ASSISTANT_TIMEOUT`, `ASSISTANT_FALLBACKS`, `ASSISTANT_DAILY_CAP`.
- Smoke test on the host: `php artisan assistant:ask "How much did we sell today?" --user=<owner email>`. Add `--vendor=<slug>` only if that user belongs to more than one vendor. It counts toward the vendor's daily cap.
- Nothing is visible to vendors until 1B adds the UI.

## Open questions

- (Phase 1A) None blocking.
- Before 1B: owner runs the live smoke test locally (Laragon MySQL + API key). **1B does not start until they report back.**


## Deviations from the prompt

- Phase 0 ran no SELECT queries because local MySQL was down. Schema findings come from migrations and models.
- Phase 1 has no existing "sales today" AI behaviour to move (there was none). Instead, 1A's `sales_today` tool reuses the dashboard's `SalesReportService::summary`.
- 1A has no admin UI for the daily cap or usage. The cap is stored and enforced in 1A, and the admin field and usage view come in 1B.
- No live API smoke call was possible in 1A: there is no `ANTHROPIC_API_KEY` in the local `.env`.

## Findings noticed during build (flagged, not fixed)

- **Corrected 2026-11-07.** The 1A note said the dashboard "Today's Sales" tile *shows* profit to staff without `view_cost_price`. In fact the component **builds** a profit string for them (app/Livewire/Vendor/HomeDashboard.php:108-118), but the Blade prints tile subtexts only on hero tiles, and this tile is never hero (hidden for owners, secondary for staff), so profit never reached the screen. It was a latent leak, not a live one. Hardening (no profit string unless the user has `view_cost_price`) is on its own branch `fix/dashboard-profit-visibility` (not in this branch), awaiting owner review together with a list of other screens that show cost or margin without checking `view_cost_price`.
- **Dashboard "today" is an hour off (flagged, not fixed).** HomeDashboard passes Lagos-midnight bounds (`Carbon::now('Africa/Lagos')->startOfDay()`, HomeDashboard.php:44-45) straight to `SalesReportService::summary()`, which binds them unconverted against UTC timestamps. The tile and stat strip therefore cover 01:00–01:00 Lagos and miss sales rung between 00:00 and 01:00. `ReportPeriod` (used by the assistant and the report pages) converts correctly; this is the only call site found. Side effect here: the 1A parity tests in SalesTodayToolTest compare against the dashboard-style call, so they can fail when run between 00:00 and 01:00 Lagos. The assistant is right in that hour; the dashboard is wrong.

## Side branches from main (not in this branch)

| Branch | What | Status |
|---|---|---|
| `fix/dashboard-profit-visibility` | Today's Sales tile builds profit only for `view_cost_price` (latent leak) + 2 tests | uncommitted, awaiting owner |
| `fix/lagos-day-boundary` | Groups B, D, E of the day-boundary audit routed through `BusinessDate`; `ReportPeriod` built on it; 00:30/23:30 Lagos tests | in progress |
| `fix/lagos-day-filters` | Group C (UTC-day labels/filters) | after the above is merged |

Once `fix/lagos-day-boundary` is merged: remove the midday pin from the two SalesTodayToolTest parity tests and add a 00:30 Lagos parity test.

## Future ideas

- Voice notes on WhatsApp (out of scope for this build).
- Bank-statement reconciliation summary tool + "transfer sale with no bank match" alert rule (reserved slot, Phase 8).
- **Stock transfer screen** in the vendor panel (would make branch-surplus suggestions actionable).
- **Link the same product across branches** (would make cross-branch stock exact instead of SKU/name matching).
- **Per-vendor timezone column.** Not built by decision (2026-11-07): `reporting.timezone` (Africa/Lagos) is the single source and `BusinessDate` the single day-boundary helper. Revisit only if a vendor outside WAT signs up.
