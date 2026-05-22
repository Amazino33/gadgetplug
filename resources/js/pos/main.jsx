import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import Login from './pages/Login';
import POS from './pages/POS';
import '../../css/pos.css';

function App() {
    const [user, setUser]       = useState(null);
    const [vendorId, setVendorId] = useState(null);

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
        localStorage.removeItem('pos_session');
        setUser(null);
        setVendorId(null);
    };

    if (!user) return <Login onLogin={handleLogin} />;

    return <POS user={user} vendorId={vendorId} onLogout={handleLogout} />;
}

const root = document.getElementById('pos-root');
if (root) createRoot(root).render(<App />);
