import { useRef } from 'react';

/**
 * A panel that slides up from the bottom over the counting screen (B6).
 * Swipe its handle down, tap ✕ or tap the dimmed area to close.
 */
export default function Sheet({ title, onClose, children, footer = null }) {
    const start = useRef(null);

    const down = (e) => { start.current = e.clientY; };
    const up = (e) => {
        if (start.current !== null && e.clientY - start.current > 60) onClose();
        start.current = null;
    };

    return (
        <div className="fixed inset-0 z-40 flex flex-col justify-end">
            <button type="button" aria-label="Close" onClick={onClose} className="min-h-0 flex-1 bg-black/40" />
            <section role="dialog" aria-label={title} className="flex max-h-[85%] min-h-[50%] flex-col rounded-t-3xl bg-white shadow-2xl dark:bg-gray-900">
                <div onPointerDown={down} onPointerUp={up} className="shrink-0 touch-none px-3 pb-1 pt-2">
                    <div className="mx-auto mb-1.5 h-1.5 w-12 rounded-full bg-gray-300 dark:bg-gray-700" />
                    <div className="flex items-center gap-2">
                        <h2 className="min-w-0 flex-1 truncate text-lg font-extrabold text-gray-900 dark:text-white">{title}</h2>
                        <button type="button" onClick={onClose} aria-label={`Close ${title}`} className="flex h-12 w-12 items-center justify-center rounded-xl text-2xl text-gray-500">✕</button>
                    </div>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto px-3 pb-4">{children}</div>
                {footer && <div className="shrink-0 border-t border-gray-200 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-gray-800">{footer}</div>}
            </section>
        </div>
    );
}
