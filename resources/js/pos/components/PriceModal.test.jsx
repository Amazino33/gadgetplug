import { describe, expect, it, vi, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import PriceModal from './PriceModal';

const watch = { id: 7, name: 'Smart Watch Storm Ultra', price: 24000, listPrice: 24000, min_price: 20000 };

const priceBox = () => screen.getByRole('spinbutton');
const typePrice = (value) => fireEvent.change(priceBox(), { target: { value } });

afterEach(cleanup);

describe('PriceModal', () => {
    it('takes an ordinary negotiated price at once', async () => {
        const onConfirm = vi.fn();
        render(<PriceModal item={watch} onConfirm={onConfirm} onClose={vi.fn()} />);

        typePrice('22000');
        await userEvent.click(screen.getByRole('button', { name: /set/i }));

        expect(onConfirm).toHaveBeenCalledWith(22000);
    });

    it('asks once before believing a price far above normal', async () => {
        const onConfirm = vi.fn();
        render(<PriceModal item={watch} onConfirm={onConfirm} onClose={vi.fn()} />);

        // Four extra zeros.
        typePrice('240000000');
        await userEvent.click(screen.getByRole('button', { name: /set/i }));

        expect(onConfirm).not.toHaveBeenCalled();
        expect(screen.getByText(/more than double the normal price/i)).toBeTruthy();

        await userEvent.click(screen.getByRole('button', { name: /set/i }));

        expect(onConfirm).toHaveBeenCalledWith(240000000);
    });

    it('asks again if the price is changed after being questioned', async () => {
        const onConfirm = vi.fn();
        render(<PriceModal item={watch} onConfirm={onConfirm} onClose={vi.fn()} />);

        typePrice('240000000');
        await userEvent.click(screen.getByRole('button', { name: /set/i }));
        typePrice('2400000');
        await userEvent.click(screen.getByRole('button', { name: /set/i }));

        expect(onConfirm).not.toHaveBeenCalled();
    });
});
