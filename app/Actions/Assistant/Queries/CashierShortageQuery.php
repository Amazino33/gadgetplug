<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\PosSession;
use App\Models\PosZReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CashierShortageQuery extends BaseAssistantQuery
{
    /** Same gate as the Store Settlement screen. */
    public function requiredPermission(): ?string
    {
        return 'view_store_settlement';
    }

    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));

        $shortages = [];

        // Check pos_z_reports joined to pos_sessions and users
        $zReports = PosZReport::query()
            ->join('pos_sessions', 'pos_sessions.id', '=', 'pos_z_reports.pos_session_id')
            ->join('users', 'users.id', '=', 'pos_z_reports.cashier_id')
            ->where('pos_sessions.store_id', $storeId)
            ->where(DB::raw('COALESCE(pos_z_reports.generated_at, pos_z_reports.created_at)'), '>=', $from)
            ->where(DB::raw('COALESCE(pos_z_reports.generated_at, pos_z_reports.created_at)'), '<=', $to)
            ->whereNotNull('pos_z_reports.cash_variance')
            ->where('pos_z_reports.cash_variance', '!=', 0)
            ->orderBy('pos_z_reports.generated_at', 'desc')
            ->select([
                'users.name as cashier_name',
                'pos_z_reports.cash_variance',
                DB::raw('COALESCE(pos_z_reports.generated_at, pos_z_reports.report_date, pos_z_reports.created_at) as shortage_date'),
            ])
            ->get();

        foreach ($zReports as $report) {
            $shortages[] = [
                'cashier_name' => (string) $report->cashier_name,
                ...self::naira('variance', ((float) $report->cash_variance)),
                'date' => Carbon::parse($report->shortage_date)->format('Y-m-d'),
            ];
        }

        // If no shortages found in pos_z_reports, also check pos_sessions directly
        if (empty($shortages)) {
            $sessions = PosSession::query()
                ->join('users', 'users.id', '=', 'pos_sessions.cashier_id')
                ->where('pos_sessions.store_id', $storeId)
                ->where(DB::raw('COALESCE(pos_sessions.closed_at, pos_sessions.opened_at, pos_sessions.created_at)'), '>=', $from)
                ->where(DB::raw('COALESCE(pos_sessions.closed_at, pos_sessions.opened_at, pos_sessions.created_at)'), '<=', $to)
                ->whereNotNull('pos_sessions.cash_variance')
                ->where('pos_sessions.cash_variance', '!=', 0)
                ->orderBy('pos_sessions.closed_at', 'desc')
                ->select([
                    'users.name as cashier_name',
                    'pos_sessions.cash_variance',
                    DB::raw('COALESCE(pos_sessions.closed_at, pos_sessions.opened_at, pos_sessions.created_at) as shortage_date'),
                ])
                ->get();

            foreach ($sessions as $session) {
                $shortages[] = [
                    'cashier_name' => (string) $session->cashier_name,
                    ...self::naira('variance', ((float) $session->cash_variance)),
                    'date' => Carbon::parse($session->shortage_date)->format('Y-m-d'),
                ];
            }
        }

        return QueryResult::withData([
            'shortages' => $shortages,
        ]);
    }
}
