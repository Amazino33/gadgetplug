# In-app tips, What's new and Learn GadgetPlug

Teaches vendors and staff the app while they use it, and reports who actually
picks each feature up. Code lives in `app/Support/Engagement`.

## The one rule

**Code decides what a feature is and how its use is detected. Admins decide
what users read.** One registry answers "has this person used it?" for every
part of the system: hiding tips, Learn-page ticks, the per-tip funnel, the
adoption report. Don't add a second way of detecting usage.

| Piece | Where |
|---|---|
| Feature registry (12 features) | `FeatureRegistry`, `Features/*Feature.php` |
| Admin-editable copy per feature | `app_features` table, `AppFeature` model, filled by `php artisan features:sync` |
| Tips and announcements | `tips` table, `Tip` model |
| What happened (append-only) | `tip_events` table, `TipEvent` model |
| Platform defaults (daily cap 1, cooldown 3 days, adoption window 7 days) | `tip_settings`, `TipSetting::current()` |
| Which tip to show, if any | `TipEligibility::pick()`; `explain()` says why one wouldn't show |
| Did the tip work | `TipAdoption::rows()` / `summary()` |
| Cached usage per person | `FeatureUsage` (see "The usage cache" below) |
| Moments | `Moments/*`, fired by `MomentRecorder`, held in the cache by `PendingMoments` |
| Conditions | `Conditions/ConditionRegistry` |
| Button URLs and access | `DestinationResolver` (Filament's own `canAccess()`, plus the free plan's allowed routes) |
| The Learn page and its dashboard card | `LearnCatalogue`, `LearnGadgetPlug`, `LearnProgressCard` |
| The per-tip report | `TipReport` (names on top of `TipAdoption`), `ReportTip` page |
| Platform adoption | `FeatureAdoption`, `FeatureAdoptionPage` |

## Who counts as "used it"

Owners (and platform admins looking in) are judged on the **business**: an owner
whose storekeeper records debts every day is using debts. Staff are judged on
**their own** use where the table records who did it. Features whose tables
don't record a person (`product_photos`, `team_members`, `multi_store`) are
always business-wide, and the UI must say so. The rule is
`Feature::isBusinessWideFor()`. Everything goes through it.

## Adding a feature

