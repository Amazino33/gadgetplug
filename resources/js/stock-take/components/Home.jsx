import { HomeSkeleton } from './Skeletons';

const TYPE_LABELS = { count: 'Count', recount: 'Recount', mapping: 'Aisle mapping' };

function dueLabel(task) {
    if (!task.due_date) return 'No due date';
    const date = new Date(`${task.due_date}T00:00:00`);
    return `Due ${date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })}`;
}

/**
 * My Tasks: everything given to this person that is still open, soonest due
 * first. Mapping tasks open their aisles; count and recount tasks open the counting screen.
 */
export default function Home({ home, offline = false, local = { waiting: {}, handIn: {} }, onOpenTask, onMapAll }) {
    if (!home) {
        return <HomeSkeleton />;
    }

    return (
        <div className="mx-auto max-w-xl space-y-4 p-3">
            <h1 className="px-1 pt-1 text-xl font-extrabold text-gray-900 dark:text-gray-100">My tasks</h1>

            {offline && (
                <p role="status" className="rounded-xl bg-amber-100 p-3 text-base font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                    📴 Offline. These are your tasks as this phone last saw them. Downloaded tasks can be counted now.
                </p>
            )}

            {home.tasks.length === 0 && (
                <p className="rounded-2xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700">
                    Nothing assigned to you right now.
                </p>
            )}

            <ul className="space-y-3">
                {home.tasks.map((task) => {

                    return (
                        <li key={task.id}>
                            <button
                                type="button"
                                onClick={() => onOpenTask(task)}
                                className="w-full rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm active:scale-[0.99] disabled:opacity-60 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="rounded-full bg-teal-50 px-2.5 py-1 text-sm font-bold text-teal-800 dark:bg-teal-900/40 dark:text-teal-200">
                                        {TYPE_LABELS[task.type] ?? task.type}
                                    </span>
                                    <span className={`text-sm font-semibold ${task.overdue ? 'text-red-600' : 'text-gray-500'}`}>
                                        {task.overdue ? 'Overdue · ' : ''}{dueLabel(task)}
                                    </span>
                                </div>
                                <p className="mt-2 text-base font-bold text-gray-800 dark:text-gray-100">
                                    {task.aisles.some((a) => a.quick)
                                        ? 'Quick count · any aisle: count and map as you go'
                                        : task.aisles.length > 0
                                        ? task.aisles.map((a) => a.label).join(', ')
                                        : `${task.products_count} product${task.products_count === 1 ? '' : 's'}`}
                                </p>
                                <p className="mt-0.5 text-sm text-gray-600 dark:text-gray-300">
                                    {task.stock_take ? `${task.stock_take.reference} · ${task.stock_take.name}` : 'Not part of a stock take'}
                                    {task.store ? ` · ${task.store}` : ''}
                                </p>
                                {task.status === 'in_progress' && <p className="mt-2 text-base font-semibold text-teal-700">Started — tap to carry on</p>}
                                {local.waiting[task.id] > 0 && <p className="mt-1 text-base font-semibold text-gray-700 dark:text-gray-200">⬆ {local.waiting[task.id]} waiting to upload</p>}
                                {local.handIn[task.id] === 'queued' && <p className="mt-1 text-base font-semibold text-amber-800 dark:text-amber-200">Submitted on this phone, waiting for signal</p>}
                            </button>
                        </li>
                    );
                })}
            </ul>

            {home.canMapAll && (
                <button
                    type="button"
                    onClick={onMapAll}
                    className="w-full rounded-2xl bg-teal-700 px-4 py-4 text-base font-bold text-white shadow-sm active:scale-[0.99]"
                >
                    Map aisles
                </button>
            )}
        </div>
    );
}
