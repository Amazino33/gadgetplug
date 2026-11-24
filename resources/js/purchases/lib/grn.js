/**
 * The Goods Received Note: a standalone A4 document, printed from a hidden
 * frame the same way the till prints its receipts (see pos/lib/printDocument),
 * but sized to a page rather than an 80mm roll — a GRN is filed, not handed
 * across a counter.
 */
import { fmt } from '../../pos/lib/format';

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const qtyText = (n) => Number(n ?? 0).toLocaleString('en-NG');

const dateText = (iso) => (iso ? new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

export function grnDocument(p, { storeName } = {}) {
    const rows = (p.lines ?? []).map((l, i) => {
        const serials = [...(l.serials ?? []), ...(l.legacy_serial ? [l.legacy_serial] : [])];

        return `
        <tr>
            <td>${i + 1}</td>
            <td><strong>${esc(l.product_name)}</strong><div class="muted">${esc(l.barcode || l.sku || '')}</div>
                ${serials.length ? `<div class="serials">S/N: ${serials.map(esc).join(', ')}</div>` : ''}</td>
            <td class="r">${qtyText(l.unit_qty)} ${esc(l.unit_name)}</td>
            <td class="r">${qtyText(l.base_qty)}</td>
            <td class="r">${fmt(l.cost_per_unit)}</td>
            <td class="r">${fmt(l.base_cost)}</td>
            <td class="r">${fmt(l.line_total)}</td>
        </tr>`;
    }).join('');

    return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>GRN ${esc(p.reference)}</title>
<style>
    @page { size: A4; margin: 14mm; }
    * { box-sizing: border-box; }
    body { font-family: Inter, Arial, sans-serif; font-size: 11px; color: #111; margin: 0; }
    h1 { font-size: 18px; margin: 0; letter-spacing: .04em; }
    .head { display: flex; justify-content: space-between; border-bottom: 3px solid #EA580C; padding-bottom: 8px; margin-bottom: 12px; }
    .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px 16px; margin-bottom: 12px; }
    .meta div span { display: block; color: #666; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #FFF7ED; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; }
    th, td { padding: 6px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
    .r { text-align: right; white-space: nowrap; }
    .muted { color: #777; font-size: 9px; }
    .serials { color: #444; font-size: 9px; margin-top: 2px; }
    .totals { margin-left: auto; width: 260px; margin-top: 12px; }
    .totals div { display: flex; justify-content: space-between; padding: 3px 0; }
    .totals .grand { font-weight: 700; font-size: 13px; border-top: 2px solid #111; padding-top: 6px; }
    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 48px; }
    .sign div { border-top: 1px solid #111; padding-top: 4px; color: #555; }
</style></head><body>
    <div class="head">
        <div><h1>GOODS RECEIVED NOTE</h1><div class="muted">${esc(p.vendor?.name)}${storeName || p.store ? ' · ' + esc(p.store || storeName) : ''}</div></div>
        <div style="text-align:right"><strong style="font-size:14px">${esc(p.reference)}</strong><div class="muted">${esc(p.status_label)}</div></div>
    </div>
    <div class="meta">
        <div><span>Supplier</span>${esc(p.supplier || '—')}</div>
        <div><span>Invoice / Ref No</span>${esc(p.invoice_number || '—')}</div>
        <div><span>Purchase date</span>${dateText(p.purchased_on || p.date)}</div>
        <div><span>Received by</span>${esc(p.received_by || '—')}</div>
        <div><span>Received at</span>${p.received_at ? new Date(p.received_at).toLocaleString('en-NG') : '—'}</div>
        <div><span>Recorded by</span>${esc(p.recorded_by || '—')}</div>
    </div>
    <table>
        <thead><tr><th>#</th><th>Product</th><th class="r">Qty</th><th class="r">Base qty</th><th class="r">Cost / unit</th><th class="r">Cost / base</th><th class="r">Total</th></tr></thead>
        <tbody>${rows}</tbody>
    </table>
    <div class="totals">
        <div class="grand"><span>Total</span><span>${fmt(p.total)}</span></div>
        <div><span>Paid</span><span>${fmt(p.paid)}</span></div>
        <div><span>Balance owed to supplier</span><span>${fmt(p.balance)}</span></div>
    </div>
    ${p.notes ? `<p><strong>Notes:</strong> ${esc(p.notes)}</p>` : ''}
    <div class="sign"><div>Received by (sign)</div><div>Delivered by (sign)</div></div>
</body></html>`;
}

const FRAME_ID = 'grn-print-frame';

export function printGrn(purchase, options) {
    document.getElementById(FRAME_ID)?.remove();

    const frame = document.createElement('iframe');
    frame.id = FRAME_ID;
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;border:0;';
    document.body.appendChild(frame);

    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(grnDocument(purchase, options));
    doc.close();

    setTimeout(() => {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    }, 150);
}
