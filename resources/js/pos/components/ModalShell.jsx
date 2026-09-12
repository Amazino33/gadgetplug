/**
 * A till modal that fits on the screen it is actually used on.
 *
 * Three bands: a header that stays, a middle that scrolls, and a footer that
 * stays. The button a cashier has to press is therefore always on screen — it
 * was falling below the fold on a 768-pixel laptop, which is most of the tills
 * this runs on, so finishing a task meant scrolling to find the way to finish it.
 *
 * dvh rather than vh: on a phone, vh is the viewport with the address bar
 * pretended away, so a modal sized in it is taller than the screen from the
 * moment it opens.
 *
 * min-h-0 on the scrolling band is not decoration. A flex child defaults to
 * min-height:auto, which refuses to shrink below its content — without it the
 * middle pushes the footer off the bottom instead of scrolling, which is the
 * exact bug this exists to fix.
 */
export default function ModalShell({
    title,
    onClose,
    children,
    footer = null,
    maxWidth = 'max-w-md',
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 backdrop-blur-sm">
            <div className={`flex max-h-[92dvh] w-full ${maxWidth} flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-900`}>
                <div className="flex shrink-0 items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                    <h2 className="text-sm font-bold text-gray-800 dark:text-gray-100">{title}</h2>
                    <button
                        onClick={onClose}
                        aria-label="Close"
                        className="-mr-1 flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
                    >
                        ✕
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    {children}
                </div>

                {footer && (
                    <div className="shrink-0 border-t border-gray-100 bg-white px-5 py-3 dark:border-gray-800 dark:bg-gray-900">
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}
