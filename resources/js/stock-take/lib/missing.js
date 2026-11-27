/**
 * What a task should have found but has no count for, worked out on the
 * phone from the task pack (A6), so hand-in needs no network.
 *
 * Count task: every product mapped to its aisles, less those already counted
 * in a handed-in task (`done`), less those counted here. A recount: the
 * products to recount, less those counted.
 *
 * A count the server refused doesn't count: it isn't on the server, so the
 * product still needs an answer.
 *
 * The same rule as StockTakeCounter::missing() on the server, which checks it
 * again when the hand-in arrives.
 */
export function missingFor(pack, entries) {
    const counted = new Set(
        (entries ?? []).filter((e) => e.syncStatus !== 'rejected').map((e) => e.productId),
    );

    const expected = pack.task.type === 'recount'
        ? (pack.task.recount ?? [])
        : [...new Set(pack.task.aisles.flatMap((a) => a.expected ?? []))].filter((id) => !pack.done?.[id]);

    return expected.filter((id) => !counted.has(id));
}

/** Still to count on one aisle, in mapping (shelf) order. */
export function stillToCount(pack, aisleId, entries) {
    const counted = new Set((entries ?? []).filter((e) => e.syncStatus !== 'rejected').map((e) => e.productId));
    const aisle = pack.task.aisles.find((a) => a.id === aisleId);

    return (aisle?.expected ?? []).filter((id) => !counted.has(id) && !pack.done?.[id]);
}
