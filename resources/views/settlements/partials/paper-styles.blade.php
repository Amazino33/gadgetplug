{{--
    The paper these documents are printed on.

    Shared by the settlement statement and the account close so the two cannot
    drift into looking like documents from different businesses. Extracted
    rather than copied for exactly that reason.

    Tables rather than flex or grid throughout: dompdf renders these, and its
    support for modern layout is thin enough that a grid which looks right in
    a browser can collapse in the exported file — which nobody notices until
    the copy in somebody's hand is the wrong one.
--}}
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #18181b; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        /* page-break-after so a section heading never strands itself at the
           foot of a page with its figures overleaf. */
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #71717a;
             margin: 22px 0 6px; border-bottom: 1px solid #e4e4e7; padding-bottom: 4px;
             page-break-after: avoid; }
        /* Applied only to the short summary tables, never the debtor list:
           forcing a long table to stay whole pushes a mostly-blank page. */
        table.keep { page-break-inside: avoid; }
        .sub { color: #52525b; font-size: 11px; margin: 0 0 2px; }
        .ref { font-family: DejaVu Sans Mono, monospace; color: #a1a1aa; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 5px 6px; text-align: left; vertical-align: top; }
        th { font-weight: 600; color: #52525b; border-bottom: 1px solid #e4e4e7; font-size: 10px;
             text-transform: uppercase; letter-spacing: .04em; }
        .num { text-align: right; font-family: DejaVu Sans Mono, monospace; white-space: nowrap; }
        .row td { border-bottom: 1px solid #f4f4f5; }
        .total td { border-top: 2px solid #18181b; font-weight: 700; }
        .headline { background: #fafafa; border: 1px solid #e4e4e7; padding: 10px 12px; margin-top: 8px; }
        .headline .label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #71717a; }
        .headline .value { font-size: 22px; font-weight: 700; }
        .short { color: #b91c1c; }
        .clear { color: #15803d; }
        .muted { color: #71717a; }
        .warn { background: #fffbeb; border: 1px solid #fde68a; padding: 8px 10px; margin-top: 6px; }
        .sign { margin-top: 10px; border: 1px solid #e4e4e7; }
        .sign td { height: 46px; border: 1px solid #e4e4e7; }
    </style>
