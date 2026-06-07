# Vendor Role Management — GadgetPlug

## Overview

Every vendor (store) on GadgetPlug has its own independent set of roles. Roles control what a team member can see and do inside their store's panel. Roles in Store A have no effect on Store B.

---

## Default Roles

When a vendor is approved, six roles are automatically created for that store:

| Role | What they can do |
|---|---|
| **Store Admin** | Full access — manages everything including team members |
| **Product Manager** | Create, edit, and delete products; view orders |
| **Order Manager** | View and update orders; view products |
| **Inventory Manager** | Full product and order access; manage team members (invite, edit) |
| **Storekeeper** | View products and orders only (read-only) |
| **Member** | View products only |

---

## Who Manages Roles

- **Super Admin (GadgetPlug)** — the only person who can create, edit, or delete roles for any store. Store owners cannot touch the role definitions.
- **Store Owners** — can invite team members and assign them one of the available roles, but cannot change what each role can do.

---

## How It Works

**1. Vendor is approved**
Roles are automatically created for that vendor. No manual setup needed.

**2. Inviting a team member**
The store owner goes to **Team Members → Invite Member**, enters the person's email, and selects a role. The invited person only gets that role inside that specific store.

**3. Role assignment is store-scoped**
If the same person is a member of two stores, they can have a different role in each. For example: `Product Manager` in Store A and `Storekeeper` in Store B.

**4. Customising roles**
If the default role permissions need adjusting for a specific store (e.g. giving the Order Manager the ability to edit products), the Super Admin can open that store's panel via the admin dashboard and update the role from **Settings → Roles**.

---

## Accessing a Vendor's Panel (Super Admin)

From the admin dashboard:
1. Go to **Vendors**
2. Click **Open Panel** on any vendor row
3. The store's panel opens in a new tab — full access, no login required

The Super Admin can also switch between stores directly inside the vendor panel using the store switcher in the top bar.

---

## Key Rules

- Store owners **cannot** see or manage the Roles page — only Super Admin can.
- Roles are **per-store** — changes to one store's roles never affect another.
- A team member removed from a store loses all their permissions for that store immediately.
