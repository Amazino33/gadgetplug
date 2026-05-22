import { useEffect } from 'react';

/**
 * Register global keyboard shortcuts.
 * map: { 'F10': handler, 'Escape': handler, ... }
 * Skips if focus is inside an input/textarea/select (unless allowInInput is true).
 */
export function useKeyboard(map, deps = [], { allowInInput = false } = {}) {
    useEffect(() => {
        const handler = (e) => {
            if (!allowInInput) {
                const tag = document.activeElement?.tagName;
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
            }

            const action = map[e.key] ?? map[e.code];
            if (action) {
                e.preventDefault();
                action(e);
            }
        };

        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, deps); // eslint-disable-line react-hooks/exhaustive-deps
}
