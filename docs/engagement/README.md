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
| Button URLs and access | `DestinationResolver` (Filament's own `canAccess()`) |

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
