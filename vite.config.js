import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

const pages = ['login', 'roster', 'aircraft', 'maintenance', 'crew', 'flights', 'rules'];
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
