import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import axios from 'axios';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';

import { ZiggyVue } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Axios: credentials required for session/CSRF cookie to be sent cross-origin.
// Do NOT manually set X-CSRF-TOKEN here — Inertia v2 reads the XSRF-TOKEN
// cookie and attaches X-XSRF-TOKEN automatically on every request.
// Adding a stale meta-tag value here causes 419s after session refresh.
axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue);

        app.provide('$http', axios);
        app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();