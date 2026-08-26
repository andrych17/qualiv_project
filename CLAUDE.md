# CLAUDE.md

Guidance for Claude Code (and any Claude model) working in this repository.

## 1. Project Overview

A **SaaS ERP platform**, architected as a **modular monolith** (Odoo-like), with **vertical/niche editions** rented to specific industries. Each vertical is a bundle of core modules + industry-specific modules on top of the same platform.

- **First vertical to ship and rent to clients: Legal.**
- Planned future verticals: Property Management, and others to be decided based on market validation.
- Built and maintained by a **single developer**, so architecture choices must optimize for: low operational overhead, clear module boundaries (to keep the codebase navigable alone), and the ability to sell/rent verticals independently without duplicating core logic.

### Business context Claude should keep in mind
- This is a rentable, multi-tenant SaaS product, not an internal tool. Features should be evaluated not just for correctness but for **marketability** (does it make the product easier to sell, demo, or justify a subscription tier?).
- Verticals are a packaging/monetization strategy: **core modules are shared**, **vertical modules are the upsell**. Avoid leaking vertical-specific logic into core modules.
- Prefer building things that make future verticals cheaper to launch (reusable components, generic workflow/scheduling primitives) over one-off solutions for Legal only.

## 2. Architecture Philosophy

> Detail for customization (DB / code / custom fields & logic): **[ARCHITECTURE.md](ARCHITECTURE.md)** — keep this ladder in mind on every feature.

### Customization ladder (no `tenant_id` branches)
Prefer lower rungs first. Same PHP/Vue path; Firm A vs B differ via tenant DB data.

| Rung | What | Where |
|------|------|--------|
| 1 | Constants | `SYSCONFIG.config_consts` |
| 2 | Serials | `SYSCONFIG.config_snums` |
| 3 | Custom fields | `CUSTOMFIELDS.*` |
| 4 | Custom logic | Services reading consts + field values / strategies |
| 5 | Plan / modules | Central `tenants.plan` + `config/tenant_modules.php` |
| 6 | Vertical module | `app/Modules/Legal` etc. |

**Anti-pattern:** `if (tenant_id === '001')`. **OK:** seed different consts/field defs/snums per firm.

### Modular monolith first. Microservices/APIs only when justified
1. Default to building inside the monolith as a well-isolated module.
2. Only extract a microservice/standalone API when at least one is true:
   - The workload has fundamentally different scaling needs (e.g. heavy async processing, document OCR, PDF generation at volume, AI/LLM calls).
   - It needs a different language/runtime than PHP/Laravel is good at.
   - It must be reused across products/verticals independently of the monolith's release cycle.
   - It touches sensitive data that benefits from strong isolation (e.g. a payments/billing service).
3. Never extract a service purely for "clean architecture" aesthetics — extraction has real operational cost for a solo dev (deployment, monitoring, versioning). Justify it against that cost every time.
4. When in doubt, ask: *"Would Claude Code and I still be able to reason about this system in 6 months without a team?"* If a proposed split makes that harder, don't do it.

### Platform-level modules (a fourth category)
- Most modules are **Core** (shared, zero knowledge of any vertical) or **Vertical** (depends on Core, e.g. Legal), with **Microservice** extraction justified only per the criteria above. A small number of modules are neither — they run entirely **outside every tenant DB**, in the central DB (`nusaevo`), and tenant DBs' own existence depends on them rather than the reverse (e.g. **CENTRAL** — tenant registry, plan/entitlement, subscription billing, dunning; see `CENTRAL_SPECS.md`).
- A Platform-level module must never depend on anything inside a tenant-scoped module (WNE, DMS, SYSCONFIG, ...) — none of those exist until the Platform-level module has provisioned the tenant DB they live in.

### Module boundaries (inside the monolith)
- Each module (Schedule, Notifications, Workflows, Legal-Cases, etc.) should be structured as a self-contained unit: its own migrations, models, services, policies, routes, and (if applicable) frontend feature folder.
- Core modules must have **zero knowledge** of vertical modules. Vertical modules depend on core, never the reverse.
- Cross-module communication goes through events/contracts (e.g. Laravel events, service interfaces), not direct model reach-through into another module's internals.
- Treat each module as if it could one day be toggled on/off per tenant (per-plan feature flags) — this is core to the rental/SaaS model.

