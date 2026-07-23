import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/communications-hub.css', 'resources/js/app.js', 'resources/js/auth.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    // Two weights only — fewer font downloads on first paint.
                    weights: [400, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules/@hotwired/turbo')) {
                        return 'turbo';
                    }

                    if (
                        id.includes('/resources/js/workspace-sync.js')
                        || id.includes('/resources/js/member-management.js')
                        || id.includes('/resources/js/workspace-admin.js')
                        || id.includes('/resources/js/sales-ops-sync.js')
                        || id.includes('/resources/js/portal-dashboard.js')
                    ) {
                        return 'workspace-features';
                    }

                    if (
                        id.includes('/resources/js/communications-webphone.js')
                        || id.includes('/resources/js/communications-dialer.js')
                        || id.includes('/resources/js/communications-auto-dial.js')
                        || id.includes('node_modules/jssip')
                        || id.includes('node_modules/sip.js')
                    ) {
                        return 'communications';
                    }

                    if (id.includes('/resources/js/agent-presence.js')) {
                        return 'agent-presence';
                    }
                },
            },
        },
    },
});
