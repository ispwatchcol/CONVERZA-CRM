import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

import { ZiggyVue, route } from 'ziggy-js';

// Always use the Ziggy config injected at runtime by the @routes Blade
// directive (window.Ziggy). This ensures URLs are correct per environment
// (local / production) without baking them into the JS bundle.
const getZiggy = () => window.Ziggy;

window.route = (name, params, absolute, config) => route(name, params, absolute, config ?? getZiggy());

const appName = import.meta.env.VITE_APP_NAME || 'Converza';

createInertiaApp({
    title: (title) => (title ? `${appName} | ${title}` : appName),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, getZiggy())
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
