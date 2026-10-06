import { useEffect, useMemo, useState } from 'react';
import AmountKeypad from '../components/AmountKeypad';
import CashUpModal from '../components/CashUpModal';
import {
    announceShift, businessDate, closeShift, fetchMissingDays, missingDaysFor, openShift, shiftForMissingDay,
} from '../lib/shift';
import { fmt } from '../lib/format';

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
    // Earlier days opened or sold on but never counted — on any device, not
    // only this one. Each must be recorded, or put off with a reason, before
    // today can start.
    const [missing, setMissing] = useState([]);
    const [serverMissing, setServerMissing] = useState(null);
    const [reason, setReason] = useState('');
    // A missing day with no shift at all has to be opened before it can be
    // counted, so the cashier is asked what was in the drawer that morning.
    const [floatFor, setFloatFor] = useState(null);
    const [pastFloat, setPastFloat] = useState('');
    const [cashingUp, setCashingUp] = useState(null);
    const [shown, setShown] = useState(false);

    // Drives the entrance: mounted first, then a frame later the transition
    // classes flip. Tailwind only, no animation library on a till.
    useEffect(() => {
        const id = requestAnimationFrame(() => setShown(true));

        return () => cancelAnimationFrame(id);
    }, []);

    // What this device knows straight away, then the server's fuller list.
    useEffect(() => {
        let cancelled = false;

        (async () => {
            const local = await missingDaysFor(user?.id).catch(() => []);
            if (!cancelled) setMissing(local);

            const fromServer = await fetchMissingDays(vendorId);
            if (cancelled || fromServer === null) return;

            setServerMissing(fromServer);
            setMissing(await missingDaysFor(user?.id, fromServer).catch(() => local));
        })();

        return () => { cancelled = true; };
    }, [user?.id, vendorId]);

    const refreshMissing = async () => {
        setMissing(await missingDaysFor(user?.id, serverMissing).catch(() => []));
    };

    const record = async (day) => {
        setError(null);

        if (day.local) {
            setCashingUp(day.local);

            return;
        }

        if (!day.session) {
            setPastFloat('');
            setFloatFor(day);

            return;
        }

        try {
            setCashingUp(await shiftForMissingDay({ cashierId: user.id, vendorId, day }));
        } catch (e) {
            setError(e?.message ?? 'Could not open that day.');
        }
    };

    const openPastDay = async () => {
        try {
            const shift = await shiftForMissingDay({
                cashierId: user.id, vendorId, day: floatFor, openingFloat: pastFloat,
            });

            setFloatFor(null);
            setCashingUp(shift);
        } catch (e) {
            setError(e?.message ?? 'Could not open that day.');
        }
    };

    // Starting with days still missing is allowed, but only with a reason —
    // it reaches the manager beside the day it explains.
    const reasonGiven = reason.trim().length >= 3;

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
                missingDayReasons: missing.map((day) => ({
                    business_date: day.business_date,
                    reason: reason.trim(),
                })),
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

                        {missing.length > 0 && (
                            <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-left dark:border-amber-900 dark:bg-amber-950/40">
                                <p className="text-xs font-semibold text-amber-800 dark:text-amber-200">
                                    {missing.length === 1
                                        ? 'One earlier day has no end of day'
                                        : `${missing.length} earlier days have no end of day`}
                                </p>
                                <p className="mt-0.5 mb-2 text-xs text-amber-700 dark:text-amber-300">
                                    Record {missing.length === 1 ? 'it' : 'each one'} now. Your manager can see every day that was not recorded.
                                </p>
                                {/* Offered here rather than only named. A day
                                    nobody can reach is a day that stays open for
                                    ever. */}
                                {missing.map((day) => (
                                    <button
                                        key={day.business_date}
                                        onClick={() => record(day)}
                                        className="mt-1 w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-left text-xs font-semibold text-amber-900 transition-all active:scale-95 dark:border-amber-800 dark:bg-amber-900/40 dark:text-amber-100"
                                    >
                                        <span className="flex items-baseline justify-between gap-2">
                                            <span>Record end of day — {dayLabel(day.business_date)}</span>
                                            {day.sales_count > 0 && (
                                                <span className="font-normal text-amber-700 dark:text-amber-300">
                                                    {day.sales_count} sale{day.sales_count === 1 ? '' : 's'}, {fmt(day.sales_total)}
                                                </span>
                                            )}
                                        </span>
                                    </button>
                                ))}

                                <label className="mt-3 block text-xs font-semibold text-amber-800 dark:text-amber-200" htmlFor="missing-reason">
                                    Cannot record {missing.length === 1 ? 'it' : 'them'} now? Say why:
                                </label>
                                <textarea
                                    id="missing-reason"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    rows={2}
                                    maxLength={500}
                                    placeholder="e.g. The manager has the drawer from that day"
                                    className="mt-1 w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-xs text-gray-800 focus:outline-none focus:border-amber-500 dark:border-amber-800 dark:bg-gray-900 dark:text-gray-100"
                                />
                            </div>
                        )}

                        {error && step === 'greeting' && !floatFor && (
                            <p className="mt-3 text-center text-xs font-semibold text-red-500">{error}</p>
                        )}

                        <button
                            onClick={() => setStep('float')}
                            disabled={missing.length > 0 && !reasonGiven}
                            className="mt-8 w-full rounded-2xl bg-[#068B03] py-4 text-base font-bold text-white transition-all active:scale-95 disabled:opacity-40"
                        >
                            {missing.length > 0 ? 'Start today without them' : 'Start your shift'}
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
                        await refreshMissing();
                    }}
                />
            )}

            {floatFor && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900">
                        <h2 className="text-center text-base font-extrabold text-gray-900 dark:text-gray-50">
                            {dayLabel(floatFor.business_date)}
                        </h2>
                        <p className="mt-1.5 mb-4 text-center text-xs text-gray-500 dark:text-gray-400">
                            No shift was started that day. How much cash was in the drawer that morning, before the first sale?
                        </p>
                        <AmountKeypad
                            value={pastFloat}
                            onChange={(next) => { setPastFloat(next); setError(null); }}
                            autoFocusLabel="Opening float that day"
                            onSubmit={() => { if (pastFloat !== '') openPastDay(); }}
                        />
                        {error && <p className="mt-3 text-center text-xs font-semibold text-red-500">{error}</p>}
                        <button
                            onClick={openPastDay}
                            disabled={pastFloat === ''}
                            className="mt-5 w-full rounded-2xl bg-[#068B03] py-3.5 text-base font-bold text-white transition-all active:scale-95 disabled:opacity-40"
                        >
                            Next: count that day
                        </button>
                        <button
                            onClick={() => { setFloatFor(null); setError(null); }}
                            className="mt-2 w-full py-2 text-xs font-semibold text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        >
                            Back
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

/** "Saturday 3 October" — the day a cashier remembers, not an ISO date. */
function dayLabel(date) {
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long',
    }).format(new Date(`${date}T12:00:00Z`));
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
