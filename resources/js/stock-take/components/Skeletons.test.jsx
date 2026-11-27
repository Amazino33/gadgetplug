import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, act, cleanup, fireEvent } from '@testing-library/react';
import { TaskSkeleton } from './Skeletons';

describe('Loading a task', () => {
    afterEach(() => { cleanup(); vi.useRealTimers(); });

    it('says what it is doing, how much has arrived, and never just sits there', () => {
        vi.useFakeTimers();
        const retry = vi.fn();
        const { rerender } = render(<TaskSkeleton progress={{ stage: 'preparing' }} onRetry={retry} />);

        expect(screen.getByText('Getting the task ready…')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();

        act(() => { vi.advanceTimersByTime(7000); });
        expect(screen.getByText(/Still waiting for the server \(7 s\)/)).toBeTruthy();

        act(() => { vi.advanceTimersByTime(14000); });
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(retry).toHaveBeenCalledTimes(1);

        rerender(<TaskSkeleton progress={{ stage: 'downloading', loaded: 65536 }} onRetry={retry} />);
        expect(screen.getByText(/Downloading the products/)).toBeTruthy();
        expect(screen.getByText(/64 KB so far/)).toBeTruthy();
    });
});
