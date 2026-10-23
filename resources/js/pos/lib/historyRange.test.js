import { describe, expect, it } from 'vitest';
import { deviceCovers, deviceSummary, filterLocal, groupByDay, presetRange } from './historyRange';

// Thursday 22 October 2026, mid-morning on the device's clock.
const NOW = new Date(2026, 9, 22, 10, 0);

const at = (y, m, d, h = 12) => new Date(y, m - 1, d, h).toISOString();

describe('presetRange', () => {
    it.each([
        ['today',      { from: '2026-10-22', to: '2026-10-22' }],
        ['yesterday',  { from: '2026-10-21', to: '2026-10-21' }],
        // Weeks start on Monday, as Carbon's do on the server.
        ['this_week',  { from: '2026-10-19', to: '2026-10-22' }],
        ['last_7',     { from: '2026-10-16', to: '2026-10-22' }],
        ['this_month', { from: '2026-10-01', to: '2026-10-22' }],
        ['last_month', { from: '2026-09-01', to: '2026-09-30' }],
        ['this_year',  { from: '2026-01-01', to: '2026-10-22' }],
    ])('%s resolves the way the server does', (preset, expected) => {
        expect(presetRange(preset, {}, NOW)).toEqual(expected);
    });

    it('takes a back-to-front custom range as the same window', () => {
        expect(presetRange('custom', { from: '2026-10-20', to: '2026-10-02' }, NOW))
            .toEqual({ from: '2026-10-02', to: '2026-10-20' });
    });

    it('treats a Sunday as the end of the week, not the start', () => {
        expect(presetRange('this_week', {}, new Date(2026, 9, 25, 9))).toEqual({ from: '2026-10-19', to: '2026-10-25' });
    });
});

describe('deviceCovers', () => {
    it('covers the last seven days, today included', () => {
        expect(deviceCovers({ from: '2026-10-16', to: '2026-10-22' }, NOW)).toBe(true);
    });

    it('does not cover a window reaching further back, so it is never shown as complete', () => {
        expect(deviceCovers({ from: '2026-10-15', to: '2026-10-22' }, NOW)).toBe(false);
        expect(deviceCovers(presetRange('this_month', {}, NOW), NOW)).toBe(false);
    });
});

describe('filterLocal', () => {
    const sales = [
        { reference: 'POS-1', completed_at: at(2026, 10, 22), payment_method: 'cash', status: 'completed', total: 1000, items: [{ product_name: 'Fast Charger' }] },
        { reference: 'POS-2', completed_at: at(2026, 10, 21), payment_method: 'split', status: 'completed', total: 2000, items: [{ product_name: 'Case' }], payments: [{ method: 'cash', amount: 500 }, { method: 'card', amount: 1500 }] },
        { reference: 'POS-3', completed_at: at(2026, 10, 20), payment_method: 'card', status: 'completed', total: 3000, items: [{ product_name: 'Cable' }] },
    ];

    const refs = (rows) => rows.map((s) => s.reference);
    const window = { from: '2026-10-20', to: '2026-10-22' };

    it('keeps only the window', () => {
        expect(refs(filterLocal(sales, { from: '2026-10-21', to: '2026-10-22' }))).toEqual(['POS-1', 'POS-2']);
    });

    it('counts the cash half of a mixed sale as cash', () => {
        expect(refs(filterLocal(sales, { ...window, method: 'cash' }))).toEqual(['POS-1', 'POS-2']);
        expect(refs(filterLocal(sales, { ...window, method: 'split' }))).toEqual(['POS-2']);
    });

    it('searches reference and product name, ignoring case', () => {
        expect(refs(filterLocal(sales, { ...window, q: 'charger' }))).toEqual(['POS-1']);
        expect(refs(filterLocal(sales, { ...window, q: 'pos-3' }))).toEqual(['POS-3']);
    });

    it('finds nothing voided, because the device never learns of a void', () => {
        expect(filterLocal(sales, { ...window, status: 'voided' })).toEqual([]);
    });
});

describe('groupByDay', () => {
    it('groups by the server\'s business date where there is one, newest day first', () => {
        const days = groupByDay([
            { id: 1, business_date: '2026-10-22', completed_at: at(2026, 10, 21, 23) },
            { id: 2, business_date: '2026-10-21', completed_at: at(2026, 10, 21, 10) },
            { id: 3, business_date: '2026-10-22', completed_at: at(2026, 10, 22, 9) },
        ]);

        expect(days.map((d) => d.date)).toEqual(['2026-10-22', '2026-10-21']);
        expect(days[0].sales.map((s) => s.id)).toEqual([1, 3]);
    });
});

describe('deviceSummary', () => {
    it('counts and totals what the device holds', () => {
        expect(deviceSummary([{ total: 1000.1 }, { total: '2000.2' }])).toEqual({ sales_count: 2, total: 3000.3 });
    });
});
