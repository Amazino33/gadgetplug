<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Order;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrderPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Order');
    }

    public function view(AuthUser $authUser, Order $order): bool
    {
        return $authUser->can('View:Order');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Order');
    }

    public function update(AuthUser $authUser, Order $order): bool
    {
        return $authUser->can('Update:Order');
    }

    public function delete(AuthUser $authUser, Order $order): bool
    {
        return $authUser->can('Delete:Order');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Order');
    }

    public function restore(AuthUser $authUser, Order $order): bool
    {
        return $authUser->can('Restore:Order');
    }

    public function forceDelete(AuthUser $authUser, Order $order): bool
    {
        return $authUser->can('ForceDelete:Order');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Order');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Order');
    }

    public function replicate(AuthUser $authUser, Order $order): bool
    {
        return $authUser->can('Replicate:Order');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Order');
    }

    // ── Online order fulfilment (OrderStatusService checks these) ───────────
    //
    // Hand-written, not Shield-generated. super_admin passes via Gate::before.

    /**
     * Pack an order and hand it to the rider — the store's part.
     *
     * The vendor's owner; a vendor-wide manager (edit_order_items, no store
     * assignment); or someone assigned to a store that holds this order's
     * stock (a storekeeper sees and fulfils their own branch's orders only).
     */
    public function fulfil(AuthUser $user, Order $order): bool
    {
        $vendorId = (int) $order->items()->value('vendor_id');
        $vendor = $vendorId ? \App\Models\Vendor::find($vendorId) : null;

        if (! $vendor || ! method_exists($user, 'hasVendorPermission')) {
            return false;
        }

        if ($vendor->isOwner($user)) {
            return true;
        }

        if (! $user->hasVendorPermission($vendorId, ['edit_order_items', 'view_any_order_items'])) {
            return false;
        }

        $myStores = $user->storesForVendor($vendorId)->pluck('id');

        if ($myStores->isEmpty()) {
            return $user->hasVendorPermission($vendorId, 'edit_order_items');
        }

        $orderStores = \App\Models\OrderItemStoreAllocation::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->pluck('store_id');

        // A resold-only order holds no stock anywhere; any of the vendor's
        // staff with order access may hand it over.
        return $orderStores->isEmpty() || $orderStores->intersect($myStores)->isNotEmpty();
    }

    /**
     * GadgetPlug's part: cancel, record payment received, mark delivered by
     * override. Platform staff only — never a vendor.
     */
    public function dispatch(AuthUser $user, Order $order): bool
    {
        return method_exists($user, 'hasGlobalPermission') && $user->hasGlobalPermission('manage_dispatch');
    }

}