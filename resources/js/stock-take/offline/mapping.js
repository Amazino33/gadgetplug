import { getMeta, setMeta } from './db';
import { buildIndex } from './lookup';

/**
 * Mapping an aisle at scanning speed.
 *
 * The shop's products come down once (the same compact rows as a task pack)
 * and stay on the phone, so a scan is found here and the beep is instant.
 * What has been put on an aisle waits in a small queue on the phone and goes
 * up in batches a moment later — the scanner never waits for the server, and
 * nothing is lost if the signal drops or the app is closed.
 *
 * A code the phone does not know still asks the server (it may be a product
 * added since the download, or truly unknown).
 */

const CATALOGUE_KEY = 'mapCatalogue';
const QUEUE_KEY     = 'mapQueue';
const BATCH_DELAY   = 800;
const RETRY_DELAY   = 4000;

/* ---------- the catalogue ---------- */

let catalogue = null; // { v, index }
let loading = null;

/**
 * The product index for mapping: the phone's copy at once when there is one,
 * then a quiet check for anything newer. `onIndex` is called each time the
 * index is ready or replaced.
 */
export function loadCatalogue(api, onIndex) {
    if (catalogue) onIndex(catalogue.index);

    loading ??= (async () => {
        if (!catalogue) {
            const stored = await getMeta(CATALOGUE_KEY).catch(() => null);
            if (stored?.products) catalogue = { v: stored.v, index: buildIndex(stored) };
        }
        if (catalogue) onIndex(catalogue.index);

        try {
            const { data } = await api.get('/mapping-catalogue', { params: catalogue ? { v: catalogue.v } : {} });
            if (!data.same && data.products) {
                catalogue = { v: data.v, index: buildIndex(data) };
                setMeta(CATALOGUE_KEY, { v: data.v, products: data.products }).catch(() => {});
            }
        } catch { /* offline: the phone's copy will do */ }

        return catalogue;
    })().finally(() => { loading = null; });

    return loading.then((c) => { if (c) onIndex(c.index); return c?.index ?? null; });
}

/** For tests. */
export function resetCatalogue() {
    catalogue = null;
    loading = null;
}

/* ---------- the save queue ---------- */

let queue = null;          // [{ aisleId, taskId, productId }]
let api = null;
let timer = null;
let sending = false;
let offline = false;
const listeners = new Set();

async function ready() {
    if (queue === null) {
        const stored = await getMeta(QUEUE_KEY, []).catch(() => []);
        queue ??= Array.isArray(stored) ? stored : [];
    }
    return queue;
}

const persist = () => setMeta(QUEUE_KEY, queue.length ? queue : null).catch(() => {});

function emit(event = {}) {
    const state = { waiting: queue?.length ?? 0, offline, ...event };
    listeners.forEach((fn) => fn(state));
}

/** Told about the queue: { waiting, offline, saved?, refused? }. Returns an unsubscribe. */
export function onMapQueue(fn) {
    listeners.add(fn);
    fn({ waiting: queue?.length ?? 0, offline });
    return () => listeners.delete(fn);
}

/** Product ids on this aisle still waiting to go up. */
export function waitingFor(aisleId) {
    return new Set((queue ?? []).filter((q) => q.aisleId === aisleId).map((q) => q.productId));
}

function schedule(ms) {
    clearTimeout(timer);
    timer = setTimeout(flush, ms);
}

/** Starts sending (and keeps sending) whatever is waiting, with this api. */
export async function startMapQueue(withApi) {
    api = withApi;
    await ready();
    if (queue.length) schedule(0);
    emit();
}

/** One product put on an aisle: saved in the next batch. */
export async function queueMap(withApi, aisleId, taskId, productId) {
    api = withApi;
    await ready();
    if (!queue.some((q) => q.aisleId === aisleId && q.productId === productId)) {
        queue.push({ aisleId, taskId: taskId ?? null, productId });
        persist();
    }
    emit();
    if (!sending) schedule(BATCH_DELAY);
}

/** Taken off before it went up: nothing to send. True when it was waiting. */
export async function unqueueMap(aisleId, productId) {
    await ready();
    const before = queue.length;
    queue = queue.filter((q) => !(q.aisleId === aisleId && q.productId === productId));
    if (queue.length !== before) { persist(); emit(); return true; }
    return false;
}

/** Sends everything waiting now. Resolves when this round is done. */
export async function flush() {
    clearTimeout(timer);
    await ready();
    if (sending || !api || !queue.length) return;

    sending = true;
    try {
        while (queue.length) {
            const { aisleId, taskId } = queue[0];
            const batch = queue.filter((q) => q.aisleId === aisleId && q.taskId === taskId).slice(0, 200);
            const ids = batch.map((q) => q.productId);

            try {
                const { data } = await api.post(`/aisles/${aisleId}/map`, { product_ids: ids, task_id: taskId });
                offline = false;
                queue = queue.filter((q) => !batch.includes(q));
                persist();
                emit({ saved: { aisleId, ids }, missing: data?.missing?.length ? { aisleId, ids: data.missing } : undefined });
            } catch (e) {
                const status = e?.response?.status;
                if (status && status >= 400 && status < 500 && status !== 401 && status !== 408 && status !== 419 && status !== 429) {
                    // Refused (not allowed to map this aisle, or it is gone): sending again will not help.
                    queue = queue.filter((q) => !batch.includes(q));
                    persist();
                    emit({ refused: { aisleId, ids, message: e?.response?.data?.message || 'Could not save to this aisle.' } });
                } else {
                    offline = true;
                    emit();
                    schedule(RETRY_DELAY);
                    return;
                }
            }
        }
    } finally {
        sending = false;
    }
}

/** For tests. */
export function resetMapQueue() {
    clearTimeout(timer);
    queue = null;
    api = null;
    sending = false;
    offline = false;
    listeners.clear();
}
