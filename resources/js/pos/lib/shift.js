import { db } from './db';
import api from './api';

/**
 * The cashier's trading day: opened with a counted float, closed with a counted
 * drawer and a terminal reading.
 *
 * Written locally first, always. A till with no signal must still be able to
 * start and finish a day — the server recomputes the authoritative figures when
 * the row reaches it, and until then the cashier is working from what this
 * device knows about its own sales.
 *
 * One row per cashier per business date, matching the server's unique key. The
 * row carries its own sync flags rather than feeding a separate queue, because
 * a shift has exactly one open and one close and a second table would only be
 * somewhere for the same day to disagree with itself.
 */

/**
 * The shop's clock, not the device's.
 *
 * A sale at 00:30 in Lagos belongs to the day the shopkeeper thinks it does,
 * and the server keys the whole reconciliation on that. A till left on UTC —
 * or carried across a border — would otherwise open a second shift for a day
 * the server considers already started.
 */
export const TIMEZONE = 'Africa/Lagos';

/** Today on the shop's clock, as YYYY-MM-DD. */
export function businessDate(at = new Date()) {
    // en-CA formats as YYYY-MM-DD, which is the shape the server expects.
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(at);
}

export const STATUS_OPEN = 'open';
export const STATUS_PENDING_REVIEW = 'pending_review';

/** This cashier's shift for a given day, whatever state it is in. */
export async function shiftFor(cashierId, date = businessDate()) {
    if (!cashierId) return null;

    return (await db.shifts
        .where('[cashier_id+business_date]')
        .equals([cashierId, date])
        .first()) ?? null;
}

/** The day this cashier is currently trading, or null if they have not started. */
export async function openShiftFor(cashierId) {
    const shift = await shiftFor(cashierId);

    return shift?.status === STATUS_OPEN ? shift : null;
}

/**
 * Days this cashier opened and never closed.
 *
 * Offered back rather than left hanging: a cashier who forgets simply opens
 * again tomorrow, and without this the earlier day stays open for ever with its
 * float unaccounted for.
 */
export async function unclosedShiftsFor(cashierId) {
    if (!cashierId) return [];

    const today = businessDate();
    const rows = await db.shifts.where('cashier_id').equals(cashierId).toArray();

    return rows
        .filter((row) => row.status === STATUS_OPEN && row.business_date < today)
        .sort((a, b) => a.business_date.localeCompare(b.business_date));
}

/**
 * Start the day.
 *
 * Returns the existing row if the day is already open, so a double-tapped
 * button or a reloaded app resumes rather than starting a second day — the same
 * answer the server gives for a repeated open.
 */
export async function openShift({ cashierId, vendorId, openingFloat, terminalId = null }) {
    if (!cashierId) throw new Error('A shift needs a cashier.');

    const amount = Number(openingFloat);

    if (!Number.isFinite(amount) || amount < 0) {
        throw new Error('The opening float has to be a real amount.');
    }

    const date = businessDate();
    const existing = await shiftFor(cashierId, date);

    if (existing) return existing;

    const row = {
        cashier_id:    cashierId,
        vendor_id:     vendorId,
        business_date: date,
        terminal_id:   terminalId,
        opening_float: amount,
        opened_at:     new Date().toISOString(),
        status:        STATUS_OPEN,

        // Filled in by the close, which is a separate moment.
        counted_cash:     null,
        counted_terminal: null,
        closed_at:        null,
        notes:            null,

        // What the server said, once it has said it. Until then the screen
        // shows this device's own arithmetic and says so.
        server_id:         null,
        expected_cash:     null,
        expected_terminal: null,
        cash_variance:     null,
        terminal_variance: null,
        breakdown:         null,

        // Its own idempotency keys, generated once and reused on every retry —
        // that is the whole point of them.
        open_key:  newKey('open'),
        close_key: null,

        open_synced:  0,
        close_synced: 0,
    };

    const id = await db.shifts.add(row);

    return { ...row, id };
}

/**
 * Record the counts and finish the day, locally.
 *
 * The provisional figures are frozen here alongside the counts, rather than
 * recomputed whenever the closed day is looked at again. A sale syncing an hour
 * later would otherwise quietly change what the cashier was shown at the moment
 * they finished, and the number they were told is the number they remember.
 */
export async function closeShift(shiftId, { countedCash, countedTerminal, notes = null, expectation = null }) {
    const shift = await db.shifts.get(shiftId);

    if (!shift) throw new Error('That shift is not on this device.');
    if (shift.status !== STATUS_OPEN) return shift;

    const patch = {
        counted_cash:     round2(countedCash),
        counted_terminal: round2(countedTerminal),
        notes:            notes || null,
        closed_at:        new Date().toISOString(),
        status:           STATUS_PENDING_REVIEW,
        close_key:        shift.close_key ?? newKey('close'),

        // This device's own working at the moment of closing. Kept apart from
        // expected_cash/expected_terminal, which belong to the server alone —
        // one screen must never show the till's guess as though the server had
        // confirmed it.
        local_expected_cash:     expectation ? round2(expectation.expectedCash) : null,
        local_expected_terminal: expectation ? round2(expectation.expectedTerminal) : null,
        local_breakdown:         expectation ?? null,
    };

    await db.shifts.update(shiftId, patch);

    return { ...shift, ...patch };
}

