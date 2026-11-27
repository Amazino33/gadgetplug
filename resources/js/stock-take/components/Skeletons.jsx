import { useEffect, useState } from 'react';

/**
 * What shows while something loads: the shape of the screen to come, gently
 * pulsing, so the counter sees at once that it is on its way and nothing
 * jumps when it arrives.
 */

const bone = 'animate-pulse rounded-xl bg-gray-200/80 dark:bg-gray-800';

const STAGES = {
    preparing: 'Getting the task ready…',
    downloading: 'Downloading the products…',
    saving: 'Saving them on this phone, for counting offline…',
};

const kb = (bytes) => `${Math.max(1, Math.round(bytes / 1024)).toLocaleString()} KB`;

/** The counting screen, before its task has arrived: header, camera, card, and how far the download is. */
export function TaskSkeleton({ progress = null, onRetry = null }) {
    const stage = progress?.stage ?? 'preparing';
    // Never just sits there: after a few seconds it says it is still waiting, then offers to try again.
    const [seconds, setSeconds] = useState(0);
    useEffect(() => {
        const timer = setInterval(() => setSeconds((s) => s + 1), 1000);
        return () => clearInterval(timer);
    }, []);
    const slow = seconds >= 6 && stage !== 'saving';
    const stuck = seconds >= 20 && stage !== 'saving';
    const steps = ['preparing', 'downloading', 'saving'];
    const at = steps.indexOf(stage);

    return (
        <div className="flex h-full flex-col bg-[#F3F4F6] dark:bg-gray-950" role="status" aria-live="polite" aria-label="Loading the task">
            <div className="shrink-0 space-y-2 border-b border-gray-200 bg-white px-3 py-2.5 dark:border-gray-800 dark:bg-gray-900">
                <div className="flex items-center gap-3">
                    <div className={`${bone} h-8 w-8 rounded-full`} />
                    <div className={`${bone} h-6 flex-1`} />
                    <div className={`${bone} h-9 w-24 rounded-full`} />
                </div>
                <div className="flex items-center gap-2">
                    <div className={`${bone} h-4 w-28`} />
                    <div className={`${bone} h-6 w-20 rounded-full`} />
                </div>
            </div>

            <div className="relative h-[38%] min-h-[160px] shrink-0 animate-pulse bg-gray-800/90">
                <div className="absolute left-1/2 top-1/2 h-20 w-[68%] -translate-x-1/2 -translate-y-1/2 rounded-2xl border-[3px] border-teal-300/40" />
            </div>

            <div className="flex-1 space-y-3 p-3">
                <div className="space-y-3 rounded-3xl bg-white p-4 shadow-sm dark:bg-gray-900">
                    <p className="text-base font-bold text-gray-800 dark:text-gray-100">{STAGES[stage]}</p>
                    <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <div
                            className="h-full rounded-full bg-teal-600 transition-[width] duration-300"
                            style={{ width: `${[20, 60, 92][Math.max(0, at)]}%` }}
                        />
                    </div>
                    <p className="text-sm text-gray-500">
                        {stage === 'downloading' && progress?.loaded ? `${kb(progress.loaded)} so far · ` : ''}
                        {stage === 'saving' && progress?.products ? `${progress.products.toLocaleString()} products · ` : ''}
                        {slow ? `Still waiting for the server (${seconds} s). The signal may be weak.` : 'Once, then it works with no signal.'}
                    </p>
                    {stuck && onRetry && (
                        <button type="button" onClick={() => { setSeconds(0); onRetry(); }} className="h-12 w-full rounded-full bg-teal-700 text-base font-extrabold text-white active:bg-teal-800">
                            Try again
                        </button>
                    )}
                </div>
                {[0, 1, 2].map((i) => (
                    <div key={i} className="flex items-center gap-3 rounded-2xl bg-white p-3 dark:bg-gray-900">
                        <div className={`${bone} h-10 w-10`} />
                        <div className="flex-1 space-y-2">
                            <div className={`${bone} h-4 w-3/4`} />
                            <div className={`${bone} h-3 w-1/3`} />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/** My Tasks, before the list has arrived. */
export function HomeSkeleton() {
    return (
        <div className="mx-auto max-w-xl space-y-3 p-3" role="status" aria-label="Loading your tasks">
            <div className={`${bone} h-7 w-40`} />
            {[0, 1, 2].map((i) => (
                <div key={i} className="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div className="flex items-center justify-between">
                        <div className={`${bone} h-6 w-20 rounded-full`} />
                        <div className={`${bone} h-4 w-24`} />
                    </div>
                    <div className={`${bone} h-5 w-4/5`} />
                    <div className={`${bone} h-4 w-1/2`} />
                </div>
            ))}
        </div>
    );
}
