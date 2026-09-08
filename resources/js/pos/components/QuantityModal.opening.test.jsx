import { describe, expect, it, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import QuantityModal from './QuantityModal';

// Reproduces the shape of the real till: an Enter in the search box opens the
// quantity box. The question this answers is whether that SAME Enter is then
// caught by the quantity box's own Enter handler — confirming a quantity of 1
// and closing again before the cashier sees it, which looks exactly like the
// product being added straight to the list with no quantity asked for.

function Till({ onConfirm }) {
    const [open, setOpen] = useState(false);

    return (
        <div>
            <input
                aria-label="Search"
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        setOpen(true);
                    }
                }}
            />
            {open && (
                <QuantityModal
                    item={{ id: 1, name: 'Samsung 25W Charger', qty: 1 }}
                    onConfirm={(qty) => { onConfirm(qty); setOpen(false); }}
                    onClose={() => setOpen(false)}
                />
            )}
        </div>
    );
}

afterEach(cleanup);

describe('opening the quantity box from the search box', () => {
    it('stays open — the Enter that opened it must not also confirm it', async () => {
        const onConfirm = vi.fn();
        render(<Till onConfirm={onConfirm} />);

        await userEvent.click(screen.getByRole('textbox', { name: /search/i }));
        await userEvent.keyboard('{Enter}');

        // The cashier has to be asked for a quantity, not have 1 assumed.
        expect(await screen.findByRole('textbox', { name: /quantity/i })).toBeDefined();
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('hands the keyboard to the quantity box, so the next digit goes there', async () => {
        const onConfirm = vi.fn();
        render(<Till onConfirm={onConfirm} />);

        await userEvent.click(screen.getByRole('textbox', { name: /search/i }));
        await userEvent.keyboard('{Enter}');

        await waitFor(() =>
            expect(document.activeElement).toBe(screen.getByRole('textbox', { name: /quantity/i }))
        );

        await userEvent.keyboard('4{Enter}');

        expect(onConfirm).toHaveBeenCalledWith(4);
    });
});
