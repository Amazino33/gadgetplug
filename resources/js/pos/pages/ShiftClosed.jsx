import { fmt } from '../lib/format';
import { describeVariance, varianceAgainst } from '../lib/cashUp';

/**
 * The day is finished.
 *
 * Selling stops here, and that is the point rather than a side effect: one open
 * and one close per cashier per day is what makes the count mean anything. A
 * till that let a cashier carry on after counting would be counting a drawer
 * that was still moving.
 *
 * Shows what they counted and what it came to, so they leave knowing where they
 * stand rather than wondering. Once the server has answered, the figures here
 * quietly become the server's — the screen says which it is showing.
 */
export default function ShiftClosed({ shift, user, onLogout }) {
    // The server's word once it has given it; this device's arithmetic until
    // then. Never both, and never silently one pretending to be the other.
    const confirmed = shift.close_synced === 1 && shift.expected_cash !== null;
    const rejected = shift.sync_status === 'rejected';

    const cashVariance = confirmed
        ? Number(shift.cash_variance ?? 0)
        : varianceAgainst(shift.counted_cash, provisional(shift, 'cash'));

    const terminalVariance = confirmed
        ? Number(shift.terminal_variance ?? 0)
        : varianceAgainst(shift.counted_terminal, provisional(shift, 'terminal'));

    const firstName = (user?.name ?? '').trim().split(/\s+/)[0] || 'there';

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-gray-50 to-gray-100 px-4 dark:from-gray-950 dark:to-gray-900">
            <div className="w-full max-w-sm text-center">
                <p className="text-sm font-medium text-gray-400 dark:text-gray-500">
                    That's your day, {firstName}
                </p>
                <h1 className="mt-1 text-2xl font-extrabold text-gray-900 dark:text-gray-50">
                    Cash-up submitted
                </h1>
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {shift.business_date} · waiting for your manager
                </p>

                <div className="mt-6 space-y-2 text-left">
                    <Row label="Cash you counted" amount={shift.counted_cash} variance={cashVariance} />
                    <Row label="On the terminal" amount={shift.counted_terminal} variance={terminalVariance} />
                </div>

                {shift.notes && (
                    <div className="mt-4 rounded-xl bg-gray-50 px-4 py-3 text-left dark:bg-gray-800/60">
                        <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Your note</p>
                        <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{shift.notes}</p>
                    </div>
                )}

                {/* A refusal the till cannot fix by sending it again. Shown
                    rather than retried silently for ever, because the cashier is
                    the only one who can go and get it sorted. */}
                {rejected ? (
                    <div className="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-left dark:border-red-900 dark:bg-red-950/40">
                        <p className="text-xs font-semibold text-red-800 dark:text-red-200">
                            This could not be sent
                        </p>
                        <p className="mt-0.5 text-xs text-red-700 dark:text-red-300">
                            {shift.sync_message || 'The server refused it. Show your manager.'}
                        </p>
                    </div>
                ) : (
                    <p className="mt-4 text-xs text-gray-400 dark:text-gray-500">
                        {confirmed
                            ? 'These are the confirmed figures from the server.'
                            : 'Still to reach the server — these are this till’s own figures, and may change.'}
                    </p>
                )}

                <button
                    onClick={onLogout}
                    className="mt-8 w-full rounded-2xl bg-gray-900 py-4 text-base font-bold text-white transition-all active:scale-95 dark:bg-gray-100 dark:text-gray-900"
                >
                    Sign out
                </button>

                <p className="mt-4 text-[11px] text-gray-400 dark:text-gray-500">
                    Your next shift starts tomorrow.
                </p>
            </div>
        </div>
    );
}

function Row({ label, amount, variance }) {
    const verdict = describeVariance(variance);

    const tone = {
        balanced: 'text-[#068B03]',
        short: 'text-red-500',
        over: 'text-amber-500',
    }[verdict.tone];

    return (
        <div className="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-700">
            <div>
                <p className="text-xs text-gray-500 dark:text-gray-400">{label}</p>
                <p className="text-base font-bold tabular-nums text-gray-900 dark:text-gray-50">{fmt(amount)}</p>
            </div>
            <div className={`text-right ${tone}`}>
                <p className="text-xs font-bold">{verdict.label}</p>
                {verdict.tone !== 'balanced' && (
                    <p className="text-sm font-extrabold tabular-nums">{fmt(verdict.amount)}</p>
                )}
            </div>
        </div>
    );
}

/**
 * What this device worked out at the moment of closing.
 *
 * Read back off the counted figure and the variance frozen with it rather than
 * recomputed, so a sale syncing afterwards cannot quietly change what the
 * cashier was shown when they finished.
 */
function provisional(shift, leg) {
    const counted = Number(leg === 'cash' ? shift.counted_cash : shift.counted_terminal) || 0;
    const stored = leg === 'cash' ? shift.local_expected_cash : shift.local_expected_terminal;

    return stored ?? counted;
}
