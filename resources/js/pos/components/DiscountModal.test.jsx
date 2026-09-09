import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import DiscountModal from './DiscountModal';

// A cart discount used to be accepted by the till whatever it came to, and only
// refused by the server. Offline that refusal lands after the customer has left,
// as a sale that can never sync and goods already off the shelf. The floor is
// now checked here, while the cashier can still do something about it.

// Auto-cleanup is not on (vitest globals are off), so each render has to be
// torn down or the next test finds two of every field.
afterEach(cleanup);

const setup = (props = {}) => {
    const onApply = vi.fn();

    render(
        <DiscountModal
            vendorId={1}
            subtotal={1000}
            floorTotal={800}
            current={{ amount: '', type: 'fixed', approvedBy: null }}
            onApply={onApply}
            onClose={vi.fn()}
            {...props}
        />,
    );

    return { onApply, user: userEvent.setup() };
};

const amountBox  = () => screen.getByRole('spinbutton');
const applyButton = () => screen.getByRole('button', { name: /apply discount/i });

describe('DiscountModal floor guard', () => {
    it('takes a discount that stops above the floor', async () => {
        const { user } = setup();

        await user.type(amountBox(), '150');

        expect(screen.queryByText(/discount too large/i)).toBeNull();
    });

    it('refuses one that goes under, and says what the limit is', async () => {
        const { user } = setup();

        await user.type(amountBox(), '300');

        expect(screen.queryByText(/discount too large/i)).not.toBeNull();
        expect(screen.queryByText(/cannot go below/i)).not.toBeNull();
        expect(applyButton().disabled).toBe(true);
    });

    it('will not apply a breaching discount even with a manager PIN typed', async () => {
        const { user, onApply } = setup();

        await user.type(amountBox(), '300');
        await user.type(screen.getByPlaceholderText(/manager pin/i), '1234');

        expect(applyButton().disabled).toBe(true);
        expect(onApply).not.toHaveBeenCalled();
    });

    it('accepts a discount landing exactly on the floor', async () => {
        const { user } = setup();

        // 1,000 down to exactly 800. Refusing this would be the arithmetic
        // being fussy rather than the rule being enforced.
        await user.type(amountBox(), '200');

        expect(screen.queryByText(/discount too large/i)).toBeNull();
    });

    it('measures a percentage against the floor too, not just a fixed amount', async () => {
        const { user } = setup();

        await user.click(screen.getByRole('button', { name: /percentage/i }));
        await user.type(amountBox(), '30'); // 30% of 1,000 leaves 700, under 800

        expect(screen.queryByText(/discount too large/i)).not.toBeNull();
    });

    it('stays out of the way when no floor was supplied', async () => {
        // An older cached catalogue may carry no minimum. The till should not
        // invent a limit it was never told about.
        const { user } = setup({ floorTotal: 0 });

        await user.type(amountBox(), '900');

        expect(screen.queryByText(/discount too large/i)).toBeNull();
    });
});
