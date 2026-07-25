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
- Central DB (`nusaevo`) holds tenant registry + login lookup (`tenant_user_lookups`) + **plan** (`tenants.plan`). Users and module data live in the tenant DB.
- Inside each tenant DB, modules get separate schemas (`SYSCONFIG`, `INVENTORY`, `CRM`, `SCHEDULE`, `NOTIFICATIONS`, `WORKFLOW`, `LEGAL`, `CUSTOMFIELDS`). See §7.
- Tenant resolution is **login-bound** (session `tenant_id` after email lookup), not domain/subdomain. UI may switch among memberships via sidebar tenant dropdown.
- Queue/cache/filesystem are tenant-aware via stancl bootstrappers. Do **not** use PostgreSQL RLS.
- Plan/feature-flagging: `config/tenant_modules.php` + `TenantFeatureService` + middleware `module:CODE`. Sidebar menus also hide modules not on the plan.
- Authorization inside a tenant: SYSCONFIG trustee (C/R/U/D) via middleware `menu.perm:MENU_CODE` (not a substitute for DB isolation).

## 5. Build Order

1. **Design system / component library first** — build base UI components and widgets per `DESIGN.md` before building feature screens. Every module UI should be composed from this shared library, not one-off styled per module.
2. **Core modules** (shared by every vertical):
   - Scheduler
   - Notifications
   - Workflows (approvals, state machines, task routing)
   - CRM
   - **CustomFields** (`CUSTOMFIELDS` schema) — EAV + config-driven logic; see `ARCHITECTURE.md`
3. **Legal vertical module** — first paid, rentable product. Built on top of the core modules above. This is the first real revenue test of the platform; prioritize correctness and UX polish here over speculative generalization for future verticals.
4. Future verticals (e.g. Property) come after Legal is validated in market — reuse core modules, add vertical-specific modules only.

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
├── CRM.
├── SCHEDULE.
├── NOTIFICATIONS.
├── WORKFLOW.
├── LEGAL.
└── CUSTOMFIELDS.
```
- Table naming: 
	- Master: 
		- mostly use 1 part, ie. materials, partners, etc.
	- Transaction: 
		- mostly use 2 part: name + level, ie. sales.order_hdrs = Sales module, Order table, Header level
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
├── CRM/
├── SCHEDULE/
├── NOTIFICATIONS/
├── DMS/
└── LEGAL/
```
- Object file will use one Cloudflare R2 bucket. With naming convention to differentiate between tenants, modules, time, etc.

## 8. Development Conventions

### Build & Run Commands
Host has Node/npm; PHP runs inside Docker Compose (image includes `pdo_pgsql`, `redis`, Composer). Do **not** use bare `composer:latest` for artisan — that image lacks DB/Redis extensions.

Stack (see `docker-compose.yml`):
- `app` — `php artisan serve` on `:8000`
- `queue` — `php artisan queue:work` (Redis)
- `postgres` — PostgreSQL 16 (host port **5435** → container 5432; 5432 often taken locally)
- `redis` — Redis 7 (host port **6381** → container 6379)

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
docker compose up -d          # app + queue + postgres + redis
npm run dev                   # Vite on host → http://localhost:8000

# Host tools (psql/redis-cli): localhost:5435 / localhost:6381
# PHP always via docker — .env uses postgres/redis service names.
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

- Before adding a new module or service, state which category it falls into (Core / Vertical / Microservice) and why, per Section 2 and Section 5.
- When a task could reasonably be solved either inside the monolith or as a separate service, default to the monolith and flag the tradeoff rather than silently extracting a service.
- When touching multi-tenant data paths, double-check tenant scoping is present — this is a recurring risk area.
- Prefer the customization ladder in §2 / `ARCHITECTURE.md` (consts → serials → custom fields → logic) over tenant_id branches.
- Reference `resources/DESIGN.md` before building any new UI — compose from `resources/js/Components/` (StatusBadge, DataTable Status Rail, Panel, StatCard, PrimaryButton). Do not invent ad hoc gray/indigo chrome.
- Since this is a commercial SaaS product, when proposing a feature or implementation approach, briefly note if there's a simpler version that would still be sellable (MVP bias), especially for the Legal module which is closest to revenue.

## 11. Open Items to Fill In As the Project Grows

- [x] API contract (Web): **Inertia.js**. Controllers → Services → `Inertia::render`. REST only later for mobile/external; same Services.
- [x] Auth strategy (session + login-bound tenancy; Sanctum reserved for future token clients)
- [ ] Billing/subscription module ownership (Core vs. separate service) — plan string exists; payment provider not yet
- [ ] CI/CD pipeline and deployment process for the Ubuntu VPS
- [ ] Per-tenant infrastructure limits/monitoring approach
- [x] `DESIGN.md` — design tokens, component inventory (`resources/DESIGN.md`; tokens in `app.css` / Tailwind)
- [x] Plan/module feature flags (`tenants.plan` + `config/tenant_modules.php` + `module:` middleware)
- [x] In-tenant menu trustee enforcement (`menu.perm:` middleware)
- [x] Custom fields + custom logic + serials (`ARCHITECTURE.md`; field-defs admin UI still open)

