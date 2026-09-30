import { db } from './db';
import { businessDate } from './shift';
import api from './api';

/**
 * What this device believes the drawer and the terminal should hold.
 *
 * Provisional, always, and the screen says so. The server is the authority: it
 * sees every sale, including the ones another till synced and the ones still
 * sitting in this one's queue. This exists because a cashier standing at the
 * counter with no signal still deserves an answer, and "we will tell you
 * tomorrow" is not one.
 *
 * Deliberately mirrors App\Services\Cash\CashUpExpectation line for line —
 * same tenders, same measure of cash, same exclusions, same line keys. Where
 * the two must agree, they agree by being the same arithmetic rather than by
 * two people having read the same description.
 */

/** The drawer. */
export const CASH_TENDERS = ['cash'];

/** Both card and transfer are taken on the cashier's own Moniepoint terminal. */
export const TERMINAL_TENDERS = ['card', 'bank_transfer'];

/**
 * Expected figures and the working behind them.
 *
 * Pure, so it can be reasoned about and tested without a database: the caller
 * hands it the day's rows.
 */
export function computeExpectation({ sales = [], refunds = [], openingFloat = 0, expenses = [], refused = [] }) {
    const live = sales.filter((sale) => sale.status !== 'voided');

    // Sales still on this device are counted — cash taken with no signal is
    // real money in the drawer — but on lines of their own. Folded into the
    // day's takings, a mistyped one is invisible: on 30/09/2026 a smart watch
    // rung as ₦324,000,000 sat in the queue and simply made the cashier look
    // ₦324,000,000 short, with nothing on screen to say where that came from.
    const taken = takings(live.filter((s) => s.synced === 1));
    const waiting = takings(live.filter((s) => s.synced !== 1));

    // ── Cash ─────────────────────────────────────────────────────────────────

    const cashLines = [
        { key: 'opening_float', label: 'Opening float', amount: round2(openingFloat) },
        { key: 'cash_sales', label: 'Cash sales', amount: taken.cash },
    ];

    if (Math.abs(taken.splitCash) > 0.009) {
        cashLines.push({ key: 'split_cash', label: 'Cash part of mixed-payment sales', amount: taken.splitCash });
    }

    const waitingCash = round2(waiting.cash + waiting.splitCash);

    if (Math.abs(waitingCash) > 0.009) {
        cashLines.push({ key: 'unsynced_cash', label: 'Cash sales not uploaded yet', amount: waitingCash });
    }

    const cashRefunds = refundTotal(refunds, CASH_TENDERS);

    if (cashRefunds > 0.009) {
        cashLines.push({ key: 'cash_refunds', label: 'Refunds paid in cash', amount: round2(-cashRefunds) });
    }

    const totalExpenses = sum(expenses, (e) => num(e.amount));
    if (totalExpenses > 0.009) {
        cashLines.push({ key: 'drawer_payouts', label: 'Paid out of the drawer', amount: round2(-totalExpenses) });
    }

    // ── Terminal ─────────────────────────────────────────────────────────────

    const terminalLines = [
        { key: 'card_sales', label: 'Card sales', amount: taken.card },
        { key: 'transfer_sales', label: 'Transfer sales', amount: taken.transfer },
    ];

    const waitingTerminal = round2(waiting.card + waiting.transfer);

    if (Math.abs(waitingTerminal) > 0.009) {
        terminalLines.push({ key: 'unsynced_terminal', label: 'Card and transfer not uploaded yet', amount: waitingTerminal });
    }

    const terminalRefunds = refundTotal(refunds, TERMINAL_TENDERS);

    if (terminalRefunds > 0.009) {
        terminalLines.push({
            key: 'terminal_refunds',
            label: 'Refunds paid back on the terminal',
            amount: round2(-terminalRefunds),
        });
    }

    // ── Context ──────────────────────────────────────────────────────────────
    //
    // Credit is the usual reason a cashier insists the drawer is short: they
    // sold ₦300,000 and the money is not there because a third of it walked out
    // unpaid. It is in nobody's hands, so it reconciles nothing — but it has to
    // be on the screen or the figures simply look wrong.

    const debtRung = round2(
        sum(live.filter((s) => s.payment_method === 'debt'), (s) => num(s.total))
        + tenderTotal(live.filter((s) => s.payment_method === 'split'), ['debt']),
    );

    return {
        expectedCash: sumLines(cashLines),
        expectedTerminal: sumLines(terminalLines),
        cashLines,
        terminalLines,
        context: {
            sales_count: live.length,
            gross_sales: sum(live, (s) => num(s.total)),
            debt_rung: debtRung,
            store_credit_refunds: refundTotal(refunds, ['store_credit']),
            // Sales this device has not managed to send yet. The server cannot
            // have counted them, so its figure will differ until they land —
            // which is a sync problem, not a missing-money problem, and the
            // cashier must be told which they are looking at.
            unsynced_sales: live.filter((s) => s.synced !== 1).length,
            unsynced_total: sum(live.filter((s) => s.synced !== 1), (s) => num(s.total)),
            // Sales the server refused. Left out of both legs: the server has
            // no record of them either, and they need correcting at the till
            // before they count anywhere.
            refused_sales: refused.length,
            refused_total: sum(refused, (s) => num(s.total)),
        },
    };
}

