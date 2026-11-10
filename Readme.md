# GadgetPlug - Multi-Vendor E-Commerce Platform

A high-performance, multi-tenant gadget marketplace built on the TALL stack.

## Architecture
* **Framework:** Laravel 12
* **Frontend:** Livewire (Volt) & Tailwind CSS v4
* **Admin/Vendor Panel:** FilamentPHP v5
* **Tenancy Pattern:** Filament Native Multi-tenancy (Tenant: `Store`)
* **Payments:** Paystack / Flutterwave

## Git Strategy (GitHub Flow)
0. **Fetch first, every session.** Run `git fetch origin` and compare against `origin/main` (not your local `main`) before any recon, baseline test run or branch work, and branch from `origin/main`. Several sessions and machines push to `main`; local refs go stale, and work built on a stale `main` has already had to be redone once.
1. Branch off `main` for all new features (`feat/...`, `fix/...`, `chore/...`).
2. Commit using Conventional Commits.
3. Merge to `main` only when tested and stable.

## Deployment Protocol (Shared Hosting)
Target: DomainKing (cPanel)
* Assets must be built locally via `npm run build` before deployment.
* Queues are handled via cPanel cron jobs running Laravel Scheduler.
