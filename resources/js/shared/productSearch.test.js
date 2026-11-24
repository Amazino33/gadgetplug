import { describe, expect, it } from 'vitest';
import { createProductSearch, distance, normalize, rankProducts } from './productSearch';

const catalogue = [
    { id: 1, name: 'PEAK MILK TIN 400G', sku: 'GRO-0001', barcode: '6001' },
    { id: 2, name: 'GOLDEN PENNY SEMOVITA 1KG', sku: 'GRO-0002' },
    { id: 3, name: 'COCA-COLA 50CL', sku: 'DRK-0003', barcode: '5449000000996', sold_30d: 400 },
    { id: 4, name: 'COCA-COLA 1L', sku: 'DRK-0004', sold_30d: 3 },
    { id: 5, name: 'DIET COKE 33CL', sku: 'DRK-0005' },
    { id: 6, name: 'COKE ZERO 50CL', sku: 'DRK-0006', sold_30d: 1 },
    { id: 7, name: 'COKE 50CL PET', sku: 'DRK-0007', sold_30d: 250 },
    { id: 8, name: 'MACKEREL FISH (LARGE)', sku: 'FRZ-0008', search_terms: [{ term: 'titus', hits: 5 }] },
    { id: 9, name: "WOMEN'S HAIR CREAM", sku: 'HAI-0009', brand: 'Dark & Lovely' },
    { id: 10, name: 'MALTA GUINNESS 33CL', sku: 'DRK-0010', units: [{ id: 91, sku: 'DRK-0010-CR24', barcodes: ['7001'] }] },
    { id: 11, name: 'PEAK MILK SACHET 16G', sku: 'GRO-0011', sold_30d: 90 },
];

const names = (results) => results.map((r) => r.product.name);
const find = (q, opts) => names(createProductSearch(catalogue, opts).search(q));

describe('normalize', () => {
    it('cleans names and queries the same way', () => {
        expect(normalize('COCA-COLA 50CL')).toBe('coca cola 50 cl');
        expect(normalize("Women's  Hair")).toBe('womens hair');
        expect(normalize('Crème brûlée')).toBe('creme brulee');
        expect(normalize('Dark & Lovely')).toBe('dark and lovely');
    });
});

describe('product search', () => {
    it('matches words in any order, from the start of each word', () => {
        expect(find('peak 400')[0]).toBe('PEAK MILK TIN 400G');
        expect(find('milk peak')).toContain('PEAK MILK TIN 400G');
        expect(find('pea 40')[0]).toBe('PEAK MILK TIN 400G');
        expect(find('tin peak')[0]).toBe('PEAK MILK TIN 400G');
    });

    it('does not match the middle of a word', () => {
        expect(find('ola')).toEqual([]);
    });

    it('ignores hyphens, spacing and unit spelling', () => {
        expect(find('coca cola 50')[0]).toBe('COCA-COLA 50CL');
        expect(find('cocacola 50cl')[0]).toBe('COCA-COLA 50CL');
        expect(find('50 cl coca')[0]).toBe('COCA-COLA 50CL');
        expect(find('womens')[0]).toBe("WOMEN'S HAIR CREAM");
    });

    it('forgives a slip when nothing matches exactly', () => {
        expect(find('golden peny')[0]).toBe('GOLDEN PENNY SEMOVITA 1KG');
        expect(find('malta guiness')[0]).toBe('MALTA GUINNESS 33CL');
        expect(find('semovta')[0]).toBe('GOLDEN PENNY SEMOVITA 1KG');
    });

    it('does not guess at short words', () => {
        expect(find('pek')).toEqual([]);
    });

    it('puts a name that starts with the query above one that merely contains it', () => {
        const list = find('coke');
        expect(list.indexOf('COKE 50CL PET')).toBeLessThan(list.indexOf('DIET COKE 33CL'));
    });

    it('ranks what the branch sells most first among equal matches', () => {
        expect(find('coca')[0]).toBe('COCA-COLA 50CL');
        expect(find('coke 50')[0]).toBe('COKE 50CL PET');
        expect(find('peak')[0]).toBe('PEAK MILK SACHET 16G');
    });

    it('answers an exact barcode, unit barcode or SKU first', () => {
        expect(find('5449000000996')[0]).toBe('COCA-COLA 50CL');
        expect(find('7001')[0]).toBe('MALTA GUINNESS 33CL');
        expect(find('drk-0010-cr24')[0]).toBe('MALTA GUINNESS 33CL');
        expect(find('GRO-0002')[0]).toBe('GOLDEN PENNY SEMOVITA 1KG');
    });

    it("finds a product by the shop's own word for it", () => {
        expect(find('titus')[0]).toBe('MACKEREL FISH (LARGE)');
        expect(find('tit')[0]).toBe('MACKEREL FISH (LARGE)');
    });

    it('finds a product by its brand', () => {
        expect(find('dark lovely')[0]).toBe("WOMEN'S HAIR CREAM");
    });

    it('can prefer what is in stock at the till', () => {
        const items = [
            { id: 1, name: 'BREAD SMALL', available_stock: 0 },
            { id: 2, name: 'BREAD SLICED', available_stock: 12 },
        ];
        expect(names(createProductSearch(items, { preferInStock: true }).search('bread'))[0]).toBe('BREAD SLICED');
    });

    it('is told when a result came from a guess, so it is never pruned on that basis', () => {
        const [hit] = createProductSearch(catalogue).search('golden peny');
        expect(hit.fuzzy).toBe(true);
        expect(hit.prunable).toBe(false);

        const [plain] = createProductSearch(catalogue).search('golden penny');
        expect(plain.prunable).toBe(true);
    });

    it('stays in step as products are added and removed', () => {
        const index = createProductSearch([]);
        index.add({ id: 50, name: 'NEW BISCUIT' });
        expect(names(index.search('biscuit'))).toEqual(['NEW BISCUIT']);
        index.remove(50);
        expect(index.search('biscuit')).toEqual([]);
    });

    it('searches 10,000 products quickly', () => {
        const big = Array.from({ length: 10000 }, (_, i) => ({ id: i, name: `PRODUCT ${i} ITEM ${i % 97} PACK`, sku: `SKU-${i}` }));
        const index = createProductSearch(big);
        const start = performance.now();
        index.search('item 42 pack');
        index.search('prodct 9999'); // falls through to the forgiving pass
        expect(performance.now() - start).toBeLessThan(250);
    });
});

describe('rankProducts', () => {
    it("orders a server's list the same way, keeping what it does not match at the end", () => {
        const server = [catalogue[4], catalogue[6], { id: 99, name: 'SOMETHING THE SERVER KNOWS' }];
        expect(rankProducts(server, 'coke').map((p) => p.id)).toEqual([7, 5, 99]);
    });
});

describe('distance', () => {
    it('counts a swapped pair of letters as one slip', () => {
        expect(distance('semovtia', 'semovita', 2)).toBe(1);
        expect(distance('peny', 'penn', 1)).toBe(1);
        expect(distance('abcdef', 'uvwxyz', 1)).toBe(2);
    });
});
