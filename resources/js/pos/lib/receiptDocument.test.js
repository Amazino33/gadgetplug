import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { receiptHtml, zReportHtml, money, escapeHtml, THERMAL_CSS } from './receiptDocument';

const config = {
    vat_enabled: true,
    vat_rate: 7.5,
    vendor_name: 'Gadget Corner',
    receipt: {
        header_name: 'GADGET CORNER',
        header_alignment: 'center',
        header_address: '12 Head Office Road, Lagos',
        header_phone: '0800 000 0000',
        show_receipt_number: true,
        show_datetime: true,
        show_cashier: true,
        show_customer: true,
        show_item_unit_price: true,
        footer_alignment: 'center',
        feed_lines: 2,
    },
    store: { name: 'Ikeja Branch', address: '5 Allen Avenue', phone: '0801 111 2222', show: true },
};

const sale = {
    reference: 'OFF-1757000000000-AB12',
    completed_at: '2026-09-10T14:30:00.000Z',
    cashier_name: 'Ada Cashier',
    customer: { name: 'Chike Buyer' },
    items: [
        { product_name: 'Anker PowerCore 20000mAh Power Bank', quantity: 2, unit_price: 44000, total: 88000 },
    ],
    subtotal: 88000,
    discount_amount: 0,
    vat_amount: 6600,
    vat_rate: 7.5,
    vat_enabled: true,
    total: 94600,
    payment_method: 'cash',
    amount_tendered: 100000,
    change_given: 5400,
};

