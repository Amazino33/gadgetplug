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
            } catch { /* stale data */ }
        }
    }, []);

    const handleLogin = (u, vid) => {
        setUser(u);
        setVendorId(vid);
    };

    const handleLogout = () => {
        localStorage.removeItem('pos_token');
        localStorage.removeItem('pos_user');
        localStorage.removeItem('pos_vendor_id');
        localStorage.removeItem('pos_session');
        setUser(null);
        setVendorId(null);
        // Deliberately NOT cleared from IndexedDB: the shift belongs to the
        // cashier and the trading day, not to this login. Someone who signs out
        // to let a colleague use the till comes back to the same open day.
        setShift(null);
    };

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
            shift={shift}
            onShiftClosed={setShift}
            onLogout={handleLogout}
        />
    );
}

const root = document.getElementById('pos-root');
if (root) createRoot(root).render(<App />);
