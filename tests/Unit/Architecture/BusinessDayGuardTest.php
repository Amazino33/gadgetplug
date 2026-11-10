<?php

/*
 * Regression guard for the trading day.
 *
 * Stored timestamps are UTC; the trading day is the Lagos day (UTC+1). Every
 * "which day is it" and every day/week/month window has to go through
 * App\Support\Pos\BusinessDate (or ReportPeriod, which is built on it), or the
 * first hour of each Lagos day lands under yesterday. This test scans app/
 * for the shapes that caused exactly that, and fails with file:line.
 *
 * It matches code patterns, not meaning. It cannot see two-step forms such as
 * `$now = now(); ... $now->startOfDay()` or `Carbon::parse($date)->startOfDay()`
 * on a user-picked date — review those by hand.
 *
 * To silence a hit, fix it. If it is genuinely not a trading-day question (a
 * cache key, a rolling window), add it to ALLOWLIST with the reason.
 */

/** Files that ARE the day-boundary helpers: exempt from the day rules only. */
const DAY_HELPERS = [
    'app/Support/Pos/BusinessDate.php',
    'app/Services/Reporting/ReportPeriod.php',
];

const DAY_RULES = [
    'today()' => [
        '/(?<![\w>:$])today\(\)/',
        'Use BusinessDate::today() — today() is the server (UTC) date.',
    ],
    'Carbon::today()' => [
        '/Carbon(Immutable)?::today\(/',
        'Use BusinessDate::today() / BusinessDate::now() — Carbon::today() is the UTC day.',
    ],
    'now()->toDateString()' => [
        '/now\([^)]*\)\s*->\s*toDateString\(\)/',
        'Use BusinessDate::today() — before 01:00 Lagos this is yesterday.',
    ],
    'now()->startOf/endOf' => [
        '/(now|Carbon(Immutable)?::now)\(\)\s*->\s*(copy\(\)\s*->\s*)?(sub\w+\([^)]*\)\s*->\s*)?(startOf|endOf)(Day|Week|Month|Year)\(/',
        'Use BusinessDate::boundsFor()/rangeFor() or ReportPeriod — this is the UTC calendar.',
    ],
    'now(Lagos)->startOf/endOf' => [
        '/now\(\s*(\'Africa\/Lagos\'|config\(\'reporting\.timezone\'\))\s*\)\s*->\s*(startOf|endOf)/',
        'Lagos-zoned bounds are compared as if UTC. Use BusinessDate::boundsFor(BusinessDate::today()).',
    ],
];

/** Applies everywhere, helpers included: the last_month bug lives in one. */
const OVERFLOW_RULE = [
    '/(->|::)(sub|add)(Month|Months|Year|Years)\(/',
    'Overflows: on 31 March subMonth() is 3 March. Use the NoOverflow variant.',
];

const UNTIL_FILTERS = 'until fix/lagos-day-filters (group C of the day-boundary audit)';

const UNTIL_ASSISTANT_PHASE_1 = 'until assistant Phase 1 (hardening the existing assistant: the Lagos day everywhere)';

/**
 * [file, rule, a fragment of the offending line, reason].
 * Matched by fragment, not line number, so unrelated edits don't break it.
 */
