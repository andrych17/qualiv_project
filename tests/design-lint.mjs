// The UI rules in CLAUDE.md §9D are already strict; what was missing is anything that
// enforces them, which is how 657 hardcoded palette classes and 189 raw form tags got in.
// Fixing all of them today is not realistic, so this ratchets instead: the committed
// baseline records today's count per rule and the build fails only when a count goes UP.
// Existing debt stays parked, new debt cannot land.
//
// Run:            node tests/design-lint.mjs
// Accept a drop:  node tests/design-lint.mjs --update-baseline
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const BASELINE = join(ROOT, 'tests/design-lint-baseline.json');

const RULES = [
    {
        id: 'hardcoded-palette',
        // Tailwind's stock palette bypasses the theme tokens entirely, so these render the
        // same in all 12 themes and break outright in dark mode.
        why: 'use semantic tokens (ink-*, surface-*, accent, signal-*) instead of Tailwind palette colors',
        dir: 'resources/js',
        test: /\b(?:bg|text|border|ring|divide|from|via|to)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)-\d{2,3}\b|\b(?:bg|text|border)-(?:white|black)\b/g,
    },
    {
        id: 'raw-form-control',
        why: 'use @/Components/forms/* so the control inherits theme tokens and label/error markup',
        dir: 'resources/js/Pages',
        test: /<(?:input|select|textarea)[\s>]/g,
    },
    {
        id: 'native-confirm',
        why: 'use useConfirm() / ConfirmDialog.vue / AlertDialog.vue',
        dir: 'resources/js',
        // The project's own useConfirm() is also called confirm(), but it takes an options
        // object; only a string argument means the native browser dialog.
        test: /\b(?:window\.)?confirm\s*\(\s*['"`]|\b(?:window\.)?alert\s*\(/g,
    },
    {
        id: 'inline-modal-overlay',
        why: 'use @/Components/Modal.vue',
        dir: 'resources/js/Pages',
        test: /class="[^"]*\bfixed\b[^"]*\binset-0\b[^"]*"/g,
    },
];

const walk = (dir) =>
    readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        return statSync(path).isDirectory() ? walk(path) : path.endsWith('.vue') ? [path] : [];
    });

const count = (rule) => {
    const hits = [];
    for (const file of walk(join(ROOT, rule.dir))) {
        const matches = readFileSync(file, 'utf8').match(rule.test);
        if (matches) hits.push({ file: relative(ROOT, file), n: matches.length });
    }
    return { total: hits.reduce((sum, h) => sum + h.n, 0), hits };
};

const results = Object.fromEntries(RULES.map((rule) => [rule.id, count(rule)]));

if (process.argv.includes('--update-baseline')) {
    const next = Object.fromEntries(RULES.map((r) => [r.id, results[r.id].total]));
    writeFileSync(BASELINE, `${JSON.stringify(next, null, 4)}\n`);
    console.log('design-lint baseline updated:', next);
    process.exit(0);
}

let baseline;
try {
    baseline = JSON.parse(readFileSync(BASELINE, 'utf8'));
} catch {
    console.error(`design-lint: no baseline at ${relative(ROOT, BASELINE)} — run with --update-baseline once`);
    process.exit(1);
}

let failed = false;
for (const rule of RULES) {
    const now = results[rule.id].total;
    const was = baseline[rule.id] ?? 0;

    if (now > was) {
        failed = true;
        console.error(`\n✗ ${rule.id}: ${was} → ${now} (+${now - was}) — ${rule.why}`);
        // Only the heaviest files, so the message stays actionable rather than a wall.
        for (const hit of results[rule.id].hits.sort((a, b) => b.n - a.n).slice(0, 5)) {
            console.error(`    ${hit.n.toString().padStart(4)}  ${hit.file}`);
        }
    } else {
        const trend = now < was ? ` (was ${was}, -${was - now} — run --update-baseline to lock it in)` : '';
        console.log(`✓ ${rule.id}: ${now}${trend}`);
    }
}

if (failed) {
    console.error('\ndesign-lint: new violations introduced. Fix them, or justify raising the baseline.');
    process.exit(1);
}
