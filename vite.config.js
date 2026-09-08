import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import react from '@vitejs/plugin-react';
import { execSync } from 'node:child_process';

// Stamped into the bundle so a till can be asked what it is actually running.
// "Is the fix live?" has repeatedly been unanswerable from the counter: the
// server can hold the new commit while the device still runs an old bundle,
// and the two are indistinguishable by looking at the screen. Now the screen
// says which.
const buildStamp = (() => {
    try {
        return execSync('git rev-parse --short HEAD', { stdio: ['ignore', 'pipe', 'ignore'] })
            .toString()
            .trim();
    } catch {
        // No git on the box, or a source-only deploy — the timestamp below
        // still distinguishes one build from another.
        return 'nogit';
    }
})();

export default defineConfig({
    define: {
        __BUILD_ID__: JSON.stringify(buildStamp),
        __BUILT_AT__: JSON.stringify(new Date().toISOString().slice(0, 16).replace('T', ' ')),
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/pwa.js',
                'resources/css/filament/vendor/theme.css',
                'resources/js/pos/main.jsx',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },

    // Scoped to our own source. Left to its default, vitest walks vendor/ and
    // picks up TypeScript tests that ship inside composer packages — whose own
    // dependencies are not installed here, so the run fails on a file that is
    // not ours and that we have no business executing.
    test: {
        include: ['resources/js/**/*.{test,spec}.{js,jsx,ts,tsx}'],
        exclude: ['**/node_modules/**', '**/vendor/**'],

        // The till's search box has to be driven the way a cashier drives it —
        // type, arrow, Enter, tap a row — because every bug it has had was in
        // that interaction rather than in any single function. jsdom is what
        // lets those be tests instead of hopeful reasoning.
        environment: 'happy-dom',
    },
});