const ALLOWLIST = [
    // ── Group C: UTC-day labels and filters, fixed on the next branch ──────
    ['app/Filament/Vendor/Pages/AccountClose.php', 'now()->toDateString()', '=> now()->toDateString(),', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/AccountClose.php', 'now()->startOf/endOf', 'now()->startOfMonth()', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/NotificationSettings.php', 'now()->startOf/endOf', 'now()->subDay()->startOfDay()', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/StoreSelector.php', 'now()->startOf/endOf', 'CarbonImmutable::now()->startOfDay()', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/StoreSelector.php', 'now()->startOf/endOf', 'CarbonImmutable::now()->endOfDay()', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/StoresOverview.php', 'now()->startOf/endOf', 'subDays(self::PERIOD_DAYS)->startOfDay()', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/StoresOverview.php', 'now()->startOf/endOf', 'CarbonImmutable::now()->endOfDay()', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/SupplierPayablePage.php', 'now()->startOf/endOf', 'now()->startOfMonth()', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/SupplierPayablePage.php', 'now()->toDateString()', '$this->to = now()->toDateString()', UNTIL_FILTERS],
    ['app/Filament/Vendor/Pages/SupplierPayablePage.php', "whereDate on timestamp 'created_at'", "whereDate('created_at'", UNTIL_FILTERS],
    ['app/Filament/Vendor/Resources/ActivityLog/ActivityLogResource.php', "whereDate on timestamp 'created_at'", "whereDate('created_at'", UNTIL_FILTERS],
    ['app/Filament/Vendor/Resources/PosSales/PosSaleResource.php', 'today()', '->default(today())', UNTIL_FILTERS],
    ['app/Filament/Vendor/Widgets/InventoryTableWidget.php', 'Carbon::today()', 'Carbon::today()->subDays(30)', UNTIL_FILTERS],
    ['app/Filament/Vendor/Widgets/StockMovementChart.php', 'Carbon::today()', 'Carbon::today()->subDays(', UNTIL_FILTERS],
    ['app/Services/Reporting/ShrinkageReadModel.php', "whereDate on timestamp 'created_at'", "whereDate('created_at'", UNTIL_FILTERS],
    ['app/Services/Reporting/VendorDailySummary.php', "whereDate on timestamp 'created_at'", "whereDate('created_at'", UNTIL_FILTERS],
    // Affiliate click-reward daily limit: UTC day today; Lagos day on the next branch.
    ['app/Services/Affiliate/ClickRewardService.php', 'today()', "whereDate('qualified_at', today())", UNTIL_FILTERS],
    ['app/Services/Affiliate/ClickRewardService.php', "whereDate on timestamp 'qualified_at'", "whereDate('qualified_at'", UNTIL_FILTERS],
    // ReportPeriod "last month" overflows on 29–31 March etc.; NoOverflow on the next branch.
    ['app/Services/Reporting/ReportPeriod.php', 'overflowing month/year', "'last_month' => [\$now->subMonth()", UNTIL_FILTERS],

    // ── The existing assistant (ab1860d): UTC-day usage, window and filters ──
    // Fixed by Phase 1 of the assistant build (hardening the existing
    // assistant, item 2 "the Lagos day everywhere"); see
    // docs/ai-assistant/PROGRESS.md on feat/assistant-hardening.
    ['app/Actions/Assistant/AskAssistantAction.php', 'now()->startOf/endOf', 'now()->subDays(7)->startOfDay()', UNTIL_ASSISTANT_PHASE_1],
    ['app/Filament/Resources/AssistantMessages/Tables/AssistantMessagesTable.php', "whereDate on timestamp 'created_at'", "whereDate('created_at'", UNTIL_ASSISTANT_PHASE_1],

    // ── Not a trading-day question ────────────────────────────────────────
    ['app/Services/Messaging/MessagingService.php', 'now()->toDateString()', "'messaging.redirect-warned.'.now()->toDateString()",
        'Cache key for a once-a-day operator warning that test-mode redirect is on. Server day is fine; no money or report depends on it.'],
    ['app/Services/Messaging/MessagingService.php', 'now()->startOf/endOf', "'messaging.redirect-warned.'.now()->toDateString(), true, now()->endOfDay()",
        'Expiry of the same cache key. Server day is fine.'],
];

/** Columns declared ->date() in any migration: whereDate on these is correct. */
function dateOnlyColumns(string $root): array
{
    $columns = [];

    foreach (glob($root.'/database/migrations/*.php') as $migration) {
        preg_match_all("/->date\('([a-z_]+)'/", file_get_contents($migration), $m);
        foreach ($m[1] as $column) {
            $columns[$column] = true;
        }
    }

    return $columns;
}

/** Source with comments blanked out (line breaks kept, so line numbers hold). */
function codeWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? preg_replace('/[^\n]/', '', $token[1])
                : $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

/**
 * Every hit in app/, before the allowlist.
 *
 * @return array<int, array{file: string, line: int, rule: string, hint: string, text: string}>
 */
