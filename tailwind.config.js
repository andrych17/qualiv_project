import defaultTheme from 'tailwindcss/defaultTheme.js';
import forms from '@tailwindcss/forms';

// Tailwind v3 cannot apply an opacity modifier (bg-accent/10) to a color stored as
// var(--x) unless the var holds channels + an <alpha-value> placeholder. Rather than
// rewrite all 24 theme blocks in app.css to channel form, each token is a closure that
// switches to color-mix() when a modifier is present. Requires Chrome 111+/Safari 16.2+.
// Tailwind hands opacityValue as a number only for real modifiers (bg-accent/10). For the
// base utility it passes the string "var(--tw-bg-opacity)", which color-mix cannot consume,
// so anything non-numeric falls back to the opaque color.
const alpha = (color, opacityValue) => {
    const pct = Number(opacityValue) * 100;
    return Number.isFinite(pct) ? `color-mix(in srgb, ${color} ${pct}%, transparent)` : color;
};

const tok = (name, fallback) => {
    const color = fallback ? `var(--color-${name}, ${fallback})` : `var(--color-${name})`;
    return ({ opacityValue }) => alpha(color, opacityValue);
};

// Mid-scale steps (ink-400..800, surface-100..200) are derived from the tokens app.css
// actually defines, so they follow every theme's light/dark values automatically instead
// of needing 6 more CSS vars per theme.
const step = (from, toward, pct) => {
    const mixed = `color-mix(in srgb, var(--color-${from}) ${pct}%, var(--color-${toward}))`;
    return ({ opacityValue }) => alpha(mixed, opacityValue);
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{vue,ts}',
    ],

    theme: {
        extend: {
            colors: {
                ink: {
                    900: tok('ink-900'),
                    800: step('ink-900', 'ink-600', 66),
                    700: step('ink-900', 'ink-600', 33),
                    600: tok('ink-600'),
                    500: step('ink-600', 'surface-0', 90),
                    400: step('ink-600', 'surface-0', 84),
                },
                surface: {
                    DEFAULT: tok('surface-0'),
                    0: tok('surface-0'),
                    50: tok('surface-50'),
                    100: step('surface-50', 'ink-900', 95),
                    150: step('surface-50', 'ink-900', 92),
                    200: step('surface-50', 'ink-900', 88),
                    sunken: step('surface-50', 'ink-900', 95),
                },
                border: {
                    DEFAULT: tok('border'),
                },
                accent: {
                    DEFAULT: tok('accent'),
                    text: tok('accent-text', '#ffffff'),
                },
                signal: {
                    success: tok('signal-success'),
                    warning: tok('signal-warning'),
                    danger: tok('signal-danger'),
                    info: tok('signal-info'),
                },
            },
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                serif: ['"Source Serif 4"', ...defaultTheme.fontFamily.serif],
                mono: ['"IBM Plex Mono"', ...defaultTheme.fontFamily.mono],
            },
            boxShadow: {
                '2xs': '0 1px 1px rgb(0 0 0 / 0.04)',
                xs: '0 1px 2px rgb(0 0 0 / 0.06)',
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