1. Create `app/Support/Engagement/Features/YourFeature.php` extending `Feature`:
   - `key()`: a stable snake_case key. It is stored on tips and events, so
     never rename it.
   - `defaultTitle()` / `defaultDescription()`: the starting copy.
   - `destinationRoute()`: the Filament route "Show me" opens (for example
     `filament.vendor.resources.expenses.index`). Access then comes from
     that page's own `canAccess()`. A feature that isn't a Filament page
     overrides `destinationUrl()` and `canAccess()` instead (see
     `SalesPointFeature`).
   - `usageSources()`: one `UsageSource` per table whose rows mean "used it".
     Give `userColumns` (user column => the timestamp of that person's use)
     when the table records who did it, and `model` so that saving one
     refreshes the usage cache immediately.
   - Optionally `moments()`.
2. Add the class to `FeatureRegistry::FEATURES` (its position there is the
   Learn-page order for new rows).
3. Run `php artisan features:sync`. It is safe on every deploy: it never
   overwrites copy an admin has edited, and it marks removed features as
   unregistered instead of deleting them.
4. `tests/Feature/Engagement/FeatureRegistryTest.php` checks that the route
   exists and that a new vendor shows no use. Add a case if the detection is
   unusual.

## The usage cache

There is one entry per feature, per vendor, per scope (the business, or one
person). A page reads all of them in one `Cache::many()` call.

- A first-use **date never changes**, so it is cached for 30 days and nothing
  ever clears it.
- **"Not used yet"** is cached for 10 minutes. It is the only answer that can
  go stale, so it is the only one that is cleared: when a row that counts as
  use is saved, `FeatureUsage::recheck()` clears it after commit.
- Each request remembers which keys need no clearing (they hold a date or
  hold nothing). Every save after the first in a bulk operation therefore
  does **no cache work at all**. A 40-sale offline sync does one batched read
  of two keys, and no writes.

## Listener safety

Every listener goes through `EngagementServiceProvider::guarded()`.

- The decision made during the save is wrapped in a try/catch.
- The work it hands back runs after commit, wrapped the same way.
- An exception is reported and swallowed: a tip is never worth a failed sale
  or sync. `EngagementListenersTest` throws from both steps and checks that
  the sync still succeeds.

## Adding a moment

Extend `Moments\Moment`.

- **Fired by a save:** add a method to `MomentRecorder`, called from a
  listener in `EngagementServiceProvider`. Make it cheap for bulk saves: decide
  once per vendor per request, and skip everything when no live tip waits for
  the moment (`isWanted()`).
- **Fired by a page visit:** implement `detectOnRender()`.

Then return the class from a feature's `moments()`.

| Moment | Fired by | Pending for |
|---|---|---|
| `cash_sales_today_reached` | `PosSale` created: cash, completed, and dated today by its own sale date (late offline syncs from earlier days never count). Counted once per cashier when the request ends, because the sync endpoint commits each sale separately. | until the end of the Lagos day, because sales happen in the POS app where tips never show |
| `first_product_added` | `Product` created, checked once per vendor per request at save time. The first row of an import sees itself as the only product; the other rows cost nothing. Needs a signed-in user. | 15 min |
| `debtors_page_empty_visit` | opening the customer debts list while the vendor has no debts | 15 min |

## Eligibility order

live (status and dates) → panel → role (`owner` is a pseudo-role) → not a
create/edit page → moment pending (moment tips only) → linked feature reachable
and not already used (announcements skip "already used") → condition → button
destination reachable → not retired (clicked, dismissed, or an announcement
already shown) → under its view limit → out of cooldown → the daily cap.

Survivors are ranked announcement → moment tip → tip, then by higher priority,
then by whichever this person saw least recently.

The daily cap is per person per Lagos day, and it also counts the guided
tours' own first-visit offer (`user_tour_progress` rows created today).

## Admin screens (Help Centre)

Platform staff with the global `manage_in_app_tips` permission see these
screens, and so do super admins. `TipPolicy` gates them all; vendor roles never
pass. Someone holding only `manage_in_app_tips` reaches these three screens and
nothing else in the admin panel; `TipAdminTest` sweeps every admin resource
and page to prove it. The permission check reads the pivots directly; see
`docs/known-issues.md` for why.

- **In-App Tips:** list with Saw → Tapped → Used and 👍%. Create and edit
  with a live phone preview. Row actions: Duplicate (as a draft),
  Pause/Activate, and **Preview as me**, which opens the real pop-up on a
  chosen business's dashboard via `?gp_tip_preview={id}`. A preview ignores
  status and caps, and records nothing.
- **App Features:** the copy, video and order of each feature (drag to
  reorder). Rows are created only by `features:sync`.
- **Tip Settings:** daily cap (default 1), days between repeats (3),
  adoption window (7).

### Writing a tip

1. Help Centre → In-App Tips → New tip. Type: **Tip**.
2. Link a feature. Its page becomes the button's destination, people already
   using it never see the tip, and "Used it" is counted from it.
3. Title as a question, a one-sentence message, and a button label that says
   where it goes.
4. Pick who sees it (panels, roles, condition), then set Status to **Active**.

### A moment tip

The same steps, plus **Show right after** (for example "After N cash sales
by this person today", with N = 5). It only shows while that moment is
pending, and it takes the day's slot ahead of ordinary tips.

### A "What's new" announcement

Type: **What's new announcement**. It is shown once to everyone targeted, in
the centre with an orange "New" badge, even to people already using the
feature. Link the feature so the button goes to it. For Ask GadgetPlug, the
button opens the chat bubble.

### Button destinations

- the linked feature's page, or
- any panel list/page (never create/edit or single-record pages), or
- Sales Point (owned by `SalesPointFeature`, checked with `access_pos`), or
- a custom https:// or /path link (the last resort: it cannot be
  access-checked).

Anyone who can't open the destination never sees the tip. A free-plan shop
can open only what `EnforceFreePlan::ALLOWED_ROUTES` lists (the dashboard,
Products, Shop Profile), so `DestinationResolver::canAccess()` refuses every
other destination for it, and `SalesPointFeature` refuses the till, as the
POS login does. There is one list: `planAllows()` reads
`EnforceFreePlan::ALLOWED_ROUTES` itself, and `LearnPageTest` requests every
destination a tip can use, as a free shop, through the real middleware, and
fails if the tips and the plan ever disagree.

## The pop-up

`App\Livewire\Engagement\TipPopup`, added to the end of every vendor-panel
(and admin-panel) page by `resources/views/engagement/tip-hook.blade.php`.

- **When no tip is live**, the hook adds nothing (one indexed query).
- **Otherwise** the component loads lazily after the page.
- **`shown` is logged only when the browser displays the tip**: after the
  tip's delay, and never on a page load where the SystemAnnouncement modal or
  a guided tour is showing or being offered. It also waits while a Filament
  modal is open. These are read-only DOM checks in `engagement/tip-scripts`;
  `TipPopupTest` fails if the announcement modal's markup stops matching
  them.
- **Layout:** a bottom sheet with a drag handle on phones (swipe down = Not
  now), a corner card above the chat bubble on desktop, or a centre modal.
- **Media:** poster first, then muted, looped, inline video once on screen.
  With Save-Data or reduced motion, it shows a play button instead.
- **Styling:** `resources/css/engagement.css` (imported by both panel themes)
  defines `--gp-brand-green` / `--gp-brand-orange` with dark-mode variants,
  used only by these components. Headings are Inter semibold; Montserrat is
  not loaded.

## Learn GadgetPlug (vendor panel)

`App\Filament\Vendor\Pages\LearnGadgetPlug` at `/plug/{shop}/learn`, beside
Help & Guides in the menu. Everything it shows comes from `LearnCatalogue`:

- **Features:** every visible, registered `app_features` row (in the admin's
  order) whose feature passes its own `canAccess()` for this person. Each card
  has the admin's copy and video, **Show me** (the feature's page, or its
  browser event: Ask GadgetPlug opens the chat bubble), a "Read the guide"
  link when the row names a published help article, and thumbs.
