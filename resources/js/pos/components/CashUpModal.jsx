import { useEffect, useState } from 'react';
import AmountKeypad from './AmountKeypad';
import { fmt } from '../lib/format';
import { describeVariance, provisionalFor, varianceAgainst } from '../lib/cashUp';

/**
 * End of the day: count the drawer, read the terminal, see where you stand.
 *
 * Blind, and that is the whole design. The cashier enters both counts before
 * the till shows a single expected figure, because a count taken with the
 * answer on screen is not a count — it is a transcription. Nothing on the two
 * counting steps reveals what the day should come to, and the server refuses to
 * say until both numbers are in.
 *
 * Both counts are taken before either variance is shown, too. Revealing the
 * drawer result before the terminal was read would let a cashier who is short
 * on cash quietly adjust what they claim the machine says.
 *
 * The figures on the review step are this device's own arithmetic, and the
 * screen says so. The server sees sales this till never did — another till's,
 * or its own still sitting in the queue — so its answer is the one that counts.
 */
export default function CashUpModal({ shift, onClose, onComplete }) {
    const [step, setStep] = useState('cash');
    const [cash, setCash] = useState('');
    const [terminal, setTerminal] = useState('');
    const [note, setNote] = useState('');
    const [expectation, setExpectation] = useState(null);
    const [busy, setBusy] = useState(false);
    const [finishing, setFinishing] = useState(false);

    // Loaded only when the counts are in. Fetching it earlier would put the
    // expected figures in the page while the cashier is still counting.
    useEffect(() => {
        if (step !== 'review' || expectation) return;

        let cancelled = false;

        setBusy(true);
        provisionalFor(shift)
            .then((result) => { if (!cancelled) setExpectation(result); })
            .catch(() => {})
            .finally(() => { if (!cancelled) setBusy(false); });

        return () => { cancelled = true; };
    }, [step, shift, expectation]);

    const counts = { cash: Number(cash) || 0, terminal: Number(terminal) || 0 };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="mx-4 max-h-[92vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-gray-900">
                <div className="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-6 py-4 dark:border-gray-800 dark:bg-gray-900">
                    <h2 className="font-bold text-gray-800 dark:text-gray-100">
                        {{ review: 'Where you stand', note: 'Before you finish' }[step] ?? 'Cash up'}
                    </h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="Close">✕</button>
                </div>

                <div className="p-6">
                    {step === 'cash' && (
                        <CountStep
                            title="How much cash have you counted?"
                            hint="Count everything in the drawer, including the float you started with."
                            value={cash}
                            onChange={setCash}
                            onNext={() => setStep('terminal')}
                            nextLabel="Next"
                        />
                    )}

                    {step === 'terminal' && (
                        <CountStep
                            title="What's the total on your Moniepoint machine?"
                            hint="Read the day's total off the terminal screen. Card and transfer together."
                            value={terminal}
                            onChange={setTerminal}
                            onNext={() => setStep('review')}
                            onBack={() => setStep('cash')}
                            nextLabel="See how you did"
                        />
                    )}

                    {step === 'note' && expectation && (
                        <NoteStep
                            note={note}
                            onNote={setNote}
                            busy={finishing}
                            balanced={isBalanced(expectation, counts)}
                            onFinish={async (text) => {
                                setFinishing(true);

                                try {
                                    await onComplete({
                                        countedCash: counts.cash,
                                        countedTerminal: counts.terminal,
                                        notes: text?.trim() ? text.trim() : null,
                                        // Frozen with the counts, so a sale
                                        // syncing later cannot change what the
                                        // cashier was shown as they finished.
                                        expectation,
                                    });
                                } catch {
                                    setFinishing(false);
                                }
                            }}
                            onBack={() => setStep('review')}
                        />
                    )}

                    {step === 'review' && (
                        busy || !expectation
                            ? <p className="py-10 text-center text-sm text-gray-400">Working it out…</p>
                            : (
                                <Review
                                    expectation={expectation}
                                    counts={counts}
                                    onContinue={() => setStep('note')}
                                />
                            )
                    )}
                </div>
            </div>
        </div>
    );
}

