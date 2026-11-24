/**
 * Product search for the till and the Purchase Terminal.
 *
 * Staff type two short pieces of what they remember, in whatever order they
 * remember them — "peak 400", "milk peak", "golden peny" — and expect the
 * product they meant at the top. Matching the whole typed phrase as one piece
 * of text found none of those. So:
 *
 *   - Names and queries are cleaned the same way: case, accents, hyphens and
 *     punctuation dropped, and "50cl" split into "50 cl", so "coca cola 50"
 *     meets "COCA-COLA 50CL".
 *   - Every typed word must match the START of some word in the product, in
 *     any order. "pea 40" finds PEAK MILK TIN 400G; "ola" does not find COLA.
 *   - Results are ranked: an exact barcode or SKU first, then a name that
 *     starts with what was typed, then word matches — and among equals, what
 *     this branch sells most (sold_30d) and what a shop's own words say
 *     (search_terms: keywords and learned shortcuts).
 *   - Only when nothing matches is a one-letter slip forgiven (two in long
 *     words), so typos still land without filling every list with noise.
 *
 * Each product is broken into words once, when the index is built; a search is
 * then a pass over prepared arrays, a few milliseconds for 10,000 products on
 * a cheap phone.
 */

const ACCENTS = /[̀-ͯ]/g;
const NON_ASCII = /[^\u0000-\u007f]/;

/**
 * "Coca-Cola 50CL" → "coca cola 50 cl". The one cleaning used everywhere.
 *
 * One pass over the characters rather than a chain of regular expressions:
 * it runs for every name in the catalogue each time the index is built.
 */
export function normalize(text) {
    let s = String(text ?? '');
    if (NON_ASCII.test(s)) s = s.normalize('NFKD').replace(ACCENTS, '');

    let out = '';
    let prev = 0; // what was written last: 0 nothing or a space, 1 a letter, 2 a digit, 3 "and"

    for (let i = 0; i < s.length; i++) {
        let c = s.charCodeAt(i);
        if (c >= 65 && c <= 90) c += 32;

        // Apostrophes join: "women's" is "womens".
        if (c === 39 || c === 96 || c === 8217) continue;

        if (c === 38) { // &
            if (prev) out += ' ';
            out += 'and';
            prev = 3;
            continue;
        }

        const kind = c >= 97 && c <= 122 ? 1 : c >= 48 && c <= 57 ? 2 : 0;

        if (kind === 0) {
            if (prev) { out += ' '; prev = 0; }
            continue;
        }

        // A letter next to a digit is a new word: "50cl" is "50 cl".
        if (prev && prev !== kind) out += ' ';
        out += String.fromCharCode(c);
        prev = kind;
    }

    return out.endsWith(' ') ? out.slice(0, -1) : out;
}

export const words = (text) => {
    const n = normalize(text);
    return n ? n.split(' ') : [];
};

const code = (value) => String(value ?? '').trim().toLowerCase();

/**
 * Edit distance between a and b, allowing a swap of two neighbouring letters
 * as one edit; gives up (returns max + 1) as soon as it cannot be within max.
 */
export function distance(a, b, max) {
    if (Math.abs(a.length - b.length) > max) return max + 1;

    let prev2 = null;
    let prev = Array.from({ length: b.length + 1 }, (_, j) => j);

    for (let i = 1; i <= a.length; i++) {
        const row = [i];
        let best = i;

        for (let j = 1; j <= b.length; j++) {
            const cost = a[i - 1] === b[j - 1] ? 0 : 1;
            let v = Math.min(prev[j] + 1, row[j - 1] + 1, prev[j - 1] + cost);

            if (prev2 && i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) {
                v = Math.min(v, prev2[j - 2] + 1);
            }

            row.push(v);
            if (v < best) best = v;
        }

        if (best > max) return max + 1;

        prev2 = prev;
        prev = row;
    }

    return prev[b.length];
}

