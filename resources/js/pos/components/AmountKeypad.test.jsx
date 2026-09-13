import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import AmountKeypad from './AmountKeypad';

afterEach(cleanup);

/** Driven the way the real screens drive it: value held by the parent. */
function Harness({ onSubmit }) {
    const [value, setValue] = useState('');

    return <AmountKeypad value={value} onChange={setValue} autoFocusLabel="Amount" onSubmit={onSubmit} />;
}

const field = () => screen.getByLabelText('Amount');

describe('typing an amount', () => {
    it('takes a figure straight off the keyboard', async () => {
        render(<Harness />);

        await userEvent.type(field(), '20000');

        expect(field().value).toBe('20,000');
    });

    it('is focused already, so a cashier can just start typing', () => {
        render(<Harness />);

        expect(document.activeElement).toBe(field());
    });

    it('takes kobo', async () => {
        render(<Harness />);

        await userEvent.type(field(), '1250.75');

        expect(field().value).toBe('1,250.75');
    });

    it('ignores anything that is not a number', async () => {
        render(<Harness />);

        await userEvent.type(field(), '1a2b3c');

        expect(field().value).toBe('123');
    });

    it('allows only one decimal point, and only two places after it', async () => {
        render(<Harness />);

        await userEvent.type(field(), '10.5.9');
        expect(field().value).toBe('10.59');

        await userEvent.clear(field());
        await userEvent.type(field(), '10.999');
        expect(field().value).toBe('10.99');
    });

    it('submits on Enter, so a keyboard till never needs the mouse', async () => {
        const onSubmit = vi.fn();
        render(<Harness onSubmit={onSubmit} />);

        await userEvent.type(field(), '5000{Enter}');

        expect(onSubmit).toHaveBeenCalled();
    });
});

describe('tapping an amount', () => {
    it('still builds the figure from the keys', async () => {
        render(<Harness />);

        for (const key of ['2', '0', '0', '0']) {
            await userEvent.click(screen.getByRole('button', { name: key }));
        }

        expect(field().value).toBe('2,000');
    });

    it('deletes the last digit', async () => {
        render(<Harness />);

        await userEvent.type(field(), '150');
        await userEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(field().value).toBe('15');
    });

    it('hands focus back so tapping and typing mix freely', async () => {
        render(<Harness />);

        await userEvent.click(screen.getByRole('button', { name: '7' }));
        await userEvent.keyboard('50');

        // The two ways of entering a number are the same box, not a choice
        // between them.
        expect(field().value).toBe('750');
    });

    it('keeps the keys out of the tab order', async () => {
        render(<Harness />);

        // Somebody tabbing through a form wants the next field, not twelve digits.
        expect(screen.getByRole('button', { name: '1' }).tabIndex).toBe(-1);
    });
});
