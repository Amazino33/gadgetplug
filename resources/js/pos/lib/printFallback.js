/**
 * Prints one element of the current page, hiding everything else.
 *
 * The till's real receipt is a server-rendered document printed from a hidden
 * iframe. This is the other kind of printing: a modal that only exists in the
 * browser — a Z report, or a receipt for a sale queued offline that has no
 * server document to fetch yet.
 *
 * The body class is the whole point. These print rules used to be always live,
 * so ANY print of the POS page rendered whatever carried the print class —
 * which is how one sale came out of the printer twice, the old modal receipt
 * alongside the new document. Now the rules exist only for the moment they are
 * asked for, and clean up after themselves.
 */
export function printFallback() {
    document.body.classList.add('printing-receipt-fallback');

    const cleanUp = () => document.body.classList.remove('printing-receipt-fallback');

    window.addEventListener('afterprint', cleanUp, { once: true });

    try {
        window.print();
    } finally {
        // afterprint does not fire reliably on every Android WebView, and a
        // class left behind would hide the whole app on the next print.
        setTimeout(cleanUp, 2000);
    }
}

export default printFallback;