/** Whether a typed word is a slip of the start of a product word. */
function nearPrefix(word, token) {
    if (word.length < 4 || token[0] !== word[0]) return false;

    const max = word.length >= 7 ? 2 : 1;

    for (let len = word.length - 1; len <= word.length + 1; len++) {
        if (len < 3 || len > token.length) continue;
        if (distance(word, token.slice(0, len), max) <= max) return true;
    }

    return false;
}

/** Every code a product answers to, as the terminals' payloads carry them. */
function codesOf(p) {
    const out = new Set();
    const put = (c) => { const k = code(c); if (k) out.add(k); };

    put(p.barcode);
    put(p.sku);
    for (const b of p.barcodes ?? []) put(typeof b === 'string' ? b : b?.barcode);
    for (const u of p.units ?? []) {
        put(u.sku);
        for (const b of u.barcodes ?? []) put(typeof b === 'string' ? b : b?.barcode);
    }

    return out;
}

/** A product, prepared once for searching. */
export function prepare(product, { preferInStock = false } = {}) {
    const nameWords = words(product.name);
    const terms = (product.search_terms ?? []).map((t) => (typeof t === 'string' ? { term: t, hits: 0 } : t));
    const termWords = [...new Set(terms.flatMap((t) => words(t.term)))];
    const brandWords = words(product.brand);
    const skuText = normalize(product.sku).replace(/ /g, '');
    const stock = product.available_stock ?? product.stock;
    const codes = codesOf(product);

    return {
        product,
        id: product.id,
        // The searchable text: a write-back that changes only stock or price
        // keeps its place in the index.
        signature: `${product.name}|${product.brand ?? ''}|${product.sku ?? ''}|${terms.map((t) => t.term).join(',')}|${[...codes].join(',')}`,
        name: nameWords.join(' '),
        nameWords,
        // "coca" + "cola" also as "cocacola", for whoever types it as one word.
        pairs: nameWords.slice(1).map((w, i) => nameWords[i] + w),
        termWords,
        // Whole shop words and learned shortcuts, for an exact-phrase boost.
        terms: terms.map((t) => ({ text: normalize(t.term), hits: Number(t.hits) || 0 })).filter((t) => t.text),
        brandWords,
        skuText,
        codes,
        // What the server's own search (word-by-word LIKE) can see. A device
        // result is only pruned on the server's say-so when it matched here.
        raw: [product.name, product.brand, product.sku, ...terms.map((t) => t.term)].filter(Boolean).join(' ').toLowerCase(),
        popularity: Math.log10(1 + Math.max(0, Number(product.sold_30d) || 0)) * 1.5,
        stockBonus: preferInStock && stock != null ? (Number(stock) > 0 ? 0.5 : -1) : 0,
    };
}

/**
 * How well one prepared product answers the query, or null when it does not.
 * fuzzy: allow near-misses on words of four letters or more.
 */
function score(entry, query, qWords, fuzzy) {
    let total = 0;
    let lastPos = -1;
    let inOrder = true;
    let firstHitsFirst = false;
    let usedFuzzy = false;
    let serverVisible = true;

    for (let i = 0; i < qWords.length; i++) {
        const w = qWords[i];
        let best = 0;
        let pos = -1;

        for (let k = 0; k < entry.nameWords.length; k++) {
            const t = entry.nameWords[k];
            if (t === w) { best = 3; pos = k; break; }
            if (best < 2 && t.startsWith(w)) { best = 2; pos = k; }
        }

        if (best < 2) for (const t of entry.termWords) if (t.startsWith(w)) { best = 2; break; }
        if (best < 1.5) for (const t of entry.pairs) if (t.startsWith(w)) { best = 1.5; break; }
        if (best < 1) for (const t of entry.brandWords) if (t.startsWith(w)) { best = 1; break; }
        if (best < 1 && entry.skuText.startsWith(w)) best = 1;

        if (best === 0 && fuzzy) {
            for (let k = 0; k < entry.nameWords.length; k++) {
                if (nearPrefix(w, entry.nameWords[k])) { best = 0.8; pos = k; usedFuzzy = true; break; }
            }
            if (best === 0) for (const t of entry.termWords) if (nearPrefix(w, t)) { best = 0.8; usedFuzzy = true; break; }
        }

        if (best === 0) return null;

        if (!entry.raw.includes(w)) serverVisible = false;
        if (i === 0 && pos === 0) firstHitsFirst = true;
        if (pos !== -1) {
            if (pos < lastPos) inOrder = false;
            lastPos = pos;
        }

        total += best;
    }

    if (entry.name === query) total += 6;
    else if (entry.name.startsWith(query)) total += 4;
    if (firstHitsFirst) total += 2;
    if (inOrder && qWords.length > 1) total += 1;

    for (const t of entry.terms) {
        if (t.text === query) { total += 8 + Math.log10(1 + t.hits); break; }
        if (t.text.startsWith(query)) { total += 3; break; }
    }

    total += entry.popularity + entry.stockBonus - entry.nameWords.length * 0.05;

    return { score: total, fuzzy: usedFuzzy, prunable: !usedFuzzy && serverVisible };
}

