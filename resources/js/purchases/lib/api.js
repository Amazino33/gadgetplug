import axios from 'axios';

const CONFIG = window.PURCHASES_CONFIG ?? {};

/**
 * The Purchases endpoints. Session-authenticated, so the CSRF token from the
 * page rides on every write — there is no till token here.
 */
const api = axios.create({
    baseURL: CONFIG.apiBase ?? '/purchases',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    },
    withCredentials: true,
    timeout: 30000,
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

/** Every validation message, keyed by field, for showing beside lines. */
export function fieldErrors(error) {
    return error?.response?.data?.errors ?? {};
}

export default api;