/** Fold in what the server said once it has answered. */
export async function applyServerShift(shiftId, session, breakdown = null) {
    if (!session) return 0;

    return db.shifts.update(shiftId, {
        server_id:         session.id ?? null,
        expected_cash:     session.expected_cash ?? null,
        expected_terminal: session.expected_terminal ?? null,
        cash_variance:     session.cash_variance ?? null,
        terminal_variance: session.terminal_variance ?? null,
        breakdown:         breakdown ?? null,
    });
}

export async function markOpenSynced(shiftId, session = null) {
    await db.shifts.update(shiftId, { open_synced: 1, server_id: session?.id ?? null });
}

export async function markCloseSynced(shiftId) {
    await db.shifts.update(shiftId, { close_synced: 1 });
}

/**
 * How long to wait before trying a failing shift again.
 *
 * A till in a shop with no signal all afternoon should not hammer a dead
 * connection every thirty seconds for four hours — it costs battery and data on
 * a device that has little of either. Widens quickly, then holds at half an
 * hour, which is far more often than a shop's connectivity actually changes.
 */
const BACKOFF_MS = [30_000, 60_000, 120_000, 300_000, 900_000, 1_800_000];

export function backoffFor(attempts = 0) {
    return BACKOFF_MS[Math.min(attempts, BACKOFF_MS.length - 1)];
}

/** Shifts with something still to tell the server. */
export async function pendingShifts(now = Date.now()) {
    const rows = await db.shifts.toArray();

    return rows.filter((row) => {
        // A refusal the till cannot fix by asking again. Retrying for ever
        // would only bury it; it is surfaced to the cashier instead.
        if (row.sync_status === 'rejected') return false;

        const outstanding = row.open_synced !== 1
            || (row.status === STATUS_PENDING_REVIEW && row.close_synced !== 1);

        if (!outstanding) return false;

        return !row.next_attempt_at || row.next_attempt_at <= now;
    });
}

/** Record a failed attempt and hold the next one off. */
export async function noteSyncFailure(shiftId, now = Date.now()) {
    const shift = await db.shifts.get(shiftId);

    if (!shift) return;

    const attempts = (shift.sync_attempts ?? 0) + 1;

    await db.shifts.update(shiftId, {
        sync_attempts: attempts,
        next_attempt_at: now + backoffFor(attempts - 1),
    });
}

/** Something got through, so start counting again from zero. */
export async function clearSyncFailure(shiftId) {
    await db.shifts.update(shiftId, {
        sync_attempts: 0,
        next_attempt_at: null,
        sync_status: null,
        sync_message: null,
    });
}

/** A shift the server refused outright, and why. */
export async function rejectedShiftFor(cashierId) {
    if (!cashierId) return null;

    const rows = await db.shifts.where('cashier_id').equals(cashierId).toArray();

    return rows.find((row) => row.sync_status === 'rejected') ?? null;
}

/**
 * Tell the server the day has started, and cache what it sends back.
 *
 * There is one session now, not two: the same call that opens the cash-up opens
 * the record every sale is stamped with. It used to be a separate request made
 * automatically with a float of zero, which is how every Z-report variance came
 * to be wrong by whatever was in the drawer when the cashier arrived.
 *
 * It is also the once-a-shift moment the till refreshes the vendor receipt
 * layout, so a shop that changed its receipt is not waiting for a cashier to
 * happen to log out before it reaches the paper.
 */
export async function announceShift(shift) {
    const { data } = await api.post('/sessions/open', {
        vendor_id: shift.vendor_id,
        opening_float: shift.opening_float,
        ...(shift.terminal_id ? { terminal_id: shift.terminal_id } : {}),
        idempotency_key: shift.open_key,
    });

    cacheSession(data);

    return data?.session ?? null;
}

/** Keep what the till needs offline: the session id, and the receipt layout. */
export function cacheSession(payload) {
    const session = payload?.session ?? null;
    const settings = payload?.vendor_settings ?? null;

    if (session) {
        localStorage.setItem('pos_session', JSON.stringify(session));
    }

    if (settings) {
        localStorage.setItem('pos_vendor_settings', JSON.stringify(settings));
    }

    return session;
}

/** The session this till is stamping sales with, if it has heard of one. */
export function cachedSession() {
    const stored = localStorage.getItem('pos_session');

    if (!stored) return null;

    try {
        return JSON.parse(stored);
    } catch {
        localStorage.removeItem('pos_session');

        return null;
    }
}

/** A key that survives retries, so a replayed request is recognised as one. */
export function newKey(kind) {
    const rand = globalThis.crypto?.randomUUID?.()
        ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    return `cashup-${kind}-${rand}`;
}

function round2(value) {
    const n = Number(value);

    return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
}
