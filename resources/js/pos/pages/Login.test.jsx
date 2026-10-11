import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import api from '../lib/api';
import Login from './Login';

vi.mock('../lib/api', () => ({
    default: { post: vi.fn(), get: vi.fn().mockResolvedValue({ data: [] }) },
}));

afterEach(cleanup);

beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    vi.clearAllMocks();
    localStorage.setItem('pos_vendor_id', '2');
});

const refused = (status, data) => Object.assign(new Error('refused'), { response: { status, data } });

const typePin = (pin) => fireEvent.change(screen.getByLabelText('PIN'), { target: { value: pin } });

const signedIn = {
    data: {
        token: 't', user: { id: 40, name: 'Ekene' },
        store: { id: 9, name: 'Zeelink Phones' }, vendor: {},
    },
};

describe('signing in to a branch', () => {
    it('asks someone who works in several branches which one they are in', async () => {
        api.post
            .mockRejectedValueOnce(refused(422, {
                code: 'choose_store',
                stores: [{ id: 2, name: 'Zeelink Accesories' }, { id: 9, name: 'Zeelink Phones' }],
            }))
            .mockResolvedValueOnce(signedIn);

        const onLogin = vi.fn();
        render(<Login onLogin={onLogin} />);
        typePin('1234');

        await userEvent.click(await screen.findByRole('button', { name: 'Zeelink Phones' }));

        await waitFor(() => expect(onLogin).toHaveBeenCalledWith(signedIn.data.user, 2, { id: 9, name: 'Zeelink Phones' }));
        expect(api.post).toHaveBeenLastCalledWith('/auth/login', { vendor_id: 2, pin: '1234', store_id: 9 });
        expect(JSON.parse(localStorage.getItem('pos_store'))).toEqual({ id: 9, name: 'Zeelink Phones' });
    });

    it('tells someone with no branch why, instead of blaming their PIN', async () => {
        api.post.mockRejectedValueOnce(refused(422, {
            code: 'no_store', message: 'You are not assigned to any branch of this business.',
        }));

        render(<Login onLogin={() => {}} />);
        typePin('1234');

        expect(await screen.findByText('You are not assigned to any branch of this business.')).toBeTruthy();
        expect(screen.queryByText(/invalid pin/i)).toBeNull();
    });

    it('still says a wrong PIN is a wrong PIN', async () => {
        api.post.mockRejectedValueOnce(refused(401, { message: 'Invalid PIN.' }));

        render(<Login onLogin={() => {}} />);
        typePin('9999');

        expect(await screen.findByText('Invalid PIN. Try again.')).toBeTruthy();
    });

    it('explains why the till was signed out', () => {
        sessionStorage.setItem('pos_login_notice', 'Sign out of the till and sign in again, choosing the branch you are in.');

        render(<Login onLogin={() => {}} />);

        expect(screen.getByText(/choosing the branch you are in/)).toBeTruthy();
    });
});

describe('a right PIN always signs in', () => {
    it('even when the product download fails, instead of calling the PIN invalid', async () => {
        api.post.mockResolvedValueOnce(signedIn);
        // The catalogue times out — easy with thousands of products.
        api.get.mockRejectedValueOnce(Object.assign(new Error('timeout of 30000ms exceeded'), { code: 'ECONNABORTED' }));

        const onLogin = vi.fn();
        render(<Login onLogin={onLogin} />);
        typePin('1234');

        await waitFor(() => expect(onLogin).toHaveBeenCalledWith(signedIn.data.user, 2, signedIn.data.store));
        expect(screen.queryByText(/invalid pin/i)).toBeNull();
        expect(localStorage.getItem('pos_token')).toBe('t');
    });

    it('blames the connection, not the PIN, when the server cannot be reached', async () => {
        api.post.mockRejectedValueOnce(new Error('Network Error'));

        render(<Login onLogin={() => {}} />);
        typePin('1234');

        expect(await screen.findByText(/could not reach the server/i)).toBeTruthy();
        expect(screen.queryByText(/invalid pin/i)).toBeNull();
    });

    it('sends one sign-in, not two, when Enter follows the fourth digit', async () => {
        let release;
        api.post.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(signedIn); }));

        const onLogin = vi.fn();
        render(<Login onLogin={onLogin} />);
        typePin('1234');
        fireEvent.keyDown(screen.getByLabelText('PIN'), { key: 'Enter' });

        await waitFor(() => expect(release).toBeDefined());
        release();

        await waitFor(() => expect(onLogin).toHaveBeenCalledTimes(1));
        expect(api.post).toHaveBeenCalledTimes(1);
    });
});

describe('the till opens as soon as the PIN is right', () => {
    it('does not wait for any download before letting the cashier in', async () => {
        api.post.mockResolvedValueOnce(signedIn);
        // Every background request hangs — the till must open regardless.
        api.get.mockImplementation(() => new Promise(() => {}));

        const onLogin = vi.fn();
        render(<Login onLogin={onLogin} />);
        typePin('1234');

        await waitFor(() => expect(onLogin).toHaveBeenCalledTimes(1));
        expect(api.get).not.toHaveBeenCalledWith('/products', expect.anything());
    });

    it('keeps the saved products for the same branch, and clears them for a different one', async () => {
        const { db } = await import('../lib/db');
        await db.products.clear();
        await db.products.put({ id: 1, name: 'Cached', barcode: '111', sku: 'C1' });

        api.get.mockResolvedValue({ data: [] });

        // Same shop and branch as last time: the device's copy stays.
        localStorage.setItem('pos_catalogue_owner', '2:9');
        api.post.mockResolvedValueOnce(signedIn);
        const onLogin = vi.fn();
        const { unmount } = render(<Login onLogin={onLogin} />);
        typePin('1234');
        await waitFor(() => expect(onLogin).toHaveBeenCalled());
        expect(await db.products.count()).toBe(1);
        unmount();

        // Another branch on this device: its products must not linger.
        localStorage.setItem('pos_catalogue_owner', '2:4');
        api.post.mockResolvedValueOnce(signedIn);
        const onLogin2 = vi.fn();
        render(<Login onLogin={onLogin2} />);
        typePin('1234');
        await waitFor(() => expect(onLogin2).toHaveBeenCalled());
        expect(await db.products.count()).toBe(0);
        expect(localStorage.getItem('pos_catalogue_owner')).toBe('2:9');
    });
});
