/**
 * Prints a standalone HTML document from a hidden iframe.
 *
 * Lifted out of ReceiptModal so the Z report can use it too. Both were printing
 * a screen modal through the page's own @media print rules; both now build a
 * real 80mm document and print that instead, and there is no reason for two
 * copies of the frame handling.
 *
 * The frame is the only thing that prints. The page's print rules are not
 * involved at all, so nothing can render a second copy alongside it.
 */
const FRAME_ID = 'receipt-print-frame';

export function printDocument(html) {
    // One frame at a time. Left to accumulate, a till that printed all morning
    // would carry a morning's worth of receipts in the DOM.
    document.getElementById(FRAME_ID)?.remove();

    const frame = document.createElement('iframe');
    frame.id = FRAME_ID;
    frame.setAttribute('aria-hidden', 'true');
    // Sized to the paper and parked off-screen rather than collapsed to 0x0.
    // A zero-width frame gives the document a zero-width containing block to
    // lay out against, and the printer then scales whatever it got to fit the
    // roll — which is what made the print blurry.
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:80mm;height:297mm;border:0;';
    document.body.appendChild(frame);

    frame.contentWindow.document.open();
    frame.contentWindow.document.write(html);
    frame.contentWindow.document.close();

    // Printed from the parent, after the document has settled. Driving it here
    // means the print happens once, when we say, and never races the content.
    const run = () => {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    };

    // Images need to be laid out or the thermal head gets a half-rendered page.
    if (frame.contentWindow.document.readyState === 'complete') {
        setTimeout(run, 50);
    } else {
        frame.contentWindow.addEventListener('load', () => setTimeout(run, 50), { once: true });
    }

    return frame;
}

export default printDocument;
