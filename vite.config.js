import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Each page has its own CSS and JS entry (resources/css/pages/<page>.css, resources/js/pages/<page>.js),
// loaded by the layouts alongside the shared app.css / app.js.
const pages = ['login', 'forgot-password', 'reset-password', 'dashboard', 'roster', 'hours', 'reports', 'data', 'accounts', 'airports', 'aircraft', 'maintenance', 'crew', 'flights', 'rules'];
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', ...pages.flatMap(page => [`resources/css/pages/${page}.css`, `resources/js/pages/${page}.js`])],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
});