/** The words a prepared product can be found by. */
function tokensOf(entry) {
    const words = new Set([...entry.nameWords, ...entry.termWords, ...entry.brandWords]);
    if (entry.skuText) words.add(entry.skuText);

    return [...words];
}

/** First position in a sorted list at which value would go. */
function lowerBound(sorted, value) {
    let lo = 0;
    let hi = sorted.length;

    while (lo < hi) {
        const mid = (lo + hi) >>> 1;
        if (sorted[mid] < value) lo = mid + 1;
        else hi = mid;
    }

    return lo;
}

/** key → array of ids, with a sorted list of its keys made when first asked for. */
function postingList() {
    const map = new Map();
    let sorted = null;

    return {
        map,
        add(key, id) {
            const list = map.get(key);
            if (list) list.push(id);
            else { map.set(key, [id]); sorted = null; }
        },
        remove(key, id) {
            const list = map.get(key);
            if (!list) return;
            const rest = list.filter((x) => x !== id);
            if (rest.length) map.set(key, rest);
            else { map.delete(key); sorted = null; }
        },
        keys() { return (sorted ??= [...map.keys()].sort()); },
    };
}

/**
 * An index over a list of products.
 *
 * Every word of every product goes into one sorted vocabulary that points at
 * the products holding it. A typed word finds its products by a binary search
 * for the words it starts; only products every typed word found are scored.
 * Nothing scans the whole catalogue, so a keystroke costs about the same in a
 * shop of 100 products as in one of 10,000.
 *
 * search(query) → [{ product, score, exact, fuzzy, prunable }], best first.
 * exact: the query is one of the product's codes (barcode, unit barcode, SKU).
 */
