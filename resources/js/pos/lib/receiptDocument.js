/**
 * The 80mm thermal receipt, built in the browser.
 *
 * This is a port of resources/views/pos/receipt.blade.php. The server renders
 * that document for a sale that has an id; offline a sale has no id, and until
 * now the till fell back to printing the React modal instead — a screen layout
 * on thermal paper. Proportional font, grey text, dashed rules, truncated item
 * names and a `position: fixed` block that could not paginate. Customers got a
 * visibly worse receipt for no reason other than the connection.
 *
 * So the same document is built here from what the till already knows. The
 * stylesheet below is the blade template's, kept deliberately identical: when
 * one is tuned the other must be tuned with it, or the two paths drift apart
 * and the offline receipt quietly degrades again.
 *
 * The one deliberate difference is the QR. It addresses the customer's online
 * copy, which does not exist until the sale syncs, so offline it is omitted
 * rather than printed dead.
 *
 * Targets the Xprinter 80mm class of head: 72mm of printable width at 203dpi,
 * 1-bit, no grey.
 */

const ALIGNMENTS = { left: 'al-left', center: 'al-center', right: 'al-right' };

/** 1-bit head, no grey: everything is bold black on white. See the notes inline. */
export const THERMAL_CSS = `
    /* 80mm roll, printed edge to edge.
       No top margin: thermal paper is consumed top-down and the printer
       already feeds a leading strip of its own, so a page margin there is
       blank paper on every single receipt. Sides keep enough to stay off the
       edge, and the bottom keeps room for the tear. */
    @page { size: 80mm auto; margin: 0 3mm 4mm; }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #000;
    }

    body {
        /* Monospace keeps the money column aligned — the single biggest reason
           thermal receipts look "not arranged" is a proportional font. */
        font-family: "Courier New", "DejaVu Sans Mono", monospace;
        font-size: 13px;
        line-height: 1.4;
        width: 72mm;          /* 80mm paper minus the printer's own margins */
        margin: 0 auto;

        /* Everything is bold on purpose. A thermal head burns dots: it has no
           grey, so the driver halftones anti-aliased edges and thin strokes come
           out banded and broken — which is what a light Courier at 11px does on
           real paper. Bold gives every stroke enough width to survive that.
           Monospace advance widths do not change when bold, so the columns hold. */
        font-weight: bold;

        /* Stops the browser lightening anything on its way to the driver, which
           would hand it more grey to halftone. */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .al-left   { text-align: left; }
    .al-center { text-align: center; }
    .al-right  { text-align: right; }

    .store-name {
        font-size: 19px;
        font-weight: bold;
        letter-spacing: 1px;
        margin: 0 0 1mm;
        text-transform: uppercase;
        line-height: 1.15;
    }
    .header-line { margin: 0; font-size: 12px; }

    /* Smaller than the vendor name but larger than an address line: it is the
       second thing a customer looks for, not a footnote. */
    .store-branch {
        margin: 0 0 1mm;
        font-size: 14px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .logo { max-width: 40mm; max-height: 18mm; margin: 0 auto 4px; display: block; }

    /* Solid black hairline, never a grey or a dashed one. A thermal head is
       1-bit: any grey is dithered into a speckled line, and dashes at 203dpi
       come out chewed. Weight is varied with thickness instead of colour. */
    hr {
        border: 0;
        border-top: 1px solid #000;
        margin: 2mm 0;
    }

    /* Label/value rows — the label is allowed to wrap, the value never is. */
    .row {
        display: flex;
        justify-content: space-between;
        gap: 4mm;
        font-size: 12px;
        padding: .3mm 0;
    }
    .row .v { white-space: nowrap; text-align: right; }

    /* One item = a full-width name, then its money on the line below. Three
       columns across 72mm leave the amount about 20mm, which is fine for
       44,000.00 and breaks 1,122,300.00 across two lines. */
    .items { margin: 1mm 0; }
    .item { margin-bottom: 1.6mm; }
    .item-name { word-break: break-word; font-size: 13px; }
    .item-line {
        display: flex;
        justify-content: space-between;
        gap: 4mm;
        font-size: 12px;
    }
    .item-qty { color: #000; }
    .item-amt { white-space: nowrap; font-weight: bold; }

    .totals .row { font-size: 12px; }
    .grand {
        font-size: 17px;
        font-weight: bold;
        border-top: 2px solid #000;
        border-bottom: 2px solid #000;
        padding: 1.2mm 0;
        margin-top: 1.5mm;
    }

    .footer { margin-top: 2.5mm; font-size: 12px; white-space: pre-line; line-height: 1.4; }

    .feed { height: 0; }

    /* On screen (a preview, or a phone) give it a paper-like frame; when
       printing that framing must disappear. */
    @media screen {
        body { padding: 8mm 4mm; box-shadow: 0 0 0 1px #e5e5e5; margin: 12px auto; }
    }
`;

