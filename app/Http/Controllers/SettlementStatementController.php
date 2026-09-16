<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PosCustomer;
use App\Models\StoreSettlementStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The settlement statement, on screen and on paper.
 *
 * Both render the same view from the same frozen payload, so the copy discussed
 * on a laptop and the copy signed in a shop cannot say different things.
 */
class SettlementStatementController extends Controller
{
    public function show(Request $request, StoreSettlementStatement $statement)
    {
        $this->authorizeFor($request, $statement);

        return view('settlements.statement', $this->payload($statement));
    }

    public function pdf(Request $request, StoreSettlementStatement $statement)
    {
        $this->authorizeFor($request, $statement);

        return Pdf::loadView('settlements.statement', $this->payload($statement))
            ->setPaper('a4', 'portrait')
            ->download($statement->reference . '.pdf');
    }

    private function payload(StoreSettlementStatement $statement): array
    {
        $statement->load(['store', 'generatedBy', 'resolutions.recordedBy']);

        // Debtor names are looked up now rather than frozen into the payload:
        // a person's name is not one of the figures being settled, and freezing
        // it would leave a statement addressing somebody by a name they no
        // longer use.
        $ids = collect($statement->figure('outstanding.unpaid_debt.debtors', []))
            ->pluck('customer_id')
            ->filter();

        return [
            'statement' => $statement,
            'names'     => PosCustomer::whereIn('id', $ids)->pluck('name', 'id')->all(),
        ];
    }

    /**
     * Only somebody who belongs to the vendor the statement is about.
     *
     * A settlement names people and states what they are short — it is not a
     * document to leave reachable by anyone holding a link.
     */
    private function authorizeFor(Request $request, StoreSettlementStatement $statement): void
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if (! $user->vendors()->contains(fn ($vendor) => (int) $vendor->id === (int) $statement->vendor_id)) {
            throw new AccessDeniedHttpException('That statement belongs to another business.');
        }
    }
}
