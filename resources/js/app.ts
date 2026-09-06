import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Nusaevo ERP';

// ponytail: auto-reload when dynamic import fails due to stale chunk after deployment (rate-limited)
if (typeof window !== 'undefined') {
    window.addEventListener('vite:preloadError', (event) => {
        const lastReload = sessionStorage.getItem('vite_preload_reload');
        const now = Date.now();
        if (!lastReload || now - parseInt(lastReload, 10) > 10000) {
            sessionStorage.setItem('vite_preload_reload', String(now));
            window.location.reload();
        } else {
            console.error('Vite chunk preload error loop prevented:', event);
        }
    });
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
