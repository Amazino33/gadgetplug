<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserTourProgress;
use App\Models\Vendor;
use App\Support\Tours\TourRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Records that this person has been shown a tour, so they are not asked twice.
 *
 * Deliberately tiny and deliberately not a Livewire action: the browser sends
 * this as the vendor is on their way to another page, with fetch(keepalive),
 * and nothing on screen waits for the answer.
 */
class TourProgressController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tour_key' => ['required', 'string', Rule::in(TourRegistry::keys())],
            'status' => ['required', 'string', Rule::in(UserTourProgress::STATUSES)],
            'vendor_id' => ['required', 'integer'],
        ]);

        $user = $request->user();

        // The vendor comes from the browser, so it is checked rather than
        // trusted. Without this, anyone signed in could write a progress row
        // against a store they have nothing to do with.
        $vendor = Vendor::find($data['vendor_id']);

        if (! $vendor || ! $user->canAccessTenant($vendor)) {
            abort(403);
        }

        UserTourProgress::record(
            (int) $user->id,
            (int) $vendor->id,
            $data['tour_key'],
            $data['status'],
        );

        return response()->json(['ok' => true]);
    }
}
