import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app-dashboard.css',
                'resources/css/app-booking.css',
                'resources/css/app-login.css',
                'resources/css/app-platform.css',
                'resources/js/app.js',
                'resources/js/pages/public-booking.js',
                'resources/js/pages/login-password-toggle.js',
                'resources/js/pwa.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        hmr: {
            host: '127.0.0.1',
            port: 5173,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
