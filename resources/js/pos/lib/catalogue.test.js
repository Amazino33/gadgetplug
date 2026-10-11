import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CATALOGUE_PAGE, fetchCatalogue } from './catalogue';

vi.mock('./api', () => ({ default: { get: vi.fn() } }));

const products = (from, count) => Array.from({ length: count }, (_, i) => ({ id: from + i, name: `P${from + i}` }));

let api;

beforeEach(async () => {
    api = (await import('./api')).default;
    api.get.mockReset();
});

describe('fetching the catalogue', () => {
    it('asks a page at a time and returns every product once', async () => {
        // 1,200 products: two full pages and a short one.
        const all = products(1, 1200);
        api.get.mockImplementation(async (url, { params }) => ({
            data: all.filter((p) => p.id > params.after_id).slice(0, params.limit),
        }));

        const result = await fetchCatalogue(7);

        expect(result).toHaveLength(1200);
        expect(new Set(result.map((p) => p.id)).size).toBe(1200);
        expect(api.get).toHaveBeenCalledTimes(3);
        expect(api.get.mock.calls[1][1].params).toEqual({ vendor_id: 7, after_id: CATALOGUE_PAGE, limit: CATALOGUE_PAGE });
    });

    it('takes a server that ignores paging at its word, without looping', async () => {
        api.get.mockResolvedValue({ data: products(1, 800) });

        expect(await fetchCatalogue(7)).toHaveLength(800);
        expect(api.get).toHaveBeenCalledTimes(1);
    });

    it('fails as a whole when a page fails, so the caller keeps its old copy', async () => {
        api.get
            .mockResolvedValueOnce({ data: products(1, CATALOGUE_PAGE) })
            .mockRejectedValueOnce(new Error('500'));

        await expect(fetchCatalogue(7)).rejects.toThrow();
    });
});
