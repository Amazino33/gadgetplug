import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import QuantityModal from './QuantityModal';

// The quantity box with the vendor's half-quantities switch on and off. Off
// is every supermarket: digits only, exactly as before, so a half can never
// even be typed. On, ½ steps go in and anything else is explained.

const item = { id: 1, name: 'Hero Lager', qty: 1 };
const box  = () => screen.getByRole('textbox', { name: /quantity/i });

const halves = (on) => localStorage.setItem('pos_vendor_settings', JSON.stringify({ allow_half_quantities: on }));

afterEach(() => {
    cleanup();
    localStorage.removeItem('pos_vendor_settings');
});

describe('QuantityModal with half quantities off', () => {
    it('cannot be given a half: the point never reaches the box', async () => {
        halves(false);
        const onConfirm = vi.fn();
        render(<QuantityModal item={item} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('1.5{Enter}');

        // Digits only, as it has always been: "1.5" types as 15.
        expect(box().value).toBe('15');
        expect(onConfirm).toHaveBeenCalledWith(15);
    });
});

describe('QuantityModal with half quantities on', () => {
    it('takes 1.5', async () => {
        halves(true);
        const onConfirm = vi.fn();
        render(<QuantityModal item={item} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('1.5{Enter}');

        expect(onConfirm).toHaveBeenCalledWith(1.5);
    });

    it('takes ½ on its own', async () => {
        halves(true);
        const onConfirm = vi.fn();
        render(<QuantityModal item={item} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('0.5{Enter}');

        expect(onConfirm).toHaveBeenCalledWith(0.5);
    });

    it('refuses 1.3 and says why, instead of adding it', async () => {
        halves(true);
        const onConfirm = vi.fn();
        render(<QuantityModal item={item} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('1.3{Enter}');

        expect(onConfirm).not.toHaveBeenCalled();
        expect(screen.getByText(/go in halves/i)).toBeDefined();
    });

    it('still refuses a scanned barcode', async () => {
        halves(true);
        const onConfirm = vi.fn();
        render(<QuantityModal item={item} onConfirm={onConfirm} onClose={vi.fn()} />);

        await userEvent.keyboard('6001234567890{Enter}');

        expect(onConfirm).not.toHaveBeenCalled();
        expect(screen.getByText(/looks like a barcode/i)).toBeDefined();
    });
});
