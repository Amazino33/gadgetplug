import { useEffect, useMemo, useState } from 'react';
import AmountKeypad from '../components/AmountKeypad';
import CashUpModal from '../components/CashUpModal';
import { closeShift } from '../lib/shift';
import { announceShift, businessDate, openShift, unclosedShiftsFor } from '../lib/shift';

/**
 * The start of a cashier's day.
 *
 * Sits in front of the till: selling is gated behind an open shift, because a
 * day that was never opened has no float, and a closing count measured against
 * an unknown opening is not a count of anything.
 *
 * The float used to be sent as zero, automatically, with no screen at all — so
 * every variance was wrong by whatever was in the drawer when the cashier
 * arrived. Asking is the entire point.
 *
 * Nothing here waits on the network. The shift is written to this device and
 * the till opens; the server hears about it when there is a signal.
 */
export default function StartShift({ user, vendorId, onStarted, onLogout }) {
    const [step, setStep] = useState('greeting');
    const [amount, setAmount] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [unclosed, setUnclosed] = useState([]);
    const [cashingUp, setCashingUp] = useState(null);
    const [shown, setShown] = useState(false);

    // Drives the entrance: mounted first, then a frame later the transition
    // classes flip. Tailwind only, no animation library on a till.
    useEffect(() => {
        const id = requestAnimationFrame(() => setShown(true));

        return () => cancelAnimationFrame(id);
    }, []);

    useEffect(() => {
        unclosedShiftsFor(user?.id).then(setUnclosed).catch(() => {});
    }, [user?.id]);

    const greeting = useMemo(() => timeGreeting(), []);
    const firstName = (user?.name ?? '').trim().split(/\s+/)[0] || 'there';

    const start = async () => {
        setError(null);

        const value = Number(amount);

        if (!Number.isFinite(value) || value < 0) {
            setError('Enter how much cash is in the drawer. Zero is fine if it is empty.');

            return;
        }

        setBusy(true);

        try {
            const shift = await openShift({
                cashierId: user.id,
                vendorId,
                openingFloat: value,
            });

            // One session, one float, one open. Fire-and-forget: a till with no
            // signal still opens, and the sync keeps offering the day to the
            // server until it lands.
            announceShift(shift).catch(() => {});
            onStarted(shift);
        } catch (e) {
            setError(e?.message ?? 'Could not start the shift.');
            setBusy(false);
        }
    };

    return (
        <div className="min-h-screen bg-gradient-to-b from-gray-50 to-gray-100 dark:from-gray-950 dark:to-gray-900 flex flex-col items-center justify-center px-4">
            <div
                className={`w-full max-w-sm transition-all duration-700 ease-out ${
                    shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'
                }`}
            >
                {step === 'greeting' ? (
                    <div className="text-center">
                        <p className="text-sm font-medium text-gray-400 dark:text-gray-500">{greeting},</p>
                        <h1 className="mt-1 text-3xl font-extrabold text-gray-900 dark:text-gray-50">
                            {firstName}
                        </h1>
                        <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
                            {longDate()}
                        </p>

                        {unclosed.length > 0 && (
                            <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-left dark:border-amber-900 dark:bg-amber-950/40">
                                <p className="text-xs font-semibold text-amber-800 dark:text-amber-200">
                                    {unclosed.length === 1 ? 'A day is still open' : `${unclosed.length} days are still open`}
                                </p>
                                <p className="mt-0.5 mb-2 text-xs text-amber-700 dark:text-amber-300">
                                    Finish {unclosed.length === 1 ? 'it' : 'them'} before you start today, or the float stays unaccounted for.
                                </p>
                                {/* Offered here rather than only named. A day
                                    nobody can reach is a day that stays open for
                                    ever. */}
                                {unclosed.map((stale) => (
                                    <button
                                        key={stale.id}
                                        onClick={() => setCashingUp(stale)}
                                        className="mt-1 w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-left text-xs font-semibold text-amber-900 transition-all active:scale-95 dark:border-amber-800 dark:bg-amber-900/40 dark:text-amber-100"
                                    >
                                        End of day {stale.business_date}
                                    </button>
                                ))}
                            </div>
                        )}

                        <button
                            onClick={() => setStep('float')}
                            className="mt-8 w-full rounded-2xl bg-[#068B03] py-4 text-base font-bold text-white transition-all active:scale-95"
                        >
                            Start your shift
                        </button>

                        <button
                            onClick={onLogout}
                            className="mt-3 w-full py-2 text-xs font-semibold text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        >
                            Not you? Sign out
                        </button>
                    </div>
                ) : (
                    <div>
                        <h2 className="text-center text-xl font-extrabold text-gray-900 dark:text-gray-50">
                            How much is in your drawer?
                        </h2>
                        <p className="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                            Count the cash you are starting with. Everything you take today is measured against it.
                        </p>

                        <div className="mt-6">
                            <AmountKeypad
                                value={amount}
                                onChange={(next) => { setAmount(next); setError(null); }}
                                autoFocusLabel="Opening float"
                                onSubmit={() => { if (amount !== '' && !busy) start(); }}
                            />
                        </div>

                        {error && (
                            <p className="mt-3 text-center text-xs font-semibold text-red-500">{error}</p>
                        )}

                        <button
                            onClick={start}
                            disabled={busy || amount === ''}
                            className="mt-5 w-full rounded-2xl bg-[#068B03] py-4 text-base font-bold text-white transition-all active:scale-95 disabled:opacity-40"
                        >
                            {busy ? 'Starting…' : 'Open the till'}
                        </button>

                        <button
                            onClick={() => { setStep('greeting'); setError(null); }}
                            className="mt-3 w-full py-2 text-xs font-semibold text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        >
                            Back
                        </button>
                    </div>
                )}
            </div>

            {cashingUp && (
                <CashUpModal
                    shift={cashingUp}
                    onClose={() => setCashingUp(null)}
                    onComplete={async ({ countedCash, countedTerminal, notes, expectation }) => {
                        await closeShift(cashingUp.id, { countedCash, countedTerminal, notes, expectation });
                        setCashingUp(null);
                        setUnclosed(await unclosedShiftsFor(user?.id));
                    }}
                />
            )}
        </div>
    );
}

function timeGreeting(at = new Date()) {
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Africa/Lagos',
            hour: '2-digit',
            hour12: false,
        }).format(at),
    );

    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';

    return 'Good evening';
}

function longDate() {
    const [y, m, d] = businessDate().split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-NG', {
        timeZone: 'UTC',
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}