/**
 * Everything written into the document goes through this.
 *
 * A product name is cashier-typed and a footer is vendor-typed; either can
 * contain a `<`. The receipt is assembled as a string and handed to
 * document.write, so an unescaped one stops being text and starts being markup.
 */
export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Money, printed the way the server prints it.
 *
 * Note there is no currency symbol: the on-screen modal uses fmt() and shows
 * "₦", but the paper does not. A thermal font may have no glyph for ₦ and
 * prints a replacement box in the middle of every figure, so the server
 * template has always used a bare number_format here. This matches it.
 */
export function money(amount) {
    return Number(amount ?? 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** "7.50" -> "7.5", "7.00" -> "7" — mirrors the blade's rtrim pair. */
function trimRate(rate) {
    const fixed = Number(rate ?? 0).toFixed(2);

    return fixed.includes('.') ? fixed.replace(/\.?0+$/, '') : fixed;
}

const alignClass = (value) => ALIGNMENTS[value] ?? 'al-center';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());

/** One label/value line. */
function row(label, value) {
    return `<div class="row"><span>${escapeHtml(label)}</span><span class="v">${escapeHtml(value)}</span></div>`;
}

/** Wraps a body in the standalone document the print frame expects. */
function wrap(title, body) {
    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(title)}</title>
<style>${THERMAL_CSS}</style>
</head>
<body>
${body}
</body>
</html>`;
}

/**
 * The store block at the top of any document this till prints.
 *
 * The branch's own address and phone win over the vendor's, for the same reason
 * they do server-side: sending somebody to head office for a product they
 * bought across town is worse than printing nothing.
 */
function header(config) {
    const settings  = config.receipt ?? {};
    const store     = config.store ?? {};
    const showStore = Boolean(store.show && store.name);

    const address = showStore && store.address ? store.address : settings.header_address;
    const phone   = showStore && store.phone   ? store.phone   : settings.header_phone;

    const lines = [];

    // onerror removes it rather than leaving a broken-image icon burnt onto the
    // paper. Offline the logo is only there if the service worker already
    // cached it, and a receipt is not worth failing over a picture.
    if (settings.show_logo && settings.logo_url) {
        lines.push(`<img src="${escapeHtml(settings.logo_url)}" alt="" class="logo" onerror="this.remove()">`);
    }

    const name = String(settings.header_name ?? '').trim() || config.vendor_name || 'Receipt';
    lines.push(`<p class="store-name">${escapeHtml(name)}</p>`);

    if (showStore) {
        lines.push(`<p class="store-branch">${escapeHtml(store.name)}</p>`);
    }

    for (const line of [settings.header_tagline, address, phone, settings.header_extra]) {
        if (String(line ?? '').trim() !== '') {
            lines.push(`<p class="header-line">${escapeHtml(line)}</p>`);
        }
    }

    return `<div class="${alignClass(settings.header_alignment)}">\n${lines.join('\n')}\n</div>`;
}

/** Blank lines so the cut lands clear of the text. */
function feed(config) {
    const count = Number((config.receipt ?? {}).feed_lines ?? 2);

    return '<p class="feed">&nbsp;</p>\n'.repeat(Number.isFinite(count) && count > 0 ? count : 0);
}

/**
 * A completed sale as an 80mm document.
 *
 * Every optional line is guarded on the value actually being there, not on a
 * setting alone. A sale reprinted from this device's own history carries less
 * than one fetched from the server — the local record keeps totals and items
 * but not the tender, the change or the customer — and a guarded line simply
 * does not print, where an unguarded one would put "undefined" on the paper.
 */
export function receiptHtml(sale, config = {}) {
    const settings = config.receipt ?? {};
    const parts    = [header(config), '<hr>'];

    if (settings.show_receipt_number !== false && sale.reference) {
        parts.push(row('Receipt', sale.reference));
    }

    if (settings.show_datetime !== false) {
        // The sale's own time, never the clock. Rendering `new Date()` here is
        // what made a reprint of yesterday's sale come out stamped today.
        const parsed = new Date(sale.completed_at ?? Date.now());
        const soldAt = Number.isNaN(parsed.getTime()) ? new Date() : parsed;

        parts.push(row('Date', soldAt.toLocaleDateString('en-GB')));
        parts.push(row('Time', soldAt.toLocaleTimeString('en-NG', {
            hour: 'numeric', minute: '2-digit', hour12: true,
        }).toUpperCase()));
    }

    if (settings.show_cashier !== false && sale.cashier_name) {
        parts.push(row('Cashier', sale.cashier_name));
    }

    if (settings.show_customer !== false && sale.customer?.name) {
        parts.push(row('Customer', sale.customer.name));
    }

    parts.push('<hr>');

    // The name gets the full width and the money sits on its own line beneath
    // it — no truncation, which is what the modal did to long product names.
    const items = (sale.items ?? []).map((item) => {
        const name = item.product_name ?? item.name ?? '';
        const qty  = settings.show_item_unit_price !== false
            ? `${escapeHtml(item.quantity)} &times; ${escapeHtml(money(item.unit_price))}`
            : escapeHtml(item.quantity);

        return `<div class="item">
<div class="item-name">${escapeHtml(name)}</div>
<div class="item-line"><span class="item-qty">${qty}</span><span class="item-amt">${escapeHtml(money(item.total))}</span></div>
</div>`;
    });

    parts.push(`<div class="items">\n${items.join('\n')}\n</div>`, '<hr>');

    const totals = [row('Subtotal', money(sale.subtotal))];

    if (Number(sale.discount_amount) > 0) {
        totals.push(row('Discount', `-${money(sale.discount_amount)}`));
    }

    // The rate the sale was actually computed with, carried on the sale itself.
    // The modal used to print a hardcoded "VAT (7.5%)", which was a lie on paper
    // for any store on a different rate and for any store with VAT switched off.
    const vatEnabled = sale.vat_enabled ?? config.vat_enabled ?? true;

    if (vatEnabled && Number(sale.vat_amount) > 0) {
        const rate = sale.vat_rate ?? config.vat_rate ?? 7.5;
        totals.push(row(`VAT (${trimRate(rate)}%)`, money(sale.vat_amount)));
    }

    parts.push(
        `<div class="totals">\n${totals.join('\n')}\n`
        + `<div class="row grand"><span>TOTAL</span><span class="v">${escapeHtml(money(sale.total))}</span></div>\n`
        + '</div>',
        '<hr>',
    );

    if (sale.payments?.length) {
        parts.push(row('Payment', 'Split'));
        for (const payment of sale.payments) {
            const label = titleCase(payment.method) + (payment.reference ? ` (${payment.reference})` : '');
            parts.push(row(label, money(payment.amount)));
        }
    } else if (sale.payment_method) {
        parts.push(row('Payment', titleCase(sale.payment_method)));

        if (sale.payment_method === 'cash' && sale.amount_tendered != null) {
            parts.push(row('Tendered', money(sale.amount_tendered)));
        }

        if (sale.payment_method === 'bank_transfer' && sale.bank_transfer_reference) {
            parts.push(row('Reference', sale.bank_transfer_reference));
        }
    }

    if (Number(sale.change_given) > 0) {
        parts.push(row('Change', money(sale.change_given)));
    }

    // A thank-you by default rather than only when configured, matching the
    // server: every vendor wants one and none of them thought to type it.
    const footer = String(settings.footer_text ?? '').trim() || 'Thank you for your patronage.';

    parts.push('<hr>', `<div class="footer ${alignClass(settings.footer_alignment)}">${escapeHtml(footer)}</div>`);

    // No QR. It addresses the customer's online copy, which does not exist
    // until this sale syncs — printing a dead code is worse than printing none.

    parts.push(feed(config));

    return wrap(`Receipt ${sale.reference ?? ''}`.trim(), parts.join('\n'));
}

/**
 * The Z report as an 80mm document.
 *
 * Printed the same way and for the same reason: it went to the same thermal
 * printer through the same screen-styled modal, and came out with the modal's
 * own heading, its Cash-in-Drawer input and its two buttons on the paper.
 */
export function zReportHtml(report, session = {}, config = {}) {
    const parts = [header(config), '<hr>'];

    parts.push('<p class="store-branch al-center">Z-Report</p>', '<hr>');

    if (session.opened_at) {
        parts.push(row('Session opened', session.opened_at));
    }

    if (report.generated_at) {
        const at = new Date(report.generated_at);
        if (!Number.isNaN(at.getTime())) {
            parts.push(row('Generated', at.toLocaleString('en-GB')));
        }
    }

    if (config.cashier_name) {
        parts.push(row('Cashier', config.cashier_name));
    }

    if (session.opening_float != null) {
        parts.push(row('Opening float', money(session.opening_float)));
    }

    parts.push('<hr>');

    const takings = [
        ['Cash Sales', report.cash_sales],
        ['POS / Card Sales', report.card_sales],
        ['Bank Transfer Sales', report.bank_transfer_sales],
    ].map(([label, value]) => row(label, money(value)));

    parts.push(
        `<div class="totals">\n${takings.join('\n')}\n`
        + `<div class="row grand"><span>GROSS</span><span class="v">${escapeHtml(money(report.total_sales))}</span></div>\n`
        + '</div>',
    );

    const adjustments = [
        row('VAT Collected', money(report.total_vat)),
        row('Discounts Given', `-${money(report.total_discounts)}`),
        row('Returns', `-${money(report.total_returns)}`),
    ];

    parts.push('<hr>', `<div class="totals">\n${adjustments.join('\n')}\n</div>`, '<hr>');

    const drawer = [
        row('Transactions', String(report.transaction_count ?? 0)),
        row('Cash Expected', money(report.cash_expected)),
    ];

    // Null means the cashier closed without counting. A variance line computed
    // against nothing would read as a perfect till, which is the opposite of
    // what happened.
    if (report.cash_counted != null) {
        drawer.push(row('Cash Counted', money(report.cash_counted)));

        const variance = Number(report.cash_variance ?? 0);
        drawer.push(row('Cash Variance', `${variance >= 0 ? '+' : '-'}${money(Math.abs(variance))}`));
    }

    parts.push(`<div class="totals">\n${drawer.join('\n')}\n</div>`);

    // Somebody signs for the drawer. On the screen version there was nowhere to.
    parts.push('<hr>', '<div class="footer al-left">Cashier signature:\n\n\nSupervisor:\n\n</div>');

    parts.push(feed(config));

    return wrap('Z-Report', parts.join('\n'));
}
