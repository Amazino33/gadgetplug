# Known issues

Problems found while building something else, written down so they get fixed
on their own branch instead of inside an unrelated change. Each entry says
what is wrong, where, what it affects today, and a suggested fix.

## `User::hasGlobalPermission()` checks the vendor's team inside the vendor panel

*Found 2026-11-11 while building in-app tips. Not fixed.*

**What's wrong.** `hasGlobalPermission()` (app/Models/User.php) calls
`setPermissionsTeamId(null)` and then asks Spatie. But
`App\Auth\VendorTeamResolver::getPermissionsTeamId()` treats a null team as
"not set" and falls back to the current Filament tenant. Inside the vendor
panel there is always a tenant, so the "global" check quietly becomes a
check at the vendor's team:

- a permission granted globally (team null) is **not seen**, so the check
  says no to someone who holds it;
- a permission with the same name granted at that vendor's team **would be
  seen**, so the check would say yes to someone who only holds it for one
  business.

In the admin panel, in queued jobs and in console commands there is no
tenant, so the method works as intended there.

**Where it is called** (each needs checking for whether it can run with a
vendor tenant set):

| Caller | Note |
|---|---|
| `User::canAccessPanel('admin')` | admin panel only; works |
| `Dashboard::homeFor()`, `DeliverySettings::canAccess()`, `OrderResource` / `LogisticsCompanyResource::canAccess()` | admin panel; works |
| `OrderPolicy.php:126` (`manage_dispatch`) | policy, so it can be asked from anywhere; check vendor-panel order screens |
| `ProductPolicy.php:86-101` (`update_product_media`, `update_product_online_name`) | the catalogue editor's rights; check whether the vendor panel's product screens ask these |
| `ProductImporter.php:243` (`update_product_online_name`) | check whether an import runs in the request (tenant set) or in a queued job (no tenant) |

**How it was worked around.** `TipPolicy::canManage()` reads the
`model_has_permissions` / `model_has_roles` pivots directly with
`team_id IS NULL`, the same way `User::isSuperAdmin()` already does. It has
to, because "Preview as me" checks the permission inside the vendor panel.

**Suggested fix (separate branch).** Make "global" explicit instead of
relying on null:

- either `hasGlobalPermission()` reads the pivots with `team_id IS NULL`, as
  `isSuperAdmin()` and `TipPolicy` do (smallest change; ignores Spatie's
  permission cache, which is fine for a handful of checks), or
- `VendorTeamResolver` remembers that a null team was set on purpose (a
  sentinel or a flag) and stops falling back to the tenant until the team is
  set again.

Then drop `TipPolicy::holdsGlobally()` in favour of the fixed method. Test
it from inside the vendor panel with a tenant set: a global grant passes, a
same-named grant at the vendor's team does not.