function CountStep({ title, hint, value, onChange, onNext, onBack, nextLabel }) {
    return (
        <div>
            <h3 className="text-center text-lg font-extrabold text-gray-900 dark:text-gray-50">{title}</h3>
            <p className="mt-2 mb-5 text-center text-xs text-gray-500 dark:text-gray-400">{hint}</p>

            <AmountKeypad value={value} onChange={onChange} autoFocusLabel={title} />

            <button
                onClick={onNext}
                disabled={value === ''}
                className="mt-5 w-full rounded-2xl bg-[#068B03] py-4 text-base font-bold text-white transition-all active:scale-95 disabled:opacity-40"
            >
                {nextLabel}
            </button>

            {onBack && (
                <button
                    onClick={onBack}
                    className="mt-3 w-full py-2 text-xs font-semibold text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                >
                    Back
                </button>
            )}
        </div>
    );
}

function Review({ expectation, counts, onContinue }) {
    const cash = varianceAgainst(counts.cash, expectation.expectedCash);
    const terminal = varianceAgainst(counts.terminal, expectation.expectedTerminal);
    const { context } = expectation;

    return (
        <div className="space-y-4">
            <Leg
                title="Cash drawer"
                counted={counts.cash}
                expected={expectation.expectedCash}
                variance={cash}
                lines={expectation.cashLines}
            />

            <Leg
                title="Moniepoint terminal"
                counted={counts.terminal}
                expected={expectation.expectedTerminal}
                variance={terminal}
                lines={expectation.terminalLines}
            />

            {/* The wrong-tender signature. Worth saying out loud, because it is
                the difference between a cashier going home worried and a
                manager ticking a box tomorrow. */}
            {offsetting(cash, terminal) && (
                <p className="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                    These two cancel each other out — that usually means a sale was rung on the wrong
                    button. Say so in your note and your manager can put it right.
                </p>
            )}

            {context.debt_rung > 0 && (
                <p className="rounded-xl bg-gray-50 px-4 py-3 text-xs text-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
                    <strong>{fmt(context.debt_rung)}</strong> of today's {fmt(context.gross_sales)} went out on credit,
                    so it is not in the drawer or on the machine.
                </p>
            )}

            <p className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                This is the till's own working, from the {context.sales_count} sale{context.sales_count === 1 ? '' : 's'} on
                this device. <strong>Your manager sees the final figure</strong> once it reaches the server.
                {context.unsynced_sales > 0 && (
                    <> {context.unsynced_sales} sale{context.unsynced_sales === 1 ? ' has' : 's have'} not
                    uploaded yet, so the final figure will differ.</>
                )}
            </p>

            <button
                onClick={onContinue}
                className="w-full rounded-2xl bg-[#068B03] py-4 text-base font-bold text-white transition-all active:scale-95"
            >
                Continue
            </button>
        </div>
    );
}

/**
 * The cashier's own account of the day, in their own words.
 *
 * This is the only thing they can do about a difference. Rectifying one is a
 * manager's job, deliberately — a cashier who could write off their own
 * shortage would make the whole count pointless — so what they get instead is a
 * voice. "I gave 3,000 to the driver" is what turns an accusation into an
 * errand, and it has to reach somebody who can act on it.
 *
 * Two ways out when the day does not balance, and both are real. A cashier who
 * cannot explain a difference must still be able to go home: refusing to let
 * them finish would only teach them to type anything at all, and an invented
 * explanation is worse than an honest blank.
 */