- **Ticks and progress:** `FeatureUsage::map()`, the same cached registry
  answer the tips use. "You've used X of Y features" counts the ticked cards.
- **Honest labels:** owners (and platform admins) are told ticks count the
  whole business. Staff are told ticks are their own, and cards whose tables
  don't record who did it carry a "Whole business" chip.
- **What's new:** every announcement sent to this person (active, started;
  ones past their end date stay as history), newest first, filtered by
  `TipEligibility::targets()` (panel, role, feature access, destination). An
  orange dot marks each one without a `shown` event. Reading it here (a
  quarter of it on screen for 1.5 seconds) records `shown` on the Learn route,
  which clears the dot and retires its pop-up. `shownToday()` ignores events
  on the Learn route, so reading here never uses up the day's pop-up, and no
  pop-up ever shows on the Learn page itself.
- **Recorded as `tip_events`** (route = the Learn route): Show me taps
  (`clicked` + `feature_key`), thumbs, video plays (once per visit).

**Who can open it:** its own vendor permission, `view_learn_page`, on every
default role (and granted to every existing role by migration). It is on the
Roles screen's list (`RoleResource::GRANTABLE_PERMISSIONS`), ticked by
default when a vendor creates a new role (`DEFAULT_ON_PERMISSIONS`), so a
vendor takes it away rather than having to know to add it. Owners and super
admins always can. Not part of the free plan.

**Dashboard card:** `App\Livewire\Engagement\LearnProgressCard`, lazy, at the
top of the vendor dashboard (render hook). It shows the progress and the
number of unread announcements, and links to the page. Closing it records a
`dismissed` event with `feature_key = learn_progress_card` (not a registry
key), and it stays closed for that person at that business. It also hides
once every feature is ticked.

## Reports (admin, Help Centre)

### Per-tip report

In-App Tips → a tip → **Report** (`/admin/tips/{id}/report`), same access as
the other tip screens. `TipReport` adds names to `TipAdoption::rows()` and
never recomputes a status:

- the funnel: Saw → Tapped → Used it, each bar with its count and share of
  Saw; Already using is counted separately underneath;
- found it helpful (% and 👍/👎, latest answer per person) and video plays;
- one row per person per business: business, branch (where they first saw
  it), person, role, status (*Saw only* / *Tapped, not used* / *Used it* /
  *Already using*), first saw, tapped, first used, and whether "used" counted
  the person or the business. Filter by status, search by name, and
  **Download CSV** of what's on screen.

### Feature Adoption

Help Centre → **Feature Adoption** (`/admin/feature-adoption`). Per feature:
the share of businesses that have used it, how many people have used it
themselves ("Whole business" where the tables don't record who), thumbs from
the Learn page, and Show me taps. **Not using yet** lists the businesses that
haven't, newest first, with the owner's name and number (the shop's WhatsApp
when the owner has none). Filters: plan (paid by default: a free shop can't
reach most features, so counting it would make them all look unused) and
date joined. CSV of what's on screen.

- **Live, cached for 10 minutes** (`FeatureAdoption::CACHE_SECONDS`), with a
  Refresh now button. Each feature is one or two indexed GROUP BY queries, so
  a nightly rollup (a new table, plus a cron entry on a crontab shared with
  another app) wasn't worth figures a day old. Filters work on the cached
  answers, so changing one never re-runs the queries.
- **Who can open it:** super admins and the global `view_feature_adoption`
  permission, its own because the list holds every owner's phone number.
  Writing tips (`manage_in_app_tips`) doesn't open it.

CSV cells that start with `=`, `+`, `-`, `@` or a tab get a leading
apostrophe (`Csv::cell()`), because business and owner names are typed by
vendors and a spreadsheet would otherwise run them as formulas.

## Trying it locally

1. `php artisan migrate` and `php artisan db:seed --class=EngagementTipsSeeder`
   (this seeds five draft tips).
2. As a super admin: Help Centre → In-App Tips → "Debts: buy on credit" →
   Activate.
3. Sign in as a vendor owner whose business has no debts yet, in a
   phone-sized window. Open the dashboard; after about 4 seconds the bottom
   sheet appears.
4. Tap "See who owes you": it opens the business's Customer Debts list.
5. Record a credit sale in Sales Point. The tip's **Report** then shows you
   as "Used it" (and the list's Saw → Tapped → Used column counts it).
6. Open **Learn GadgetPlug** from the menu: Customer debts now has its tick,
   and the progress bar has moved. Activate the seeded "What's new"
   announcement to see the unread dot; it clears once you've read it.

Each person gets one pop-up per Lagos day. To see it again the same day,
raise the daily cap in Tip Settings, or use Preview as me.