export function createProductSearch(products = [], options = {}) {
    const entries = new Map(); // id → prepared product
    const words = postingList(); // name, shop words, brand, SKU
    const codes = postingList(); // barcodes and SKUs, matched whole

    const drop = (id) => {
        const old = entries.get(id);
        if (!old) return;
        old.tokens.forEach((t) => words.remove(t, id));
        old.codes.forEach((c) => codes.remove(c, id));
        entries.delete(id);
    };

    const add = (p) => {
        if (p?.id == null) return;

        const entry = prepare(p, options);
        const old = entries.get(p.id);

        // The till writes back every product a server answer carries, mostly
        // with only the stock changed: then the words stay where they are.
        if (old && old.signature === entry.signature) {
            entry.tokens = old.tokens;
            entries.set(p.id, entry);
            return;
        }

        drop(p.id);
        entry.tokens = tokensOf(entry);
        entries.set(p.id, entry);
        entry.tokens.forEach((t) => words.add(t, p.id));
        entry.codes.forEach((c) => codes.add(c, p.id));
    };

    for (const p of products) add(p);

    const collect = (list, w, into) => {
        const sorted = list.keys();
        for (let i = lowerBound(sorted, w); i < sorted.length && sorted[i].startsWith(w); i++) {
            for (const id of list.map.get(sorted[i])) into.add(id);
        }
    };

    /** Ids holding a word that starts with w — or, guessing, nearly does. */
    const idsFor = (w, fuzzy) => {
        const ids = new Set();
        collect(words, w, ids);

        // "cocacola" for COCA COLA: a whole word, then the start of another,
        // on the same product. Tried only when the word itself found nothing.
        if (!ids.size && w.length >= 5) {
            for (let i = 2; i <= w.length - 2; i++) {
                const head = words.map.get(w.slice(0, i));
                if (!head) continue;
                const tail = new Set();
                collect(words, w.slice(i), tail);
                for (const id of head) if (tail.has(id)) ids.add(id);
            }
        }

        if (fuzzy && w.length >= 4) {
            // A slip is looked for only among whole words with the same first letter.
            const sorted = words.keys();
            const first = w[0];
            for (let i = lowerBound(sorted, first); i < sorted.length && sorted[i][0] === first; i++) {
                if (nearPrefix(w, sorted[i])) for (const id of words.map.get(sorted[i])) ids.add(id);
            }
        }

        return ids;
    };

    const run = (query, fuzzy) => {
        const q = normalize(query);
        const qWords = q ? q.split(' ') : [];
        const out = [];

        if (!qWords.length) return out;

        // Smallest set first: the intersection never grows past it.
        const sets = qWords.map((w) => idsFor(w, fuzzy)).sort((a, b) => a.size - b.size);
        let candidates = sets[0];
        for (const set of sets.slice(1)) {
            if (!candidates.size) break;
            const next = new Set();
            for (const id of candidates) if (set.has(id)) next.add(id);
            candidates = next;
        }

        for (const id of candidates) {
            const entry = entries.get(id);
            const s = score(entry, q, qWords, fuzzy);
            if (s) out.push({ product: entry.product, ...s, exact: false });
        }

        // "coca cola" typed in two for a name written as one word.
        if (!out.length && qWords.length > 1) {
            const ids = new Set();
            collect(words, qWords.join(''), ids);
            for (const id of ids) {
                const entry = entries.get(id);
                out.push({ product: entry.product, score: 1.5 + entry.popularity + entry.stockBonus, fuzzy: false, prunable: false, exact: false });
            }
        }

        return out;
    };

    return {
        get size() { return entries.size; },

        add(product) { add(product); },

        addMany(items) { for (const p of items ?? []) add(p); },

        /**
         * addMany a few hundred at a time, letting the page breathe between,
         * so building the index for a big catalogue never freezes a till
         * mid-sale. Resolves once everything is in.
         */
        async addManyInChunks(items, size = 400) {
            const list = items ?? [];
            for (let i = 0; i < list.length; i += size) {
                for (const p of list.slice(i, i + size)) add(p);
                if (i + size < list.length) await new Promise((resolve) => setTimeout(resolve, 0));
            }
        },

        remove(ids) { for (const id of [].concat(ids)) drop(id); },

        search(query, { limit = 10 } = {}) {
            const raw = code(query);
            if (!raw) return [];

            const exact = (codes.map.get(raw) ?? []).map((id) => ({
                product: entries.get(id).product, score: 100, exact: true, fuzzy: false, prunable: true,
            }));

            let found = run(query, false);
            if (!found.length && !exact.length) found = run(query, true);

            const seen = new Set(exact.map((r) => r.product.id));
            found.sort((a, b) => b.score - a.score || String(a.product.name).localeCompare(String(b.product.name)));

            return [...exact, ...found.filter((r) => !seen.has(r.product.id))].slice(0, limit);
        },
    };
}

/**
 * Puts an outside list (a server's answer) in the same order this search
 * would, keeping anything it does not match at the end rather than dropping
 * it — the server may know a reason to show it that the device does not.
 */
export function rankProducts(products, query, options = {}) {
    const index = createProductSearch(products, options);
    const ranked = index.search(query, { limit: Infinity }).map((r) => r.product);
    const placed = new Set(ranked.map((p) => p.id));

    return [...ranked, ...products.filter((p) => !placed.has(p.id))];
}
