import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import Login from './pages/Login';
import POS from './pages/POS';
import StartShift from './pages/StartShift';
import ShiftClosed from './pages/ShiftClosed';
import { STATUS_OPEN } from './lib/shift';
import { useLiveShift } from './hooks/useLiveShift';
import '../../css/pos.css';

function App() {
    const [user, setUser]       = useState(null);
    const [vendorId, setVendorId] = useState(null);
    // The branch this till signed in to — stamped on every sale it rings.
    const [store, setStore]       = useState(null);
    // The day this cashier is trading. Selling is gated behind it: a shift that
    // was never opened has no counted float, and a closing count measured
    // against an unknown opening proves nothing.
    //
    // Live, because the background sync rewrites this row: a day finished
    // offline shows the till's own figures and must quietly become the server's
    // once they arrive, without anyone reloading the app.
    const { shift, loading: checkingShift, setShift } = useLiveShift(user?.id);

    // Restore session from localStorage on page reload
    useEffect(() => {
        const token  = localStorage.getItem('pos_token');
        const stored = localStorage.getItem('pos_user');
        const vid    = localStorage.getItem('pos_vendor_id');
        if (token && stored && vid) {
            try {
                setUser(JSON.parse(stored));
                setVendorId(Number(vid));
                // Absent on a till signed in before branches were recorded.
                // Its sales then carry no branch and the server uses the till
                // login's own — or refuses, and this till is signed out below.
                setStore(JSON.parse(localStorage.getItem('pos_store') ?? 'null'));
            } catch { /* stale data */ }
        }
    }, []);

    const handleLogin = (u, vid, s) => {
        setUser(u);
        setVendorId(vid);
        setStore(s ?? null);
    };

    const handleLogout = () => {
        localStorage.removeItem('pos_token');
        localStorage.removeItem('pos_user');
        localStorage.removeItem('pos_vendor_id');
        localStorage.removeItem('pos_store');
        localStorage.removeItem('pos_session');
        localStorage.removeItem('pos_cart');
        localStorage.removeItem('pos_customer');
        localStorage.removeItem('pos_cartDiscount');
        localStorage.removeItem('pos_recoveredAt');
        setUser(null);
        setVendorId(null);
        setStore(null);
        // Deliberately NOT cleared from IndexedDB: the shift belongs to the
        // cashier and the trading day, not to this login. Someone who signs out
        // to let a colleague use the till comes back to the same open day.
        setShift(null);
    };

    // The server could not tell which branch this till is in (see lib/api).
    // Signing in again is the only fix, so the till does it for the cashier
    // and says why on the sign-in screen. Queued sales are untouched — they
    // live in IndexedDB and go up after the next sign-in.
    useEffect(() => {
        const onBranchUnclear = (e) => {
            try { sessionStorage.setItem('pos_login_notice', e.detail ?? ''); } catch { /* private mode */ }
            handleLogout();
        };

        window.addEventListener('pos:branch-unclear', onBranchUnclear);

        return () => window.removeEventListener('pos:branch-unclear', onBranchUnclear);
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    if (!user) return <Login onLogin={handleLogin} />;

    // Held rather than flashed: rendering the float screen for a frame before
    // IndexedDB answers would ask a cashier mid-shift to open a second one.
    if (checkingShift) {
        return (
            <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-950">
                <p className="text-sm font-medium text-gray-400">Opening the till…</p>
            </div>
        );
    }

    if (!shift) {
        return (
            <StartShift
                user={user}
                vendorId={vendorId}
                onStarted={setShift}
                onLogout={handleLogout}
            />
        );
    }

    // Counted and submitted. Selling stops here, and that is the point rather
    // than a side effect: one open and one close per day is what makes the count
    // mean anything, and a till that kept selling afterwards would have counted
    // a drawer that was still moving.
    if (shift.status !== STATUS_OPEN) {
        return <ShiftClosed shift={shift} user={user} onLogout={handleLogout} />;
    }

    return (
        <POS
            user={user}
            vendorId={vendorId}
            storeId={store?.id ?? null}
            shift={shift}
            onShiftClosed={setShift}
            onLogout={handleLogout}
        />
    );
}

const root = document.getElementById('pos-root');
if (root) createRoot(root).render(<App />);
