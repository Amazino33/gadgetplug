import { describe, it, expect } from 'vitest';
import {
    addProduct, calc, changeUnit, fromDetail, looksLikeCode, roundTo, serialProblem,
    setUnitCost, setUnitPrice, toPayload, totals, unitRows,
} from './lines';
import { buildIndex, resolveCode, searchLocal } from './catalogue';

const soap = {
    id: 1,
    name: 'Bar Soap',
    base_unit: 'Piece',
    barcode: '1001',
    sku: 'SOAP-1',
    price: 400,
    cost_price: 280,
    track_serial: false,
    units: [
        { id: 11, name: 'Carton', factor: 12, is_base: false, selling_price: 4500, barcodes: ['1001-CTN'] },
        { id: 12, name: 'Half Carton', factor: 6, is_base: false, selling_price: 2300, barcodes: [] },
        { id: 10, name: 'Piece', factor: 1, is_base: true, selling_price: 400, barcodes: [] },
    ],
    barcodes: [{ barcode: '1001', unit_id: null }, { barcode: '1001-CTN', unit_id: 11 }],
};

const carton = { id: 11, name: 'Carton', factor: 12 };

describe('purchase lines', () => {
    it('adds a product in its base unit, at the last base cost', () => {
        const { lines } = addProduct([], soap);

        expect(lines[0].unitId).toBe(10);
        expect(calc(lines[0]).cost).toBe(280);
    });

    it('scanning the same product twice increases its quantity', () => {
        let { lines } = addProduct([], soap);
        ({ lines } = addProduct(lines, soap));

        expect(lines).toHaveLength(1);
        expect(lines[0].qty).toBe(2);
    });

    it('changing the unit shows that unit\'s cost: base cost × factor', () => {
        const line = addProduct([], soap).lines[0];

        expect(calc(changeUnit(line, 11)).cost).toBe(3360);
        expect(calc(changeUnit(line, 12)).cost).toBe(1680);
    });

    it('editing a carton\'s cost sets the base cost, and every unit\'s cost follows', () => {
        let { lines } = addProduct([], { ...soap, scanned_unit_id: 11 });
        lines = setUnitCost(lines, 1, carton, 3600);

        const rows = unitRows(lines[0]);
        expect(rows.find((r) => r.unit.id === 10).cost).toBe(300);
        expect(rows.find((r) => r.unit.id === 12).cost).toBe(1800);
        expect(rows.find((r) => r.unit.id === 11).cost).toBe(3600);

        const c = calc(lines[0]);
        expect(c.costBase).toBe(300);
        expect(c.profit).toBe(100);
        expect(c.margin).toBe(25);
    });

    it('the cost is shared by every line of the same product', () => {
        let { lines } = addProduct([], soap);
        ({ lines } = addProduct(lines, { ...soap, scanned_unit_id: 11 }));
        lines = setUnitCost(lines, 1, carton, 3600);

        expect(calc(lines[0]).cost).toBe(300);
        expect(calc(lines[1]).cost).toBe(3600);
    });

    it('a new selling price moves the other units by the same %, rounded to ₦50', () => {
        const { lines } = addProduct([], soap);
        const anchor = { ...lines[0].prices };

        // Carton 4,500 → 5,400 is +20%.
        const next = setUnitPrice(lines, 1, '11', 5400, { propagate: true, step: 50, anchor })[0].prices;

        expect(next['11']).toBe(5400);
        expect(next['10']).toBe(500);   // 400 × 1.2 = 480 → 500
        expect(next['12']).toBe(2750);  // 2,300 × 1.2 = 2,760 → 2,750
    });

    it('measures the % from where typing started, not from the last keystroke', () => {
        let { lines } = addProduct([], soap);
        const anchor = { ...lines[0].prices };

        for (const typed of [5, 54, 540, 5400]) {
            lines = setUnitPrice(lines, 1, '11', typed, { step: 50, anchor });
        }

        expect(lines[0].prices['10']).toBe(500);
    });

    it('leaves the other units alone when propagation is off', () => {
        const { lines } = addProduct([], soap);
        const next = setUnitPrice(lines, 1, '11', 5400, { propagate: false, step: 50 })[0].prices;

        expect(next).toMatchObject({ 11: 5400, 10: 400, 12: 2300 });
    });

    it('rounds to the shop step, or to the kobo when the step is 0', () => {
        expect(roundTo(480, 50)).toBe(500);
        expect(roundTo(474, 50)).toBe(450);
        expect(roundTo(474.256, 0)).toBe(474.26);
    });

    it('warns when any unit sells at or below its cost', () => {
        let { lines } = addProduct([], soap);
        expect(calc(lines[0]).warn).toBe(false);

        lines = setUnitCost(lines, 1, carton, 4600); // carton cost above its 4,500 price
        expect(calc(lines[0]).warn).toBe(true);
        expect(unitRows(lines[0]).find((r) => r.unit.id === 11).warn).toBe(true);
    });

    it('treats a product with no units as its own base unit', () => {
        const { lines } = addProduct([], { id: 2, name: 'Pen', price: 50, cost_price: null, units: [] });
        const c = calc(lines[0]);

        expect(lines[0].unitId).toBe(null);
        expect(c.factor).toBe(1);
        expect(c.cost).toBe(0);
    });

    it('asks for one serial per base unit on tracked products', () => {
        const phone = { ...soap, id: 3, track_serial: true };
        const line = addProduct([], { ...phone, scanned_unit_id: 11 }).lines[0];

        expect(serialProblem(line)).toBe('0 / 12 serials');
        expect(serialProblem({ ...line, unitId: 10, serials: ['A'] })).toBe(null);
        expect(serialProblem({ ...line, unitId: 10, qty: 2, serials: ['A', 'A'] })).toBe('A serial is entered twice');
    });

    it('builds the payload with every unit\'s selling price, and the totals', () => {
        let { lines } = addProduct([], { ...soap, scanned_unit_id: 11 });
        lines = setUnitCost(lines, 1, carton, 3600).map((l) => ({ ...l, qty: 2 }));

        expect(totals(lines)).toEqual({ total: 7200, units: 24 });
        expect(toPayload(lines)).toEqual([{
            product_id: 1, product_unit_id: 11, quantity: 2, unit_cost: 3600, selling_price: 400,
            unit_prices: [{ unit_id: 12, price: 2300 }, { unit_id: 11, price: 4500 }],
            serials: [],
        }]);
    });

    it('restores a draft, including its unit prices', () => {
        const lines = fromDetail({
            lines: [{ product: soap, unit_id: 11, factor: 12, unit_qty: 3, cost_per_unit: 3600, selling_price: 450, unit_prices: { 11: 5000 }, serials: [] }],
        });

        expect(lines[0]).toMatchObject({ unitId: 11, qty: 3, baseCost: 300 });
        expect(lines[0].prices).toMatchObject({ 10: 450, 11: 5000, 12: 2300 });
    });

    it('tells a code from a name', () => {
        expect(looksLikeCode('6154000012345')).toBe(true);
        expect(looksLikeCode('peak milk')).toBe(false);
    });
});

describe('in-memory catalogue', () => {
    const index = buildIndex([soap, { id: 2, name: 'Peak Milk', barcode: '1002', sku: 'MILK-1', units: [], barcodes: [] }]);

    it('resolves a barcode, a SKU and a unit barcode, case-insensitively', () => {
        expect(resolveCode(index, '1001')).toMatchObject({ id: 1, scanned_unit_id: null });
        expect(resolveCode(index, ' soap-1 ')).toMatchObject({ id: 1 });
        expect(resolveCode(index, '1001-ctn')).toMatchObject({ id: 1, scanned_unit_id: 11 });
        expect(resolveCode(index, '9999')).toBe(null);
    });

    it('searches names from memory, starts-with first', () => {
        const products = [{ id: 1, name: 'Milky Way' }, { id: 2, name: 'Peak Milk' }];

        expect(searchLocal(products, 'milk').map((p) => p.id)).toEqual([1, 2]);
        expect(searchLocal(products, 'm')).toEqual([]);
    });
});
