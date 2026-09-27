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