describe('receiptHtml — thermal layout', () => {
    it('prints as a monospace, bold, black document rather than the screen modal', () => {
        const html = receiptHtml(sale, config);

        expect(html).toContain('"Courier New"');
        expect(html).toContain('font-weight: bold');
        expect(html).toContain('print-color-adjust: exact');
        // The 80mm roll, with no top margin — the printer feeds its own.
        expect(html).toContain('@page { size: 80mm auto; margin: 0 3mm 4mm; }');
        expect(html).toContain('width: 72mm');
    });

    it('carries no grey and no dashed rule for a 1-bit head to dither', () => {
        const html = receiptHtml(sale, config);

        expect(html).toContain('border-top: 1px solid #000');
        expect(html).not.toMatch(/border.*dashed/);
        // Tailwind's greys are what the old modal print put on paper.
        expect(html).not.toMatch(/#9ca3af|#d1d5db|#6b7280/i);
    });

    it('never truncates an item name', () => {
        const html = receiptHtml(sale, config);

        expect(html).toContain('Anker PowerCore 20000mAh Power Bank');
        expect(html).toContain('word-break: break-word');
        expect(html).not.toContain('text-overflow');
    });

    it('omits the QR, which addresses a copy that does not exist until the sale syncs', () => {
        const html = receiptHtml(sale, config);

        expect(html).not.toContain('<svg');
        expect(html.toLowerCase()).not.toContain('scan for your receipt');
    });
});

describe('receiptHtml — the sale it is actually printing', () => {
    it("stamps the sale's own time, not the clock", () => {
        // Deliberately long past, so "today" can never coincide with it however
        // long this suite lives.
        const when = new Date('2019-03-04T14:30:00.000Z');
        const html = receiptHtml({ ...sale, completed_at: when.toISOString() }, config);

        expect(html).toContain(when.toLocaleDateString('en-GB'));
        // The bug: a reprint rendered new Date() and came out stamped today.
        expect(html).not.toContain(new Date().toLocaleDateString('en-GB'));
    });

    it('falls back to now only when the sale carries no time at all', () => {
        const html = receiptHtml({ ...sale, completed_at: undefined }, config);

        expect(html).toContain(new Date().toLocaleDateString('en-GB'));
    });

    it('prints the rate the sale was computed with, not a hardcoded 7.5%', () => {
        const html = receiptHtml({ ...sale, vat_rate: 5, vat_amount: 4400 }, config);

        expect(html).toContain('VAT (5%)');
        expect(html).not.toContain('VAT (7.5%)');
    });

    it('leaves the VAT line off entirely when the store does not charge it', () => {
        const html = receiptHtml({ ...sale, vat_enabled: false, vat_amount: 0 }, config);

        expect(html).not.toContain('VAT');
    });

    it('trims a whole-number rate to look like one', () => {
        expect(receiptHtml({ ...sale, vat_rate: 7 }, config)).toContain('VAT (7%)');
        expect(receiptHtml({ ...sale, vat_rate: 7.25 }, config)).toContain('VAT (7.25%)');
    });

    it('shows the branch over the vendor address when the vendor has several', () => {
        const html = receiptHtml(sale, config);

        expect(html).toContain('Ikeja Branch');
        expect(html).toContain('5 Allen Avenue');
        expect(html).not.toContain('12 Head Office Road');
    });

    it('falls back to the vendor address for a single-store vendor', () => {
        const html = receiptHtml(sale, { ...config, store: { show: false } });

        expect(html).toContain('12 Head Office Road, Lagos');
        expect(html).not.toContain('Ikeja Branch');
    });

    it('prints tender and change for a cash sale', () => {
        const html = receiptHtml(sale, config);

        expect(html).toContain('Tendered');
        expect(html).toContain('100,000.00');
        expect(html).toContain('Change');
        expect(html).toContain('5,400.00');
    });

    it('itemises a split payment', () => {
        const html = receiptHtml({
            ...sale,
            payment_method: 'split',
            amount_tendered: null,
            payments: [
                { method: 'cash', amount: 50000, reference: null },
                { method: 'bank_transfer', amount: 44600, reference: 'TRF-99' },
            ],
        }, config);

        expect(html).toContain('Split');
        expect(html).toContain('Bank Transfer (TRF-99)');
        expect(html).toContain('44,600.00');
    });

    it('leaves out what a locally-recorded sale never kept, rather than printing undefined', () => {
        // This is the shape salesHistory.recordSale stores: totals and items,
        // but no tender, no change, no customer.
        const local = {
            reference: 'OFF-1',
            completed_at: '2026-09-09T09:00:00.000Z',
            items: [{ product_name: 'Cable', quantity: 1, unit_price: 2000, total: 2000 }],
            subtotal: 2000,
            vat_amount: 150,
            total: 2150,
            payment_method: 'cash',
        };
        const html = receiptHtml(local, config);

        expect(html).not.toContain('undefined');
        expect(html).not.toContain('NaN');
        expect(html).not.toContain('Tendered');
        expect(html).not.toContain('Customer');
        expect(html).toContain('2,150.00');
    });

    it('escapes a product name that contains markup', () => {
        const html = receiptHtml({
            ...sale,
            items: [{ product_name: '<script>alert(1)</script> Cable', quantity: 1, unit_price: 1, total: 1 }],
        }, config);

        expect(html).not.toContain('<script>alert(1)</script>');
        expect(html).toContain('&lt;script&gt;');
    });

    it('survives an empty config, because a receipt still has to come out', () => {
        const html = receiptHtml(sale, {});

        expect(html).toContain('94,600.00');
        expect(html).toContain('Thank you for your patronage.');
        expect(html).not.toContain('undefined');
    });
});

describe('zReportHtml', () => {
    const report = {
        generated_at: '2026-09-10T18:00:00.000Z',
        cash_sales: 120000,
        card_sales: 40000,
        bank_transfer_sales: 15000,
        total_sales: 175000,
        total_vat: 12000,
        total_discounts: 3000,
        total_returns: 1000,
        transaction_count: 27,
        cash_expected: 130000,
        cash_counted: 129500,
        cash_variance: -500,
    };
    const session = { opened_at: '2026-09-10 08:00', opening_float: 10000 };

    it('is the same 80mm thermal document as the receipt', () => {
        const html = zReportHtml(report, session, config);

        expect(html).toContain('@page { size: 80mm auto; margin: 0 3mm 4mm; }');
        expect(html).toContain('"Courier New"');
        expect(html).toContain('font-weight: bold');
    });

    it('carries none of the modal chrome that used to land on the paper', () => {
        const html = zReportHtml(report, session, config);

        expect(html).not.toContain('Cash in Drawer (counted physically)');
        expect(html).not.toContain('<input');
        expect(html).not.toContain('<button');
        // The colour-coded figures a 1-bit head cannot render.
        expect(html).not.toContain('#3B82F6');
        expect(html).not.toContain('#8B5CF6');
    });

    it('reports the drawer, with the variance signed', () => {
        const html = zReportHtml(report, session, config);

        expect(html).toContain('129,500.00');
        expect(html).toContain('-500.00');
        expect(html).toContain('27');
    });

    it('leaves the variance off when the drawer was never counted', () => {
        const html = zReportHtml({ ...report, cash_counted: null, cash_variance: null }, session, config);

        expect(html).not.toContain('Cash Variance');
        expect(html).toContain('Cash Expected');
    });

    it('gives the report a signature block, since it is what gets filed', () => {
        expect(zReportHtml(report, session, config)).toContain('Cashier signature:');
    });
});

describe('money', () => {
    it('prints no currency symbol — a thermal font may have no glyph for it', () => {
        expect(money(1122300)).toBe('1,122,300.00');
        expect(money(0)).toBe('0.00');
        expect(money(null)).toBe('0.00');
        expect(money(undefined)).toBe('0.00');
    });
});

describe('escapeHtml', () => {
    it('neutralises every character that could open a tag or an attribute', () => {
        expect(escapeHtml(`<a href="x" id='y'>&`)).toBe('&lt;a href=&quot;x&quot; id=&#039;y&#039;&gt;&amp;');
    });

    it('renders a missing value as nothing at all', () => {
        expect(escapeHtml(null)).toBe('');
        expect(escapeHtml(undefined)).toBe('');
    });
});

describe('the port has not drifted from the server template', () => {
    // The two renderers put paper in the same customer's hand and must not
    // diverge. Tuning one for the printer and forgetting the other is exactly
    // how the offline receipt became the worse of the two in the first place.
    const strip = (css) =>
        css.replace(/\/\*[\s\S]*?\*\//g, '')
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean);

    const bladeCss = strip(
        readFileSync('resources/views/pos/receipt.blade.php', 'utf8')
            .split('<style>')[1]
            .split('</style>')[0],
    );

    it('carries every rule the blade template does, but for the QR', () => {
        const ported  = new Set(strip(THERMAL_CSS));
        const missing = bladeCss.filter((rule) => !ported.has(rule));

        // The QR is the one deliberate omission: it addresses a copy that does
        // not exist until the sale syncs.
        expect(missing.filter((rule) => !rule.startsWith('.qr'))).toEqual([]);
    });

    it('adds no rule of its own that the blade template lacks', () => {
        const blade = new Set(bladeCss);

        expect(strip(THERMAL_CSS).filter((rule) => !blade.has(rule))).toEqual([]);
    });
});
