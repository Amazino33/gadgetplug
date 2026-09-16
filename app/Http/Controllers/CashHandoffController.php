<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cash\RedeemCashHandoffAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Where a scanned handover code lands.
 *
 * Its own page rather than a panel screen because the person scanning is
 * standing in a shop holding cash, on their phone. They need the amount, two
 * buttons, and nothing else on the way there.
 */
class CashHandoffController extends Controller
{
    public function __construct(private readonly RedeemCashHandoffAction $handoff) {}

    public function show(string $token)
    {
        try {
            $submission = $this->handoff->inspect($token);
        } catch (Throwable $e) {
            return view('cash.handoff-expired', ['message' => $e->getMessage()]);
        }

        return view('cash.handoff', [
            'submission' => $submission->load(['submitter', 'store']),
            'token'      => $token,
        ]);
    }

    public function confirm(Request $request, string $token)
    {
        try {
            $submission = $this->handoff->confirm($token, $request->user());
        } catch (Throwable $e) {
            return back()->with('handoff_error', $e->getMessage());
        }

        return view('cash.handoff-done', [
            'submission' => $submission->load('submitter'),
            'disputed'   => false,
        ]);
    }

    public function dispute(Request $request, string $token)
    {
        $data = Validator::make($request->all(), [
            // Required: a dispute with no account of it leaves two figures and
            // no way to tell which is wrong.
            'note'            => ['required', 'string', 'max:500'],
            'disputed_amount' => ['nullable', 'numeric', 'min:0'],
        ])->validate();

        try {
            $submission = $this->handoff->dispute(
                $token,
                $request->user(),
                $data['note'],
                isset($data['disputed_amount']) ? (float) $data['disputed_amount'] : null,
            );
        } catch (Throwable $e) {
            return back()->with('handoff_error', $e->getMessage());
        }

        return view('cash.handoff-done', [
            'submission' => $submission->load('submitter'),
            'disputed'   => true,
        ]);
    }
}