### Web vs future clients (API boundary)
- **Web (current):** Laravel + Inertia.js + Vue 3. Controllers return `Inertia::render(...)`. Do **not** build REST/GraphQL endpoints for web pages.
- **Business logic** always lives in Service classes (never in controllers or Vue). Services are the reusable boundary.
- **Mobile / Tablet / external clients (later):** versioned REST APIs that call the same Services — no duplicated domain logic. Do not invent a parallel API layer for web.
- Start with Web edition. Ship REST only when a non-Inertia client is real, not speculative.

## 3. Tech Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 11/12 |
| Frontend (Web) | Vue 3 via Inertia.js (Vite, Tailwind CSS, Lucide Icons) |
| Future clients | Versioned REST reusing Service classes (not yet) |
| Database | PostgreSQL |
| Cache / Queue broker | Redis |
| Web server | Nginx |
| Hosting | Ubuntu VPS |
| Local / container | Docker Compose (`docker-compose.yml`: PHP app, queue worker, PostgreSQL, Redis) |
| Design tooling | Claude Design / Google Stitch for UI exploration, see `DESIGN.md` for the resulting system |
| Primary coding agent | Claude Code |

Notes:
- Laravel handles core business logic, auth, multi-tenancy, module orchestration, background jobs (via Redis queues).
- Vue pages receive props from Inertia — keep presentation in Vue, domain rules in PHP Services.
- Any microservice added later should default to whatever language fits the job best; don't force everything into PHP if it's the wrong tool (e.g. a Python service for document parsing/OCR is fine).

## 4. Multi-Tenancy

- Mode B: **one PostgreSQL database per tenant** (`tenant_{id}`), via `stancl/tenancy`. Tenant app data does **not** use a `tenant_id` column — isolation is the DB boundary.
- Central DB (`nusaevo`) holds tenant registry + login lookup (`tenant_user_lookups`) + **plan** (`tenants.plan`), plus plan/entitlement, billing, and dunning tables — see `CENTRAL_SPECS.md` for the full schema; this is the Platform-level module described in §2. Users and module data live in the tenant DB.
- Inside each tenant DB, modules get separate schemas (`SYSCONFIG`, `WNE`, `DMS`, `CRM`,
  `SCHEDULE`, `INVENTORY`, `ACCOUNTING`, `SALES`, `PURCHASE`, `HCM`, `PAYROLL`, `PERF`,
  `AIINSIGHT`, `LEGAL`, `CUSTOMFIELDS`, `PROJECTS`). See §7A for the authoritative list. (This consolidates
  the old separate `NOTIFICATIONS`/`WORKFLOW` schemas into the single `WNE` schema, per
  `WNE_SPECS.md` — an earlier version of this section still had the pre-merge names.)
- Tenant resolution is **login-bound** (session `tenant_id` after email lookup), not domain/subdomain. UI may switch among memberships via sidebar tenant dropdown.
- Queue/cache/filesystem are tenant-aware via stancl bootstrappers. Do **not** use PostgreSQL RLS.
- Plan/feature-flagging: `config/tenant_modules.php` + `TenantFeatureService` + middleware `module:CODE`. Sidebar menus also hide modules not on the plan.
- Authorization inside a tenant: SYSCONFIG trustee (C/R/U/D) via middleware `menu.perm:MENU_CODE` (not a substitute for DB isolation).

## 5. Build Order

0. **CENTRAL** (`nusaevo` central DB — tenant registry, plan/entitlement, subscription
   billing, dunning) — Platform-level, structurally first: a tenant DB has to exist before
   `SYSCONFIG` or any Core module can be built against a real one. MVP is just enough of the
   Tenant Registry (see `CENTRAL_SPECS.md` §3B) and a manual provisioning path to stand up the
   *first* tenant DB; Billing/Dunning can follow once there's a real second or third paying
   tenant to bill. See `CENTRAL_SPECS.md` §5 for the suggested build order within this step.
