import axios from 'axios';

const api = axios.create({
    baseURL: '/api/pos',
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
    // Without this, a stalled connection hangs forever rather than failing —
    // the till has no way to tell "still loading" from "never coming back".
    // Set generously here because one caller (the offline sync batch) can
    // legitimately take a while — a whole shift's unsynced sales go up in
    // one request when connectivity returns. A latency-sensitive caller like
    // product search overrides this with its own, much shorter timeout
    // per-request rather than everyone inheriting a number sized for the
    // slowest thing on the till.
    timeout: 30000,
});

// Attach Sanctum token from localStorage on every request
api.interceptors.request.use((config) => {
    const token = localStorage.getItem('pos_token');
    if (token) config.headers['Authorization'] = `Bearer ${token}`;
    return config;
});

export default api;
