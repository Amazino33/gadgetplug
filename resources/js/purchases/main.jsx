import { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import Terminal from './components/Terminal';
import History from './components/History';
import '../../css/purchases.css';

const CONFIG = window.PURCHASES_CONFIG ?? {};
const TABS = [
    { id: 'terminal', label: 'Purchase Terminal' },
    { id: 'history', label: 'Purchase History' },
];

/**
 * Purchases: the till's layout turned round to face the other way. Orange
 * where Sales Point is green, so nobody is ever unsure which way stock is
 * about to move.
 */
function App() {
    const [tab, setTab] = useState(() => (window.location.hash === '#history' ? 'history' : 'terminal'));
    const [resume, setResume] = useState(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const [isDark, setIsDark] = useState(() => document.documentElement.classList.contains('dark'));

    useEffect(() => {
        try { window.history.replaceState(null, '', tab === 'history' ? '#history' : window.location.pathname); } catch { /* sandboxed */ }
    }, [tab]);

    const toggleDark = () => {
        const next = !isDark;
        setIsDark(next);
        document.documentElement.classList.toggle('dark', next);
        try { localStorage.setItem('darkMode', String(next)); } catch { /* private mode */ }
    };

    const onResumed = useCallback(() => setResume(null), []);
    const onSaved = useCallback(() => setRefreshKey((k) => k + 1), []);

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-[#F9FAFB] dark:bg-gray-950" style={{ fontFamily: 'Inter, sans-serif' }}>
            {/* Full-width banner: no doubt which terminal this is. */}
            <div className="shrink-0 bg-orange-600 px-3 py-1.5 text-center text-[11px] font-extrabold uppercase tracking-[0.12em] text-white sm:py-2 sm:text-sm sm:tracking-[0.18em]" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                Purchase Terminal — Receiving Stock
            </div>

            {/* On a phone: name and the two small buttons on one line, the tabs
                full width beneath — big enough to hit with a thumb. */}
            <header className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 px-3 py-2 sm:px-4">
                <div className="min-w-0 flex-1 md:flex-none">
                    <div className="truncate text-sm font-bold text-gray-800 dark:text-gray-100">{CONFIG.vendorName}</div>
                    <div className="truncate text-[11px] text-gray-400">{CONFIG.storeName} · {CONFIG.userName}</div>
                </div>

                <nav role="tablist" className="order-last flex w-full rounded-xl bg-gray-100 p-1 dark:bg-gray-800 md:order-none md:ml-4 md:w-auto">
                    {TABS.map((t) => (
                        <button
                            key={t.id}
                            role="tab"
                            type="button"
                            aria-selected={tab === t.id}
                            onClick={() => setTab(t.id)}
                            className={`flex-1 rounded-lg px-4 py-2 text-xs font-bold transition-colors md:flex-none md:py-1.5 ${tab === t.id ? 'bg-orange-600 text-white shadow-sm' : 'text-gray-600 dark:text-gray-300 hover:text-orange-700'}`}
                        >
                            {t.label}
                        </button>
                    ))}
                </nav>

                <div className="ml-auto flex shrink-0 items-center gap-3">
                    <button type="button" onClick={toggleDark} aria-label="Toggle dark mode" className="flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
                        {isDark ? '☀' : '☾'}
                    </button>
                    {CONFIG.panelUrl && (
                        <a href={CONFIG.panelUrl} className="text-xs font-semibold text-gray-500 hover:text-orange-700">← <span className="max-sm:hidden">Back to </span>panel</a>
                    )}
                </div>
            </header>

            <Terminal
                active={tab === 'terminal'}
                resume={resume}
                onResumed={onResumed}
                onSaved={onSaved}
                refreshKey={refreshKey}
            />
            <History
                active={tab === 'history'}
                refreshKey={refreshKey}
                onContinue={(purchase) => { setResume(purchase); setTab('terminal'); }}
                onPaid={onSaved}
            />
        </div>
    );
}

const root = document.getElementById('purchases-root');
if (root) createRoot(root).render(<App />);
