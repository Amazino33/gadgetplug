import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { db } from '../lib/db';
import { businessDate } from '../lib/shift';
import CashUpModal from './CashUpModal';

// Auto-cleanup is not on (vitest globals are off), so each render has to be
// torn down or the next test finds two of every button.
afterEach(cleanup);

const CASHIER = 7;
const shift = () => ({ id: 1, cashier_id: CASHIER, business_date: businessDate(), opening_float: 20000 });

beforeEach(async () => {
    await db.sales.clear();
    await db.refunds.clear();
});

const sell = (over = {}) => db.sales.add({
    cashier_id: CASHIER,
    status: 'completed',
    payment_method: 'cash',
    total: 10000,
    amount_tendered: 10000,
    change_given: 0,
    payments: null,
    synced: 1,
    completed_at: new Date().toISOString(),
    ...over,
});

const show = (props = {}) => render(
    <CashUpModal shift={shift()} onClose={() => {}} onComplete={() => {}} {...props} />,
);

/** Punch an amount into whichever keypad is on screen. */
const type = async (amount) => {
    for (const key of String(amount).split('')) {
        await userEvent.click(screen.getByRole('button', { name: key }));
    }
};

const next = async (name) => userEvent.click(screen.getByRole('button', { name }));

/** Both counts, through to the reveal. */
const countBoth = async (cash, terminal) => {
    await type(cash);
    await next(/next/i);
    await type(terminal);
    await next(/see how you did/i);
};

describe('counting blind', () => {
    it('asks for the drawer first', () => {
        show();

        expect(screen.getByText(/how much cash have you counted/i)).toBeTruthy();
    });

    it('reveals no expected figure while the drawer is being counted', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        await type('50000');

        // A count taken with the answer on screen is not a count.
        expect(screen.queryByText(/should be/i)).toBeNull();
        expect(screen.queryByText(/100,000/)).toBeNull();
        expect(screen.queryByText(/short/i)).toBeNull();
    });

    it('reveals nothing about the drawer while the terminal is being read', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        await type('50000');
        await next(/next/i);

        // Showing the cash result here would let a cashier who is short quietly
        // adjust what they claim the machine says.
        expect(screen.getByText(/moniepoint machine/i)).toBeTruthy();
        expect(screen.queryByText(/short/i)).toBeNull();
        expect(screen.queryByText(/should be/i)).toBeNull();
    });

    it('will not move on until an amount is entered', () => {
        show();

        expect(screen.getByRole('button', { name: /next/i }).disabled).toBe(true);
    });
});

describe('the reveal', () => {
    it('shows a shortage as short, in words', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        // Float 20,000 + 80,000 sales = 100,000 expected; drawer holds 97,000.
        await countBoth('97000', '0');

        expect(await screen.findByText('Short')).toBeTruthy();
        expect(screen.getByText('₦3,000.00')).toBeTruthy();
    });

    it('shows the working, float included', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        await countBoth('97000', '0');

        await screen.findByText('Short');
        expect(screen.getByText('Opening float')).toBeTruthy();
        expect(screen.getByText('Cash sales')).toBeTruthy();
        // One per leg — both are shown their own working.
        expect(screen.getAllByText(/should be/i)).toHaveLength(2);
    });

    it('says balanced when it balances', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        await countBoth('100000', '0');

        // Both legs: the drawer against its expected figure, and a terminal
        // that took nothing and holds nothing.
        await waitFor(() => expect(screen.getAllByText('Balanced')).toHaveLength(2));
    });

    it('reconciles the terminal on its own', async () => {
        await sell({ payment_method: 'card', total: 50000, amount_tendered: 0 });
        show();

        await countBoth('20000', '48000');

        await screen.findByText('Moniepoint terminal');
        // Cash balances on the float alone; the terminal is 2,000 short.
        expect(screen.getByText('Balanced')).toBeTruthy();
        expect(screen.getByText('Short')).toBeTruthy();
    });

    it('names the wrong-tender pattern rather than leaving it to be guessed', async () => {
        await sell({ total: 15000, amount_tendered: 15000 });
        show();

        // Cash short 15,000, terminal over 15,000 — one sale on the wrong button.
        await countBoth('20000', '15000');

        expect(await screen.findByText(/cancel each other out/i)).toBeTruthy();
    });

    it('explains credit, so the figures do not just look wrong', async () => {
        await sell({ total: 60000, amount_tendered: 60000 });
        await sell({ payment_method: 'debt', total: 40000, amount_tendered: 0 });
        show();

        await countBoth('80000', '0');

        expect(await screen.findByText(/went out on credit/i)).toBeTruthy();
    });

    it('says plainly that the figure is not the final one', async () => {
        await sell();
        show();

        await countBoth('30000', '0');

        expect(await screen.findByText(/your manager sees the final figure/i)).toBeTruthy();
    });

    it('warns when sales have not uploaded, because the server will disagree', async () => {
        await sell({ synced: 0 });
        show();

        await countBoth('30000', '0');

        // A sync problem, not a missing-money problem — the cashier has to be
        // told which they are looking at.
        expect(await screen.findByText(/not uploaded yet/i)).toBeTruthy();
    });

    it('leads on to the note rather than finishing behind the cashier back', async () => {
        const onComplete = vi.fn();
        await sell({ total: 80000, amount_tendered: 80000 });
        show({ onComplete });

        await countBoth('97000', '5000');
        await screen.findByText('Short');
        await userEvent.click(screen.getByRole('button', { name: /continue/i }));

        // Seeing the figures is not agreeing to them. Nothing is submitted
        // until the cashier has had the chance to say what happened.
        expect(await screen.findByText(/what happened/i)).toBeTruthy();
        expect(onComplete).not.toHaveBeenCalled();
    });
});
