<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Extends the existing status enum additively — 'pending'/'approved'/'voided'
// (the wizard-based manual-pricing flow) keep working unchanged for any
// procurement already in that lifecycle. 'draft'/'awaiting_logistics'/
// 'reconciled' are the new auto-pricing workflow's states, going forward.
// Default stays 'pending' — every code path that creates a Procurement sets
// status explicitly, so this only matters as a safety net.
//
// This mirrors the repo's established pattern for enum extension (raw
// ALTER ... MODIFY, e.g. 2026_05_13_000001_extend_orders_status_enum.php)
// rather than converting to a plain string column, to stay consistent with
// existing migrations. Known tradeoff: like those existing migrations, this
// is MySQL-specific raw SQL and cannot run against the SQLite test config —
// same pre-existing limitation flagged after Batch 1, not introduced here.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE procurements MODIFY status ENUM('pending', 'approved', 'voided', 'draft', 'awaiting_logistics', 'reconciled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE procurements MODIFY status ENUM('pending', 'approved', 'voided') NOT NULL DEFAULT 'pending'");
    }
};
