<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Cash\SubmitCashAction;
use App\Http\Controllers\Controller;
use App\Models\CashSubmission;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\TillStore;
use App\Services\Cash\CashDrawer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Handing the day's takings over, from the till.
 *
 * Online only, and deliberately so. What a cashier should be holding is derived
 * from the sales the server knows about, and a till that has been offline since
 * morning does not know them — recording a handover against a stale figure
 * would manufacture a shortfall out of sales that simply had not synced yet.
 */
class PosCashController extends Controller
{
    /** What this cashier is holding, and who they can hand it to. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $vendorId = (int) $request->vendor_id;
        $storeId = TillStore::resolve($request->user(), $vendorId);
        $vendor = Vendor::findOrFail($vendorId);

        return response()->json([
            'store_id' => $storeId,
            'expected' => CashDrawer::expectedFrom($vendorId, $storeId, $request->user()->id),
            'receivers' => $this->receivers($vendor, $request->user()),
            // So the cashier can see what they have already handed over today
            // without leaving the till.
            'recent' => CashSubmission::where('vendor_id', $vendorId)
                ->where('store_id', $storeId)
                ->where('submitted_by', $request->user()->id)
                ->latest()
                ->limit(5)
                ->get(['reference', 'amount', 'expected_amount', 'status', 'created_at'])
                ->map(fn (CashSubmission $row) => [
                    'reference'  => $row->reference,
                    'amount'     => (float) $row->amount,
                    'variance'   => $row->variance(),
                    'status'     => $row->status,
                    'created_at' => $row->created_at,
                ]),
        ]);
    }

    public function submit(Request $request, SubmitCashAction $submit): JsonResponse
    {
        $request->validate([
            'vendor_id'   => 'required|integer',
            'received_by' => 'required|integer',
            'amount'      => 'required|numeric|min:0.01',
            'reason'      => 'nullable|string|max:500',
        ]);

        $vendorId = (int) $request->vendor_id;
        $vendor = Vendor::findOrFail($vendorId);

        // Checked against who may actually receive, so a posted id cannot name
        // somebody outside the team or somebody with no business holding cash.
        if (! array_key_exists((int) $request->received_by, $this->receivers($vendor, $request->user()))) {
            return response()->json(['message' => 'That person cannot receive cash.'], 422);
        }

        try {
            $submission = $submit->execute(
                submitter: $request->user(),
                receiver: User::findOrFail($request->received_by),
                store: TillStore::resolve($request->user(), $vendorId),
                amount: (float) $request->amount,
                reason: $request->reason,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'reference' => $submission->reference,
            'amount'    => (float) $submission->amount,
            'variance'  => $submission->variance(),
            'receiver'  => $submission->receiver->name,
        ]);
    }

    /**
     * Who cash may be handed to: team members who can receive it, never
     * yourself. Handing to yourself would leave one name on a two-name record.
     *
     * @return array<int, string>
     */
    private function receivers(Vendor $vendor, User $submitter): array
    {
        return $vendor->users()
            ->get()
            ->push($vendor->user)
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $submitter->id)
            ->filter(fn (User $user) => $user->hasVendorPermission($vendor->id, 'receive_cash'))
            ->pluck('name', 'id')
            ->all();
    }
}
