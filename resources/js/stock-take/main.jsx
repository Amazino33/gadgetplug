import { useCallback, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { liveQuery } from 'dexie';
import Home from './components/Home';
import AislePicker from './components/AislePicker';
import MapAisle from './components/MapAisle';
import CountTask from './components/CountTask';
import api, { errorMessage } from './lib/api';
import { db, getMeta, setMeta } from './offline/db';
import { startAllSync } from './offline/outbox';
import { flush as flushMapping, startMapQueue } from './offline/mapping';
import '../../css/stock-take.css';

const CONFIG = window.STOCK_TAKE_CONFIG ?? {};
const HOME_KEY = `home:${CONFIG.vendorSlug ?? ''}`;
const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

// A counter who is signed out (the session ended) goes back to the shop's
// sign-in page, remembered from the last time they were in.
try {
    if (CONFIG.signInUrl) localStorage.setItem('stockTakeSignIn', CONFIG.signInUrl);
} catch { /* private window: they will be asked to sign in the usual way */ }

/** Where to sign in again: a counter's QR page (remembered), everyone else's the usual login. */
function signInHref() {
    try { return localStorage.getItem('stockTakeSignIn') || '/login'; } catch { return '/login'; }
}

/**
 * Stock Take on a phone. Teal, so nobody mistakes it for the till (green) or
 * Purchases (orange): nothing here sells or receives anything.
 *
 * Screens: My Tasks → (a task's aisles, or every aisle) → map one aisle.
 *
 * Release 2:
 * - My Tasks shows at once from the phone, then refreshes from the server;
 *   with no signal it stays as it was, and says so.
 * - One uploader for every task with counts waiting, so a task left for
 *   another keeps uploading (A7).
 * - Leaving for the panel with counts not yet uploaded asks first: they stay
 *   on the phone either way, and are never cleared quietly.
 */
export function App() {
    const [view, setView]         = useState({ name: 'home' });
    const [home, setHome]         = useState(null);
    const [error, setError]       = useState('');
    const [notice, setNotice]     = useState('');
    const [offline, setOffline]   = useState(false);
    const [signedOut, setSignedOut] = useState(false);
    const [local, setLocal]       = useState({ waiting: {}, handIn: {} });

    const syncer = useMemo(() => startAllSync(api), []);
    useEffect(() => () => syncer.stop(), [syncer]);
    // Aisle mapping saved on this phone but not yet sent (no signal then): send it now, and when the signal is back.
    useEffect(() => {
        startMapQueue(api).catch(() => {});
        const online = () => flushMapping().catch(() => {});
        window.addEventListener('online', online);
        return () => window.removeEventListener('online', online);
    }, []);

    // What is still on the phone, per task: for My Tasks and the panel warning.
    useEffect(() => {
        const sub = liveQuery(async () => {
            const waiting = {};
            for (const row of await db.outbox.toArray()) {
                if (row.op.type !== 'submit') waiting[row.taskId] = (waiting[row.taskId] ?? 0) + 1;
            }
            const handIn = {};
            for (const row of await db.meta.where('key').startsWith('handIn:').toArray()) {
                handIn[Number(row.key.slice(7))] = row.value?.status;
            }
            return { waiting, handIn };
        }).subscribe({ next: setLocal, error: () => {} });
        return () => sub.unsubscribe();
    }, []);

    useEffect(() => {
        const out = () => setSignedOut(true);
        window.addEventListener('stock-take:signed-out', out);
        return () => window.removeEventListener('stock-take:signed-out', out);
    }, []);

    const loadHome = useCallback(async () => {
        setError('');
        const saved = await getMeta(HOME_KEY);
        if (saved) setHome((current) => current ?? saved);

        try {
            const { data } = await api.get('/home');
            setHome(data);
            setOffline(false);
            setSignedOut(false);
            await setMeta(HOME_KEY, data);
        } catch (e) {
            if (!e?.response && saved) { setOffline(true); return; }
            if (e?.response?.status !== 401) setError(errorMessage(e, 'Could not load your tasks.'));
        }
    }, []);

    useEffect(() => { loadHome().finally(registerWorker); }, [loadHome]);

    const goHome = useCallback(() => { setView({ name: 'home' }); loadHome(); }, [loadHome]);

    const waitingTotal = Object.values(local.waiting).reduce((a, b) => a + b, 0);

    const leaveForPanel = (e) => {
        if (waitingTotal > 0 && !window.confirm(`${waitingTotal} count${waitingTotal === 1 ? ' is' : 's are'} not uploaded yet. They stay on this phone and upload next time Stock Take is open with signal. Leave anyway?`)) {
            e.preventDefault();
        }
    };

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-[#F9FAFB] dark:bg-gray-950" style={{ fontFamily: 'Inter, sans-serif' }}>
            <div className="shrink-0 bg-teal-700 px-3 py-1.5 text-center text-[11px] font-extrabold uppercase tracking-[0.12em] text-white sm:text-sm" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                Stock Take
            </div>
            {/* Hidden while counting: the counting screen has its own header, and the camera needs the room. */}
            {view.name !== 'count' && (
            <header className="flex shrink-0 items-center gap-3 border-b border-gray-100 bg-white px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
                <div className="min-w-0 flex-1">
                    <div className="truncate text-base font-bold text-gray-900 dark:text-gray-100">{CONFIG.vendorName}</div>
                    <div className="truncate text-sm text-gray-500">{home?.store?.name ?? CONFIG.storeName} · {CONFIG.userName}</div>
                </div>
                {CONFIG.isCounter ? (
                    // A counter has no panel to go back to: they sign out, ready for the next person.
                    // Counts still on the phone stay there either way; it asks first.
                    <form method="POST" action={CONFIG.signOutUrl} onSubmit={leaveForPanel}>
                        <input type="hidden" name="_token" value={CSRF} />
                        <button type="submit" className="min-h-12 shrink-0 rounded-lg border border-gray-200 px-4 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-300">Sign out</button>
                    </form>
                ) : (
                    <a href={CONFIG.panelUrl} onClick={leaveForPanel} className="flex min-h-12 shrink-0 items-center rounded-lg border border-gray-200 px-3 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-300">Back to panel</a>
                )}
            </header>
            )}

            {signedOut && (
                <p role="alert" className="shrink-0 bg-red-600 px-3 py-2 text-base font-semibold text-white">
                    You’re signed out. Your counts are safe on this phone. <a href={signInHref()} className="underline">Sign in again</a> and they upload.
                </p>
            )}

            <main className="min-h-0 flex-1 overflow-y-auto">
                {error && <p role="alert" className="m-3 rounded-xl bg-red-50 p-3 text-base font-semibold text-red-700">{error}</p>}

                {notice && view.name === 'home' && (
                    <p role="status" className="m-3 rounded-xl bg-green-600 p-3 text-base font-bold text-white">{notice}</p>
                )}

                {view.name === 'home' && (
                    <Home
                        home={home}
                        offline={offline}
                        local={local}
                        onOpenTask={(task) => { setNotice(''); setView(task.type === 'mapping' ? { name: 'aisles', task } : { name: 'count', task }); }}
                        onMapAll={() => setView({ name: 'aisles', task: null })}
                    />
                )}
                {view.name === 'aisles' && (
                    <AislePicker
                        task={view.task}
                        onBack={goHome}
                        onPick={(aisle) => setView({ name: 'map', aisle, task: view.task })}
                        onFinished={goHome}
                    />
                )}
                {view.name === 'count' && (
                    <CountTask
                        taskId={view.task.id}
                        syncer={syncer}
                        onBack={goHome}
                        onSubmitted={(message) => { setNotice(message ?? 'Count submitted. Thank you.'); goHome(); }}
                    />
                )}
                {view.name === 'map' && (
                    <MapAisle
                        aisle={view.aisle}
                        task={view.task}
                        onBack={() => setView({ name: 'aisles', task: view.task })}
                    />
                )}
            </main>
        </div>
    );
}

/**
 * The app's own service worker (A8), for /stock-take/ only: never the till's
 * pages, which keep theirs (/sw.js).
 *
 * Set up only once My Tasks is on screen and the phone is idle, at least 5
 * seconds in: on a first visit it downloads the app's files again to store
 * them, and that must not slow the first screen or a task's first download.
 */
let workerAsked = false;

export function registerWorker() {
    if (workerAsked || !('serviceWorker' in navigator) || !window.location.pathname.startsWith('/stock-take/')) return;
    workerAsked = true;

    const idle = window.requestIdleCallback ?? ((fn) => setTimeout(fn, 1));
    setTimeout(() => idle(() => {
        navigator.serviceWorker.register('/stock-take/sw.js', { scope: '/stock-take/' })
            .then(() => navigator.serviceWorker.ready)
            // Store this page now, so the next open needs no network.
            .then((registration) => registration.active?.postMessage({ type: 'store-shell', url: window.location.pathname }))
            .catch(() => {});
    }, { timeout: 10000 }), 5000);
}

const root = document.getElementById('stock-take-root');
if (root) createRoot(root).render(<App />);
