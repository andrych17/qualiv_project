import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            colors: {
                ink: {
                    900: 'var(--color-ink-900)',
                    600: 'var(--color-ink-600)',
                },
                surface: {
                    DEFAULT: 'var(--color-surface-0)',
                    0: 'var(--color-surface-0)',
                    50: 'var(--color-surface-50)',
                },
                border: {
                    DEFAULT: 'var(--color-border)',
                },
                accent: {
                    DEFAULT: 'var(--color-accent)',
                },
                signal: {
                    success: 'var(--color-signal-success)',
                    warning: 'var(--color-signal-warning)',
                    danger: 'var(--color-signal-danger)',
                    info: 'var(--color-signal-info)',
                },
            },
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                serif: ['"Source Serif 4"', ...defaultTheme.fontFamily.serif],
                mono: ['"IBM Plex Mono"', ...defaultTheme.fontFamily.mono],
            },
            borderRadius: {
                sm: '4px',
                DEFAULT: '4px',
                md: '8px',
                lg: '8px',
            },
        },
    },

    plugins: [forms],
};