/**
 * The day's rows off this device, for one cashier.
 *
 * Sales the server refused are handed back separately. The refusal is on the
 * upload queue, not the history, so the two are read together.
 */
export async function dayRows(cashierId, date) {
    if (!cashierId) return { sales: [], refunds: [], refused: [] };

    const [allSales, allRefunds, queue] = await Promise.all([
        db.sales.where('cashier_id').equals(cashierId).toArray(),
        db.refunds.where('cashier_id').equals(cashierId).toArray(),
        db.offlineSales.where('synced').equals(0).toArray(),
    ]);

    const refusedIds = new Set(
        queue
            .filter((q) => q.sync_status === 'rejected' || q.sync_status === 'error')
            .map((q) => q.offline_id),
    );

    const today = allSales.filter((row) => onDay(row.completed_at, date));
    const isRefused = (row) => row.synced !== 1 && row.offline_id && refusedIds.has(row.offline_id);

    return {
        sales: today.filter((row) => !isRefused(row)),
        refused: today.filter(isRefused),
        // Dated by the refund itself: money handed back today came out of
        // today's drawer, whatever day the sale was made.
        refunds: allRefunds.filter((row) => onDay(row.created_at, date)),
    };
}

/** This device's provisional view of a shift. */
export async function provisionalFor(shift) {
    const { sales, refunds, refused } = await dayRows(shift.cashier_id, shift.business_date);

    let expenses = [];
    try {
        const { data } = await api.get('/expenses', { params: { vendor_id: shift.vendor_id } });
        expenses = data?.expenses || [];
    } catch (e) {
        // If offline, we can't fetch expenses. The device has no local record
        // since expenses are online-only.
        console.warn('Could not fetch expenses for cash-up', e);
    }

    return computeExpectation({
        sales,
        refunds,
        refused,
        openingFloat: num(shift.opening_float),
        expenses,
    });
}

/** Counted minus expected. Negative is short, positive is over. */
export function varianceAgainst(counted, expected) {
    return round2(num(counted) - num(expected));
}

/**
 * How a difference should be read out loud.
 *
 * "Short" and "over" rather than a signed number, because the sign is the one
 * thing a tired cashier at the end of a long day will read backwards.
 */
export function describeVariance(variance) {
    const value = num(variance);

    if (Math.abs(value) < 0.01) return { tone: 'balanced', label: 'Balanced', amount: 0 };

    return value < 0
        ? { tone: 'short', label: 'Short', amount: Math.abs(value) }
        : { tone: 'over', label: 'Over', amount: value };
}

// ── helpers ──────────────────────────────────────────────────────────────────

/**
 * What a set of sales put in the drawer and on the terminal.
 *
 * Cash is what stayed in the drawer, not the sale total: on a sale where change
 * was given, the total overstates the notes left behind. Change on a mixed sale
 * always comes out of the drawer, whatever the rest of it was paid on —
 * subtracted once per sale, not once per tender row.
 */
function takings(sales) {
    const splits = sales.filter((s) => s.payment_method === 'split');

    return {
        cash: sum(sales.filter((s) => s.payment_method === 'cash'), (s) => num(s.amount_tendered) - num(s.change_given)),
        splitCash: round2(tenderTotal(splits, CASH_TENDERS) - sum(splits, (s) => num(s.change_given))),
        card: round2(sum(sales.filter((s) => s.payment_method === 'card'), (s) => num(s.total)) + tenderTotal(splits, ['card'])),
        transfer: round2(sum(sales.filter((s) => s.payment_method === 'bank_transfer'), (s) => num(s.total)) + tenderTotal(splits, ['bank_transfer'])),
    };
}

function onDay(timestamp, date) {
    if (!timestamp) return false;

    const at = new Date(timestamp);

    return Number.isNaN(at.getTime()) ? false : businessDate(at) === date;
}

/**
 * The value of given tenders inside mixed-payment sales.
 *
 * Read off the payment rows rather than the sale, because a split sale's
 * payment_method is 'split' and says nothing about where the money went.
 */
function tenderTotal(sales, methods) {
    return round2(sales.reduce((total, sale) => {
        const payments = Array.isArray(sale.payments) ? sale.payments : [];

        return total + payments
            .filter((p) => methods.includes(p?.method))
            .reduce((acc, p) => acc + num(p.amount), 0);
    }, 0));
}

function refundTotal(refunds, methods) {
    return round2(refunds
        .filter((r) => methods.includes(r?.refund_method))
        .reduce((total, r) => total + num(r.refund_amount), 0));
}

function sum(rows, pick) {
    return round2(rows.reduce((total, row) => total + num(pick(row)), 0));
}

function sumLines(lines) {
    return round2(lines.reduce((total, line) => total + num(line.amount), 0));
}

function num(value) {
    const n = Number(value ?? 0);

    return Number.isFinite(n) ? n : 0;
}

function round2(value) {
    return Math.round(num(value) * 100) / 100;
}
