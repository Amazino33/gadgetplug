import { describe, expect, it, vi, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import QuantityModal from './QuantityModal';

// The counter loop this has to support, without a mouse anywhere in it:
// type a product, pick it, type how many, press Enter, type the next
// product. So this box has to open already holding the keyboard with its
// current value selected — otherwise the typed quantity lands on the end of
// the "1" (11, not 1) or nowhere at all.

const item = { id: 1, name: 'Samsung 25W Charger', qty: 1 };

const quantityBox = () => screen.getByRole('textbox', { name: /quantity/i });

afterEach(cleanup);

describe('QuantityModal', () => {
    it('opens holding the keyboard, with the current quantity selected', () => {
        render(<QuantityModal item={item} onConfirm={vi.fn()} onClose={vi.fn()} />);

        const box = quantityBox();

        expect(document.activeElement).toBe(box);
        // Selected, not just present — typing has to replace it rather than
        // append to it.
        expect(box.selectionStart).toBe(0);
        expect(box.selectionEnd).toBe(String(item.qty).length);
    });

    it('replaces the quantity as soon as a digit is typed', async () => {
        render(<QuantityModal item={item} onConfirm={vi.fn()} onClose={vi.fn()} />);

        await userEvent.keyboard('3');

        // Replaced, not appended — "3", never "13".
        expect(quantityBox().value).toBe('3');
    });

    it('confirms what was typed on Enter, with no click', async () => {
        const onConfirm = vi.fn();
        render(<QuantityModal item={item} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('12{Enter}');

        expect(onConfirm).toHaveBeenCalledWith(12);
    });

    it('keeps the quantity it opened with when Enter is pressed on an untouched box', async () => {
        const onConfirm = vi.fn();
        render(<QuantityModal item={{ ...item, qty: 4 }} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('{Enter}');

        expect(onConfirm).toHaveBeenCalledWith(4);
    });

    it('treats an emptied box as leaving the quantity alone, rather than doing nothing at all', async () => {
        const onConfirm = vi.fn();
        render(<QuantityModal item={{ ...item, qty: 2 }} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('{Backspace}{Enter}');

        expect(onConfirm).toHaveBeenCalledWith(2);
    });

    it('passes zero through, which is how a line is removed', async () => {
        const onConfirm = vi.fn();
        render(<QuantityModal item={item} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('0{Enter}');

        expect(onConfirm).toHaveBeenCalledWith(0);
    });

    it('refuses anything that is not a digit', async () => {
        render(<QuantityModal item={item} onConfirm={vi.fn()} onClose={vi.fn()} />);

        // A number input would have accepted these and reported an empty value.
        await userEvent.keyboard('e-+2');

        expect(quantityBox().value).toBe('2');
    });

    it('closes on Escape without confirming anything', async () => {
        const onConfirm = vi.fn();
        const onClose = vi.fn();
        render(<QuantityModal item={item} onConfirm={onConfirm} onClose={onClose} />);

        await userEvent.keyboard('{Escape}');

        expect(onClose).toHaveBeenCalled();
        expect(onConfirm).not.toHaveBeenCalled();
    });
});
