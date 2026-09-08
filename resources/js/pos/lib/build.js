/**
 * What this bundle is, so a till can be asked what it is actually running.
 *
 * A deploy has three places to fail and they look identical from the counter:
 * the server may not have pulled, it may have pulled without rebuilding, or
 * the device may still be holding an older bundle. Reading a commit off the
 * screen settles which — without it, "is the fix live?" can only be answered
 * by describing symptoms, and symptoms are exactly what is in dispute.
 *
 * The guards matter: these are compile-time substitutions (vite.config.js
 * `define`), so anything importing this outside a Vite build — a bare node
 * script, a test runner configured differently — would otherwise throw on an
 * undefined global rather than simply not knowing its build.
 */
export const BUILD_ID = typeof __BUILD_ID__ !== 'undefined' ? __BUILD_ID__ : 'dev';
export const BUILT_AT = typeof __BUILT_AT__ !== 'undefined' ? __BUILT_AT__ : '';

/** Short enough for a corner of the till, precise enough to act on. */
export const buildLabel = () => (BUILT_AT ? `${BUILD_ID} · ${BUILT_AT}` : BUILD_ID);
