import { describe, expect, it } from 'vitest';
import { filterRows, hydrate, sortRows, tileTests } from './products-list';

const COLUMNS = ['id', 'name', 'sku', 'barcode', 'category', 'brand', 'status', 'price', 'cost',
    'available', 'quantity', 'low_at', 'photo', 'created', 'last_sold', 'sold_7d', 'search', 'has_barcode'];

const now = 1_800_000_000;
const day = 86_400;
const old = now - 200 * day;

const rows = hydrate(COLUMNS, [
    [1, 'Peak Milk 400g', 'PEAK', '6001', 10, 'Peak', 'published', 2000, 1500, 12, 12, 5, 'p.jpg', old, now - day, 4, 'peak milk 400g peak 6001', true],
    [2, 'Rice 5kg', 'RICE', null, 11, null, 'draft', 9000, null, 3, 3, 5, null, old, null, 0, 'rice 5kg rice', false],
    [3, 'Soap Bar', 'SOAP', '6003', null, null, 'published', 500, 300, 0, 0, 5, null, old, now - 40 * day, 0, 'soap bar soap 6003', true],
    [4, 'Old Biscuit', 'BISC', '6004', 10, null, 'expired', 300, 200, -2, -2, 5, 'b.jpg', old, now - 100 * day, 0, 'old biscuit bisc 6004', true],
    [5, 'Dusty Tin', 'TIN', '6005', 10, null, 'published', 700, 500, 9, 9, 5, 't.jpg', old, now - 120 * day, 0, 'dusty tin tin 6005', true],
]);
const tests = tileTests(now);
const view = (over = {}) => ({ tile: null, status: '', category: '', search: '', ...over });
const ids = (list) => list.map((r) => r.id);

describe('the summary tiles', () => {
    const counted = (key) => rows.filter(tests[key].test).map((r) => r.id);

    it('stock health', () => {
        expect(counted('in_stock')).toEqual([1, 2, 5]);
        expect(counted('low')).toEqual([2]);
        expect(counted('out')).toEqual([3]);
        expect(counted('negative')).toEqual([4]);
    });

    it('catalogue gaps', () => {
        expect(counted('no_photo')).toEqual([2, 3]);
        expect(counted('no_barcode')).toEqual([2]);
        expect(counted('no_cost')).toEqual([2]);
        expect(counted('no_category')).toEqual([3]);
        expect(counted('draft')).toEqual([2]);
    });

    it('movement: sold this week, and stock on the shelf that has not sold', () => {
        expect(counted('selling')).toEqual([1]);
        // Rice is on the shelf and has never sold; the tin last sold 120 days ago.
        expect(counted('idle_30')).toEqual([2, 5]);
        expect(counted('idle_90')).toEqual([2, 5]);
    });

    it('a new product is not dead stock just for being new', () => {
        const [fresh] = hydrate(COLUMNS, [[9, 'New', null, null, null, null, 'published', 1, 1, 5, 5, 1, null, now - 3 * day, null, 0, 'new', false]]);
        expect(tests.idle_30.test(fresh)).toBe(false);
    });
});

describe('filters and search', () => {
    it('every word typed, in any order, across name, SKU and barcodes', () => {
        expect(ids(filterRows(rows, view({ search: '400 peak' }), tests))).toEqual([1]);
        expect(ids(filterRows(rows, view({ search: '6003' }), tests))).toEqual([3]);
        expect(ids(filterRows(rows, view({ search: 'peak rice' }), tests))).toEqual([]);
    });

    it('tile, status and category narrow together', () => {
        expect(ids(filterRows(rows, view({ category: 10 }), tests))).toEqual([1, 4, 5]);
        expect(ids(filterRows(rows, view({ category: 10, tile: 'in_stock' }), tests))).toEqual([1, 5]);
        // Published includes those whose publish window has ended, as on the server.
        expect(ids(filterRows(rows, view({ status: 'published' }), tests))).toEqual([1, 3, 4, 5]);
    });
});

describe('sorting', () => {
    it('by price, stock, name, newest and sales', () => {
        expect(ids(sortRows(rows, 'price_desc'))).toEqual([2, 1, 5, 3, 4]);
        expect(ids(sortRows(rows, 'stock'))).toEqual([4, 3, 2, 5, 1]);
        expect(ids(sortRows(rows, 'name'))).toEqual([5, 4, 1, 2, 3]);
        expect(ids(sortRows(rows, 'newest'))).toEqual([5, 4, 3, 2, 1]);
        expect(sortRows(rows, 'sold_7d')[0].id).toBe(1);
    });

    it('margin, with unknown cost last', () => {
        const sorted = sortRows(rows, 'margin_desc');
        expect(sorted[sorted.length - 1].id).toBe(2);
        expect(rows[0].margin).toBeCloseTo(25);
    });
});

describe('8,000 products', () => {
    it('filter and sort the whole list fast enough to feel instant', () => {
        const many = hydrate(COLUMNS, Array.from({ length: 8000 }, (_, i) => [
            i, `Product ${i}`, `SKU${i}`, `600${i}`, i % 40, null, 'published', 100 + i, 50, i % 30, i % 30, 5, null, old, null, i % 7,
            `product ${i} sku${i} 600${i}`, true,
        ]));
        const t0 = performance.now();
        const found = sortRows(filterRows(many, view({ search: 'product 7', tile: 'low' }), tests), 'price_desc');
        const ms = performance.now() - t0;

        expect(found.length).toBeGreaterThan(0);
        expect(ms).toBeLessThan(100);
    });
});