function businessDayHits(string $root): array
{
    $dateColumns = dateOnlyColumns($root);
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $isHelper = in_array($relative, DAY_HELPERS, true);

        foreach (explode("\n", codeWithoutComments($file->getPathname())) as $i => $line) {
            // A real closure, not fn(): an arrow function captures $hits by
            // value and every hit would vanish — the self-test below exists
            // because that is exactly how the first version silently passed.
            $add = function (string $rule, string $hint) use (&$hits, $relative, $i, $line): void {
                $hits[] = ['file' => $relative, 'line' => $i + 1, 'rule' => $rule, 'hint' => $hint, 'text' => trim($line)];
            };

            if (! $isHelper) {
                foreach (DAY_RULES as $rule => [$pattern, $hint]) {
                    if (preg_match($pattern, $line)) {
                        $add($rule, $hint);
                    }
                }

                if (preg_match_all("/whereDate\(\s*'(?:[a-z_]+\.)?([a-z_]+)'/", $line, $m)) {
                    foreach ($m[1] as $column) {
                        if (! isset($dateColumns[$column])) {
                            $add("whereDate on timestamp '{$column}'", "'{$column}' is a UTC timestamp: whereDate compares its UTC date. Use whereBetween with BusinessDate::boundsFor()/rangeFor().");
                        }
                    }
                }
            }

            if (preg_match(OVERFLOW_RULE[0], $line)) {
                $add('overflowing month/year', OVERFLOW_RULE[1]);
            }
        }
    }

    return $hits;
}

function isAllowlisted(array $hit): bool
{
    foreach (ALLOWLIST as [$file, $rule, $fragment]) {
        if ($hit['file'] === $file && $hit['rule'] === $rule && str_contains($hit['text'], $fragment)) {
            return true;
        }
    }

    return false;
}

test('no code outside BusinessDate works out the trading day on the server clock', function () {
    $root = dirname(__DIR__, 3);

    $violations = array_values(array_filter(businessDayHits($root), fn (array $hit) => ! isAllowlisted($hit)));

    $report = implode("\n", array_map(
        fn (array $h) => "  {$h['file']}:{$h['line']}  [{$h['rule']}]  {$h['hint']}\n      {$h['text']}",
        $violations,
    ));

    expect($violations)->toBe([], "Trading-day code that bypasses BusinessDate:\n{$report}\n");
});

test('every allowlist entry still matches something, so fixed sites cannot linger in it', function () {
    $root = dirname(__DIR__, 3);
    $hits = businessDayHits($root);

    $stale = array_values(array_filter(ALLOWLIST, function (array $entry) use ($hits) {
        [$file, $rule, $fragment] = $entry;

        foreach ($hits as $hit) {
            if ($hit['file'] === $file && $hit['rule'] === $rule && str_contains($hit['text'], $fragment)) {
                return false;
            }
        }

        return true;
    }));

    expect(array_map(fn ($e) => "{$e[0]} [{$e[1]}] {$e[2]}", $stale))->toBe([]);
});

test('the guard catches the shapes it is meant to catch', function () {
    $tmp = sys_get_temp_dir().'/bdguard-'.uniqid();
    mkdir("{$tmp}/app", 0777, true);
    mkdir("{$tmp}/database/migrations", 0777, true);

    file_put_contents("{$tmp}/database/migrations/x.php", "<?php \$table->date('business_date'); \$table->timestamp('completed_at');");
    file_put_contents("{$tmp}/app/Bad.php", implode("\n", [
        '<?php',
        '$a = today();',
        '$b = Carbon::today();',
        '$c = now()->toDateString();',
        '$d = now()->startOfDay();',
        "\$e = Carbon::now('Africa/Lagos')->startOfDay();",
        "\$q->whereDate('completed_at', \$x);",
        "\$q->whereDate('business_date', \$x);",   // date column: allowed
        '$f = $now->subMonth();',
        '$g = $now->subMonthNoOverflow();',      // allowed
        '// today() in a comment is ignored',
    ]));

    $rules = array_column(businessDayHits($tmp), 'rule');

    expect($rules)->toBe([
        'today()',
        'Carbon::today()',
        'now()->toDateString()',
        'now()->startOf/endOf',
        'now(Lagos)->startOf/endOf',
        "whereDate on timestamp 'completed_at'",
        'overflowing month/year',
    ]);

    array_map('unlink', glob("{$tmp}/app/*") + glob("{$tmp}/database/migrations/*"));
});
