# Docs Index

Entry points for this repo's documentation. Start here instead of browsing folders blind.

## Core

- [`CLAUDE.md`](../CLAUDE.md) — agent rules, tech stack, build order, storage conventions.
- [`ARCHITECTURE.md`](../ARCHITECTURE.md) — customization ladder detail (consts → serials → custom fields → custom logic → plan/modules → vertical module).
- [`resources/DESIGN.md`](../resources/DESIGN.md) — design tokens, component inventory.
- [`README.md`](../README.md) — local setup / run instructions.

## Reviews

- [`reviews/`](reviews/) — dated project reviews. Most recent: [`reviews/2026-08-07-review.md`](reviews/2026-08-07-review.md).

## Archive

- [`archive/`](archive/) — superseded docs kept for history, not current. `docs.md` (original one-shot scaffolding prompt) lives here — it predates most of the actual codebase and should not be treated as current.

## Per-module specs

Each module under `app/Modules/<Module>/` carries its own `<MODULE>_SPECS.md` (plus `.drawio`/`.sql` where applicable) describing that module's data model and business rules. These are not indexed here individually — open the module folder directly.
