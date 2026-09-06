// Derived ink/surface steps in tailwind.config.js are color-mix() expressions, so no browser
// or design tool ever shows their real value per theme. This resolves them the same way the
// browser would and asserts every ink step used for text clears WCAG AA (4.5:1) against the
// theme's own card surface, in all themes at once.
//
// Run: node tests/design-tokens.check.mjs
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import config from '../tailwind.config.js';

const css = readFileSync(new URL('../resources/css/app.css', import.meta.url), 'utf8');

/** @returns {Map<string, Record<string,string>>} theme name -> token hex values */
const parseThemes = () => {
    const themes = new Map();
    for (const block of css.split('}')) {
        const selector = block.slice(0, block.indexOf('{'));
        if (!selector.includes(':root') && !selector.includes('data-theme')) continue;
        const name = selector.includes(':root')
            ? 'classic-navy'
            : (selector.match(/data-theme="([^"]+)"/) ?? [])[1];
        if (!name) continue;
        const tokens = {};
        for (const [, key, value] of block.matchAll(/--color-([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})/g)) {
            tokens[key] = value;
        }
        if (tokens['ink-900']) themes.set(name, tokens);
    }
    return themes;
};

const rgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));

// color-mix(in srgb, A p%, B) — sRGB mixing is a plain per-channel lerp.
const mix = (a, b, pct) => rgb(a).map((c, i) => (c * pct + rgb(b)[i] * (100 - pct)) / 100);

const luminance = (channels) =>
    channels
        .map((c) => {
            const s = c / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        })
        .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);

const contrast = (fg, bg) => {
    const [hi, lo] = [luminance(fg), luminance(bg)].sort((a, b) => b - a);
    return (hi + 0.05) / (lo + 0.05);
};

// Resolve a token closure to concrete channels for one theme. Mirrors the two shapes the
// config emits: plain var(--x) and a single nested color-mix of two theme vars.
const resolve = (token, tokens) => {
    const expr = token({ opacityValue: undefined });
    const plain = expr.match(/^var\(--color-([a-z0-9-]+)(?:,.*)?\)$/);
    if (plain) return rgb(tokens[plain[1]]);
    const mixed = expr.match(/^color-mix\(in srgb, var\(--color-([a-z0-9-]+)\) ([\d.]+)%, var\(--color-([a-z0-9-]+)\)\)$/);
    assert.ok(mixed, `unhandled token expression: ${expr}`);
    return mix(tokens[mixed[1]], tokens[mixed[3]], Number(mixed[2]));
};

const themes = parseThemes();
assert.ok(themes.size >= 10, `expected all themes parsed, got ${themes.size}`);

const ink = config.theme.extend.colors.ink;
const surface = config.theme.extend.colors.surface;
const TEXT_STEPS = [900, 800, 700, 600, 500, 400]; // every ink step reachable as text-*
const failures = [];

for (const [name, tokens] of themes) {
    const card = resolve(surface[0], tokens);
    let previous = Infinity;

    for (const stepName of TEXT_STEPS) {
        const ratio = contrast(resolve(ink[stepName], tokens), card);
        if (ratio < 4.5) failures.push(`${name} ink-${stepName}: ${ratio.toFixed(2)}:1 (need 4.5)`);
        // The scale must stay monotonic, otherwise ink-500 could read darker than ink-700
        // and the hierarchy the steps exist to express silently inverts.
        if (ratio > previous) failures.push(`${name} ink-${stepName} is darker than the step above it`);
        previous = ratio;
    }

    // Surface steps are backgrounds, so they only need to be distinguishable from the card.
    for (const stepName of [100, 150, 200]) {
        const ratio = contrast(resolve(surface[stepName], tokens), card);
        if (ratio > 1.9) failures.push(`${name} surface-${stepName}: ${ratio.toFixed(2)}:1 is too dark for a hover fill`);
    }
}

// Opacity modifiers must not leak the non-numeric value Tailwind passes to base utilities.
for (const token of [ink[500], surface[100], config.theme.extend.colors.accent.DEFAULT]) {
    assert.doesNotMatch(token({ opacityValue: 'var(--tw-bg-opacity)' }), /NaN/, 'base utility produced NaN');
    assert.match(token({ opacityValue: 0.1 }), /10%, transparent/, 'modifier did not produce color-mix');
}

if (failures.length) {
    console.error(`design tokens: ${failures.length} failure(s)\n  ` + failures.join('\n  '));
    process.exit(1);
}
console.log(`design tokens OK — ${themes.size} themes x ${TEXT_STEPS.length} ink steps meet 4.5:1`);