1. **SYSCONFIG** (System Configuration, Access Control & Runtime Settings) — foundational,
   built before anything else, including the design system and every Core module. Every later
   piece — menu/permission checks, tenant-editable consts, serial numbering, module
   activation — depends on it, and several modules' own specs already assume it exists
   (e.g. Legal's `MATTER_PREFIX`/`URGENT_SETS_PENDING` example in `ARCHITECTURE.md`). See
   `SYSCONFIG_SPECS.md`.
2. **CustomFields** (`CUSTOMFIELDS` schema) — foundational alongside SYSCONFIG, built
   immediately after it and before the design system or any Core module. EAV + config-driven
   logic; see `ARCHITECTURE.md` and `CUSTOMFIELDS_SPECS.md`. This is not optional scaffolding:
   DMS's Metadata Management, CRM's Custom Fields, Legal's deed/matter/land-object fields,
   Inventory's product custom fields, Payroll's component/run fields, and others all already
   assume `CUSTOMFIELDS.field_defs`/`field_values` exist as part of *their own* MVP ship.
   `SYSCONFIG_SPECS.md` §5 and `CUSTOMFIELDS_SPECS.md` §5 each independently recommend this
   correction; this entry applies it.
3. **Design system / component library** — build base UI components and widgets per
   `DESIGN.md` before building feature screens. Every module UI should be composed from this
   shared library, not one-off styled per module.
4. **Core modules** (shared by every vertical). Each has its own `*_SPECS.md` covering
   Backgrounds/Goals/Forms-Engines/Storage/Technical Notes — check the relevant spec before
   starting work on a module; this section only tracks sequence, not detail. A build order
   consistent with each module's own stated dependencies (see each spec's §5 for specifics):
   - **WNE** (Workflow & Notification Engine — approvals, state machines, task routing,
     multi-channel notifications) — foundational; every other module reaches for it first.
   - **DMS** (Document Management System)
   - **CRM**
   - **Schedule**
   - **Inventory**
   - **Accounting** — built before Purchase/Sales are considered feature-complete, since both
     have a hard dependency on it for one specific action (AP recording, AR billing).
   - **Purchase**
   - **Sales**
   - **HCM**
   - **Payroll** — hard dependency on HCM (employee identity); build immediately after it.
   - **Performance**
   - **AIInsight** — specced (`AIINSIGHT_SPECS.md`) but not yet built; gated on a Zero Data
     Retention agreement with Anthropic before going live for any confidentiality-sensitive
     tenant (see the project's "on the horizon" notes).
5. **Projects** (`PROJECTS` schema, `app/Modules/Projects/`) — project/issue tracker with a Kanban board (see `app/Modules/Projects/PROJECTS_SPECS.md`). Can be used for Nusaevo's own tenant via the `internal` plan in `config/tenant_modules.php` (§2 customization ladder rung 5), used as the team's own Jira-style board.
6. **Legal vertical module** — the first paid, rentable product, built on top of the Core
   modules above. Prioritize correctness and UX polish here over speculative generalization
   for future verticals.
7. Future verticals (e.g. Property) come after Legal is validated in market — reuse core modules, add vertical-specific modules only.
When working on a task, Claude should check whether it belongs in **Core** (reusable) or in the **active vertical module** (Legal-specific) and place code accordingly. If unsure, ask rather than guessing — misplacing logic here has long-term architectural cost.

## 6. Coding Conventions

### Laravel / PHP
- Follow PSR-12 and standard Laravel conventions (thin controllers, business logic in services/actions, Eloquent models kept lean).
- Use Form Requests for validation, Policies for authorization, and Events/Listeners for cross-module communication.
- Every new table needs a migration; never edit a migration that's already been run in a shared/deployed environment — write a new one.
- Write feature/unit tests for core module logic, especially Scheduler/Workflows since other modules will depend on their correctness.

### Vue.js / TypeScript
- Strict TypeScript. No implicit `any`.
- Keep feature folders aligned with backend modules where practical, to keep the mental model consistent for a solo dev.
- Shared UI primitives live in the design-system package/folder described in `DESIGN.md`; feature code should compose those, not redefine styles inline.

### General
- Prefer explicit, boring code over clever abstractions — this codebase will be read and extended by one person (plus Claude Code), so optimize for future-you re-reading it, not for impressing a team.
- Add short comments explaining *why* for any non-obvious architectural decision (e.g. why something was kept in Core vs. Legal, why a microservice was split out).

## 7. Storage Conventions
### A. Database:
- One DB for each tenant.
- Separate schema for each modules.
- Custom fields on separate schema.
- Structure:
```text
tenant_001.			# Database
├── SYSCONFIG.		# Menus, groups, rights, consts (runtime authz)
├── INVENTORY.		# Prefer new inventory tables here (legacy demo tables may still be in public)
├── WNE.
├── CRM.
├── SCHEDULE.
├── DMS.
├── LEGAL.
├── ACCOUNTING.
├── SALES.
├── PURCHASE.
├── HCM.
├── PAYROLL.
├── PERF.
├── PROJECTS.		
├── AIINSIGHT.
└── CUSTOMFIELDS.
```
- Table naming: 
	- Master: 
		- mostly use 1 part, ie. materials, partners, etc.
	- Transaction: 
		- mostly use 2 part: abbreviated-name + level, ie. `SALES.so_hdrs` = Sales module,
		  Sales Order table, Header level — matches the actual convention used across every
		  `*_SPECS.md` (`ACCOUNTING.gl_journals`, `WNE.wrkflow_instances`,
		  `SCHEDULE.sched_items`, `PURCHASE.pur_receipt_hdrs`, etc.)
- Use bigint for PK, FK, and JOIN. Add UUID for external facing objects.
- Use stancl/tenancy whenever possible.
- Tenant resolution strategy is login-bound.
- Do not use PostgreSQL Row Level Security.

## B. Object File:
- Separate folder for each tenant.
- Subfolder and object file arrangement must consider restore performance / capability.
- Structure:
```text
tenant_001/
├── DB/
├── DMS/          # primary shared document store for most modules' documents,
│                 # subfoldered by owning module — e.g. DMS/LEGAL, DMS/HCM, DMS/Sales
├── CRM/
├── SCHEDULE/
├── ACCOUNTING/   # system-generated artifacts not routed through DMS (bank statement
│                 # imports, tax exports, generated reports) — see ACCOUNTING_SPECS.md §4
├── PURCHASE/     # reserved per-module convention for restore-planning consistency;
│                 # most actual document content still routes through DMS — see
│                 # PURCHASE_SPECS.md §4
├── PAYROLL/      # same reserved-folder convention as Purchase — see PAYROLL_SPECS.md §4
└── INVENTORY/    # same reserved-folder convention as Purchase — see INVENTORY_SPECS.md §4
```
- Modules not listed above (WNE, HCM, Sales, Performance, AIInsight) own no top-level R2
  folder of their own — their files, if any, route entirely through DMS's structure with a
  `subject_type`/`subject_id` pointer back to the owning record, per each module's own spec.
- Object file will use one Cloudflare R2 bucket. With naming convention to differentiate between tenants, modules, time, etc.

## 8. Development Conventions

### Build & Run Commands
Host has Node/npm; PHP runs inside Docker Compose (image includes `pdo_pgsql`, `redis`, Composer). Do **not** use bare `composer:latest` for artisan — that image lacks DB/Redis extensions.

Stack (see `docker-compose.yml`):
- `app` — `php artisan serve` on `:8000`
- `queue` — `php artisan queue:work` (Redis)
- Postgres/Redis now come from the shared-infra stack (`shared-postgres`/`shared-redis`,
  host port **5432**/**6379**), joined via the external `shared-infra` network. Start
  that stack before this one.

Compose injects DB/Redis env for containers. Vite stays on the host.

### Local Development Setup
```bash
# First-time / after clone
cp .env.example .env          # set APP_KEY via artisan key:generate below if empty
docker compose build
docker compose run --rm app composer install
npm install
docker compose up -d
docker compose exec app php artisan key:generate   # once
docker compose exec app php artisan migrate --seed

# Local / container
docker compose up -d          # app + queue (needs shared-infra stack running)
npm run dev                   # Vite on host → http://localhost:8000

# Host tools (psql/redis-cli): localhost:5432 / localhost:6379 (shared-infra stack)
# PHP always via docker — .env uses postgres/redis service names (network aliases).
```

One-off artisan (examples):
- **Migrate**: `docker compose exec app php artisan migrate`
- **Seed**: `docker compose exec app php artisan db:seed`
- **Fresh + seed**: `docker compose exec app php artisan migrate:fresh --seed`
- **Tinker**: `docker compose exec app php artisan tinker`

### Build Production Assets
- **Vite production build**: `npm run build`

### Code Quality & Formatting
- **PHP Linting (Laravel Pint)**: `docker compose exec app ./vendor/bin/pint`
- **TypeScript Checking**: `npm run build` (runs `vue-tsc`)

### Running Tests
- **Run PHPUnit tests**: `docker compose exec app php artisan test`
 
## 9. Codebase Guidelines & Conventions

### A. Modular Monolith Architecture
- Business modules live in `app/Modules/<ModuleName>/`.
- Each module contains:
  - `Controllers/` (Thin controllers only)
  - `Models/` (Eloquent models with query scopes)
  - `Requests/` (Store/Update FormRequest validation classes)
  - `Services/` (All business logic and DB transactions)
  - `Data/` (DTOs / Data objects)
  - `Enums/` (Status values and constants)
  - `Routes/` (Routings named `web.php`)
- Shared/core utilities live in `app/Shared/` (`Actions/`, `DTOs/`, `Enums/`, `Services/`, `Traits/`, `Helpers/`).
- Module routes are loaded dynamically from `routes/web.php`.

### B. Frontend Page Structure
- Vue pages live in `resources/js/Pages/<ModuleName>/Items/` (e.g. `Index.vue`, `Create.vue`, `Edit.vue`).
- Shared frontend layouts, navigation, forms, and table components live in `resources/js/Components/` (`layout/`, `navigation/`, `forms/`, `tables/`, `filters/`, `modals/`, `feedback/`).

### C. Coding Conventions
- **Controllers**: Keep controllers thin. Validate requests using Form Requests, delegate execution to Service classes, and return Inertia responses.
- **TypeScript**: Use strict TypeScript in Vue files. Explicitly define types and interfaces for backend-passed props.
- **Tailwind CSS**: Use utility classes directly for layouts and UI styling. Maintain clean structure and consistent spacing.
- **Lucide Icons**: Render Lucide icons dynamically in layouts and sidebars using the `<component :is="..." />` helper.

## 10. Working with Claude Code

- Before adding a new module or service, state which category it falls into (Core / Vertical / Microservice / Platform-level) and why, per Section 2 and Section 5.
- When a task could reasonably be solved either inside the monolith or as a separate service, default to the monolith and flag the tradeoff rather than silently extracting a service.
- When touching multi-tenant data paths, double-check tenant scoping is present — this is a recurring risk area.
- Prefer the customization ladder in §2 / `ARCHITECTURE.md` (consts → serials → custom fields → logic) over tenant_id branches.
- Reference `resources/DESIGN.md` before building any new UI — compose from `resources/js/Components/` (StatusBadge, DataTable Status Rail, Panel, StatCard, PrimaryButton). Do not invent ad hoc gray/indigo chrome.
- Since this is a commercial SaaS product, when proposing a feature or implementation approach, briefly note if there's a simpler version that would still be sellable (MVP bias), especially for the Legal module which is closest to revenue.

## 11. Open Items to Fill In As the Project Grows

- [x] API contract (Web): **Inertia.js**. Controllers → Services → `Inertia::render`. REST only later for mobile/external; same Services.
- [x] Auth strategy (session + login-bound tenancy; Sanctum reserved for future token clients)
- [ ] Tenant SaaS subscription billing (how the platform itself charges each tenant for their
      plan) — `tenants.plan` string exists in the central DB; no payment provider integration
      yet. Distinct from the in-app AR/AP item below — do not conflate the two.
- [x] In-app AR/AP module ownership (how a *tenant's* Sales/Purchase/vertical modules bill
      *their* customers and pay *their* vendors) — **resolved**: Accounting is the platform's
      one AR/AP ledger; Sales is the sole AR-side caller (`InvoiceRequested`/
      `PaymentRequested`, including on behalf of vertical modules like Legal via
      `SalesOrderRequested` — see `SALES_SPECS.md` §3I/§5 and `LEGAL_SPECS.md` §2); Purchase
      is the sole AP-side caller (`BillRequested` — see `PURCHASE_SPECS.md` §3F/§5).
- [ ] CI/CD pipeline and deployment process for the Ubuntu VPS
- [ ] Per-tenant infrastructure limits/monitoring approach
- [x] `DESIGN.md` — design tokens, component inventory (`resources/DESIGN.md`; tokens in `app.css` / Tailwind)
- [x] Plan/module feature flags (`tenants.plan` + `config/tenant_modules.php` + `module:` middleware)
- [x] In-tenant menu trustee enforcement (`menu.perm:` middleware)
- [x] Custom fields + custom logic + serials (`ARCHITECTURE.md`; field-defs admin UI still open)

## graphify — WAJIB SEMUA PROJECT / SEMUA AI AGENT

Canonical: `~/.agents/rules/graphify.md` (always_on).

STRICT:
1. JANGAN `grep` / `grep_search` / ripgrep sebagai **langkah pertama** untuk cari kode, flow, arsitektur, bug tracing codebase, atau navigasi source.
2. SELALU graphify dulu: cek `graphify-out/graph.json` → jika hilang `graphify index .` → `graphify query "..."` (atau MCP `query_graph`).
3. Relasi: `graphify path A B`. Konsep: `graphify explain "..."`.
4. `grep` / baca raw **hanya** setelah graphify kosong/gagal, atau user kasih path file eksplisit.
5. Wiki `graphify-out/wiki/index.md` preferensi navigasi. `GRAPH_REPORT.md` hanya review luas.
6. Setelah edit kode di session: `graphify update .` (AST-only).
7. Subagent ikut aturan ini.
8. Pengecualian: path eksplisit user; non-codebase (git/SQL/MCP-DB/build/config); graphify CLI/MCP error total (laporkan + fallback).


---

<!-- caveman-begin -->
## Caveman - Chat Response Mode

Respond terse like smart caveman. All technical substance stay. Only fluff die.

Rules:
- Drop: articles (a/an/the), filler (just/really/basically), pleasantries, hedging
- Fragments OK. Short synonyms. Technical terms exact. Code unchanged.
- Pattern: [thing] [action] [reason]. [next step].
- Not: "Sure! I'd be happy to help you with that."
- Yes: "Bug in auth middleware. Fix:"

Auto-Clarity: drop caveman for security warnings, irreversible actions, user confused. Resume after.
Boundaries: code/commits/PRs written normal.
<!-- caveman-end -->

## Ponytail - Coding Mode

You are a lazy senior developer. Lazy means efficient, not careless. The best code is the code never written.

Before writing any code, stop at the first rung that holds:

1. Does this need to be built at all? (YAGNI)
2. Does the standard library already do this? Use it.
3. Does a native platform feature cover it? Use it.
4. Does an already-installed dependency solve it? Use it.
5. Can this be one line? Make it one line.
6. Only then: write the minimum code that works.

Rules:
- No abstractions that were not explicitly requested.
- No new dependency if it can be avoided.
- No boilerplate nobody asked for.
- Deletion over addition. Boring over clever. Fewest files possible.
- Question complex requests: Do you actually need X, or does Y cover it?
- Mark intentional simplifications with a ponytail: comment.

Not lazy about: input validation, error handling that prevents data loss, security, accessibility, anything explicitly requested.

Domain Boundaries: Ponytail governs CODE. Caveman governs CHAT. Code blocks/commits written normal.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
