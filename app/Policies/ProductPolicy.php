<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Product;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Product');
    }

    public function view(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('View:Product');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Product');
    }

    public function update(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('Update:Product');
    }

    public function delete(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('Delete:Product');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Product');
    }

    public function restore(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('Restore:Product');
    }

    public function forceDelete(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('ForceDelete:Product');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Product');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Product');
    }

    public function replicate(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('Replicate:Product');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Product');
    }

    // ── Back-office catalogue editing ────────────────────────────────────────
    //
    // Hand-written, not Shield-generated: Shield's names are Verb:Model, these
    // are platform permissions held through the GLOBAL catalogue_editor role.
    // super_admin passes via Gate::before. Checked by the actions themselves,
    // not only by hiding their buttons — see ManageProductImages.

    /** Open the admin All Products page — anyone who may edit some part of the catalogue. */
    public function viewCatalogue(AuthUser $authUser): bool
    {
        return method_exists($authUser, 'hasGlobalPermission')
            && ($authUser->hasGlobalPermission('update_product_media')
                || $authUser->hasGlobalPermission('update_product_online_name'));
    }

    /** Replace a product's photos — and nothing else about it. */
    public function updateMedia(AuthUser $authUser, Product $product): bool
    {
        return method_exists($authUser, 'hasGlobalPermission')
            && $authUser->hasGlobalPermission('update_product_media');
    }

    /** Set the customer-facing online name and meta description. */
    public function updateOnlineName(AuthUser $authUser, Product $product): bool
    {
        return method_exists($authUser, 'hasGlobalPermission')
            && $authUser->hasGlobalPermission('update_product_online_name');
    }

}