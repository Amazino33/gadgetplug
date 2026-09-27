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

// The server could not tell which branch this till is in — a login from before
// branches were chosen at sign-in, or the cashier has since been moved. Nothing
// the till does can put that right except signing in again, so it is announced
// once here rather than left for each screen to fail on in its own way.
// (A queued sale refused for its branch arrives inside a 200 sync result, not
// here, and is shown in the stuck-sales list instead.)
api.interceptors.response.use(undefined, (error) => {
    const body = error?.response?.data;

    if (body?.code === 'till_branch_unclear') {
        window.dispatchEvent(new CustomEvent('pos:branch-unclear', { detail: body.message }));
    }

    return Promise.reject(error);
});

export default api;