function NoteStep({ note, onNote, onFinish, onBack, busy, balanced }) {
    const written = note.trim().length > 0;

    return (
        <div>
            <h3 className="text-center text-lg font-extrabold text-gray-900 dark:text-gray-50">
                {balanced ? 'Anything to add?' : 'What happened?'}
            </h3>
            <p className="mt-2 mb-4 text-center text-xs text-gray-500 dark:text-gray-400">
                {balanced
                    ? 'Your day balances. Add a note if there is anything your manager should know.'
                    : 'Your manager decides what accounts for a difference — this is how you tell them. Money spent out of the drawer, cash you handed over, a sale rung on the wrong button.'}
            </p>

            <textarea
                value={note}
                onChange={(e) => onNote(e.target.value)}
                rows={4}
                aria-label="Note for your manager"
                placeholder="e.g. Gave ₦3,000 to the driver for transport"
                className="w-full rounded-xl border-2 border-gray-200 px-4 py-3 text-sm text-gray-800 focus:border-[#068B03] focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
            />

            <button
                onClick={() => onFinish(note)}
                disabled={busy || (!balanced && !written)}
                className="mt-4 w-full rounded-2xl bg-[#068B03] py-4 text-base font-bold text-white transition-all active:scale-95 disabled:opacity-40"
            >
                {busy ? 'Finishing…' : balanced ? 'Finish cash-up' : 'Send this and finish'}
            </button>

            {!balanced && (
                <button
                    onClick={() => onFinish('')}
                    disabled={busy}
                    className="mt-3 w-full py-2 text-xs font-semibold text-gray-400 hover:text-gray-600 disabled:opacity-40 dark:hover:text-gray-300"
                >
                    I cannot explain it — finish anyway
                </button>
            )}

            <button
                onClick={onBack}
                disabled={busy}
                className="mt-1 w-full py-2 text-xs font-semibold text-gray-400 hover:text-gray-600 disabled:opacity-40 dark:hover:text-gray-300"
            >
                Back to the figures
            </button>
        </div>
    );
}

function Leg({ title, counted, expected, variance, lines }) {
    const verdict = describeVariance(variance);

    const tone = {
        balanced: 'text-[#068B03]',
        short: 'text-red-500',
        over: 'text-amber-500',
    }[verdict.tone];

    return (
        <div className="rounded-xl border border-gray-200 dark:border-gray-700">
            <p className="border-b border-gray-100 px-4 py-2 text-sm font-bold text-gray-800 dark:border-gray-800 dark:text-gray-100">
                {title}
            </p>

            <div className="px-4 py-3">
                {/* The working, so "why isn't the drawer just my sales?" has an
                    answer on the screen instead of a shrug. */}
                {lines.map((line) => (
                    <div key={line.key} className="flex justify-between py-0.5 text-xs">
                        <span className="text-gray-500 dark:text-gray-400">{line.label}</span>
                        <span className={`tabular-nums ${line.amount < 0 ? 'text-red-500' : 'text-gray-600 dark:text-gray-300'}`}>
                            {line.amount < 0 ? '−' : ''}{fmt(Math.abs(line.amount))}
                        </span>
                    </div>
                ))}

                <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-xs font-semibold dark:border-gray-700">
                    <span className="text-gray-700 dark:text-gray-200">Should be</span>
                    <span className="tabular-nums text-gray-900 dark:text-gray-50">{fmt(expected)}</span>
                </div>
                <div className="flex justify-between py-0.5 text-xs font-semibold">
                    <span className="text-gray-700 dark:text-gray-200">You counted</span>
                    <span className="tabular-nums text-gray-900 dark:text-gray-50">{fmt(counted)}</span>
                </div>

                <div className={`mt-2 flex items-baseline justify-between border-t border-gray-200 pt-2 dark:border-gray-700 ${tone}`}>
                    <span className="text-sm font-extrabold">{verdict.label}</span>
                    {verdict.tone !== 'balanced' && (
                        <span className="text-lg font-extrabold tabular-nums">{fmt(verdict.amount)}</span>
                    )}
                </div>
            </div>
        </div>
    );
}

function isBalanced(expectation, counts) {
    return Math.abs(varianceAgainst(counts.cash, expectation.expectedCash)) < 0.01
        && Math.abs(varianceAgainst(counts.terminal, expectation.expectedTerminal)) < 0.01;
}

/** Opposite directions, same size — one sale on the wrong tender, nearly always. */
function offsetting(cash, terminal) {
    return Math.abs(cash) > 0.009
        && Math.abs(terminal) > 0.009
        && (cash < 0) !== (terminal < 0)
        && Math.abs(cash + terminal) < 0.01;
}
