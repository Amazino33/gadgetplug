import { RETENTION_DAYS } from './salesHistory';

/**
 * Date windows and day grouping for the till's full sales history.
 *
 * Online, the server resolves every preset on the store's clock (ReportPeriod)
 * and says which day each sale belongs to — that is the answer that counts.
 * What is here is for when it cannot be asked: the device's own last-seven-days
 * record, filtered and grouped the same way. The presets mirror ReportPeriod's,
 * week starting Monday, so the two cannot drift into offering different things.
 */

export const PRESETS = [
    { key: 'today',      label: 'Today' },
    { key: 'yesterday',  label: 'Yesterday' },
    { key: 'this_week',  label: 'This week' },
    { key: 'last_7',     label: 'Last 7 days' },
    { key: 'this_month', label: 'This month' },
    { key: 'last_month', label: 'Last month' },
    { key: 'this_year',  label: 'This year' },
    { key: 'custom',     label: 'Pick dates' },
];

const pad = (n) => String(n).padStart(2, '0');

/** A Date as YYYY-MM-DD on this device's clock. */
export const ymd = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

/** The day a stored timestamp falls on, on this device's clock. */
export const localDate = (iso) => {
    const at = new Date(iso);

    return Number.isNaN(at.getTime()) ? null : ymd(at);
};

const addDays = (date, n) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + n);

/**
 * The from/to dates a preset covers, as the server would resolve them.
 *
 * @returns {{from: string, to: string}}
 */
export function presetRange(preset, custom = {}, now = new Date()) {
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    switch (preset) {
        case 'yesterday':
            return { from: ymd(addDays(today, -1)), to: ymd(addDays(today, -1)) };
        case 'this_week':
            // Monday, as Carbon's startOfWeek.
            return { from: ymd(addDays(today, -((today.getDay() + 6) % 7))), to: ymd(today) };
        case 'last_7':
            return { from: ymd(addDays(today, -6)), to: ymd(today) };
        case 'this_month':
            return { from: ymd(new Date(today.getFullYear(), today.getMonth(), 1)), to: ymd(today) };
        case 'last_month':
            return {
                from: ymd(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
                to:   ymd(new Date(today.getFullYear(), today.getMonth(), 0)),
            };
        case 'this_year':
            return { from: ymd(new Date(today.getFullYear(), 0, 1)), to: ymd(today) };
        case 'custom': {
            const from = custom.from || ymd(new Date(today.getFullYear(), today.getMonth(), 1));
            const to   = custom.to || ymd(today);

            // Back to front is read as the cashier meaning the same window.
            return from > to ? { from: to, to: from } : { from, to };
        }
        default:
            return { from: ymd(today), to: ymd(today) };
    }
}

/**
 * Whether the device's own record covers the whole window.
 *
 * It keeps RETENTION_DAYS days, today included. Asked for anything older,
 * showing what it has would present a partial window as the whole of it.
 */
export function deviceCovers(range, now = new Date()) {
    const oldestKept = ymd(addDays(new Date(now.getFullYear(), now.getMonth(), now.getDate()), -(RETENTION_DAYS - 1)));

    return range.from >= oldestKept;
}

/** The device's sales narrowed to a window and the same filters the server applies. */
export function filterLocal(sales, { from, to, method = null, status = null, q = '' }) {
    const needle = q.trim().toLowerCase();

    return sales.filter((sale) => {
        const day = localDate(sale.completed_at);

        if (!day || day < from || day > to) return false;

        if (method === 'split' && sale.payment_method !== 'split') return false;

        if (method && method !== 'split' && sale.payment_method !== method
            && !(sale.payment_method === 'split' && (sale.payments ?? []).some((p) => p.method === method))) {
            return false;
        }

        if (status === 'refunded' && !['refunded', 'partial_refund'].includes(sale.status)) return false;
        if (status && status !== 'refunded' && sale.status !== status) return false;

        if (needle) {
            const inRef = String(sale.reference ?? '').toLowerCase().includes(needle);
            const inItems = (sale.items ?? []).some((i) => String(i.product_name ?? '').toLowerCase().includes(needle));

            if (!inRef && !inItems) return false;
        }

        return true;
    });
}

/**
 * Sales grouped under the day they belong to, newest day first, keeping the
 * order the sales arrived in within each day.
 *
 * Uses the server's business_date where there is one, so an online list is
 * grouped exactly as its day totals were worked out.
 *
 * @returns {Array<{date: string, sales: Array}>}
 */
export function groupByDay(sales) {
    const days = new Map();

    for (const sale of sales) {
        const date = sale.business_date ?? localDate(sale.completed_at) ?? 'unknown';

        if (!days.has(date)) days.set(date, []);
        days.get(date).push(sale);
    }

    return [...days.entries()]
        .sort(([a], [b]) => (a < b ? 1 : a > b ? -1 : 0))
        .map(([date, daySales]) => ({ date, sales: daySales }));
}

/**
 * What the device can honestly total without the server: how many sales and
 * what they came to. Not split by tender — a local record does not know about
 * a void or a refund, and a mixed sale's change is not always kept.
 */
export function deviceSummary(sales) {
    const live = sales.filter((s) => s.status !== 'voided');

    return {
        sales_count: live.length,
        total: Math.round(live.reduce((sum, s) => sum + Number(s.total ?? 0), 0) * 100) / 100,
    };
}

/** "Thu 22 Oct 2026" — a day heading. */
export function dayLabel(date) {
    const [y, m, d] = date.split('-').map(Number);
    const at = new Date(y, m - 1, d);

    if (Number.isNaN(at.getTime())) return date;

    return at.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}
