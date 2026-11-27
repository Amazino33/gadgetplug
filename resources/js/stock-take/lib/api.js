import axios from 'axios';

const CONFIG = window.STOCK_TAKE_CONFIG ?? {};

/**
 * The Stock Take endpoints. Session-authenticated, so the CSRF token from the
 * page rides on every write.
 */
const api = axios.create({
    baseURL: CONFIG.apiBase ?? '/stock-take',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    },
    withCredentials: true,
    timeout: 30000,
});

/**
 * The app's page can open from the phone's cache (release 2), with the CSRF
 * token of an earlier visit. A write refused for an old token (419) fetches a
 * fresh one and goes again, once.
 *
 * Signed out (401): the app is told ("stock-take:signed-out") so it can say
 * so. Counts stay on the phone and go up after signing in again.
 */
api.interceptors.response.use(undefined, async (error) => {
    const { response, config } = error ?? {};

    if (response?.status === 419 && config && !config.__retried) {
        try {
            const { data } = await api.get('/csrf');
            api.defaults.headers['X-CSRF-TOKEN'] = data.token;
            return api({ ...config, __retried: true, headers: { ...config.headers, 'X-CSRF-TOKEN': data.token } });
        } catch {
            // fall through: the original error
        }
    }

    if (response?.status === 401) {
        window.dispatchEvent(new CustomEvent('stock-take:signed-out'));
    }

    return Promise.reject(error);
});

/** The first thing worth telling a person about a failed request. */
export function errorMessage(error, fallback = 'Something went wrong. Try again.') {
    const body = error?.response?.data;

    if (body?.errors) {
        const first = Object.values(body.errors).flat()[0];
        if (first) return first;
    }

    if (body?.message) return body.message;
    if (error?.code === 'ECONNABORTED' || !error?.response) return 'Could not reach the server. Check the connection.';

    return fallback;
}

export default api;
