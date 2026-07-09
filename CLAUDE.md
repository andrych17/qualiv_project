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
- Separate Back-end from Front-end, since will be Web/Mobile/Tablet option. Start with Web edition.

### Use existing UI components extensively, and if not found one, create.

## 3. Tech Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 11/12 |
| Frontend | Vue 3 (Inertia.js, Vite, Tailwind CSS, Lucide Icons) |
| Database | PostgreSQL |
| Cache / Queue broker | Redis |
| Web server | Nginx |
| Hosting | Ubuntu VPS |
| Containerization | Docker (where it simplifies deployment/consistency — not mandatory everywhere) |
| Design tooling | Claude Design / Google Stitch for UI exploration, see `DESIGN.md` for the resulting system |
| Primary coding agent | Claude Code |

Notes:
- Laravel handles core business logic, auth, multi-tenancy, module orchestration, background jobs (via Redis queues).
- Vue.js is the primary UI — treat it as a client of Laravel's API (REST or a thin GraphQL/RPC layer, whichever is decided per module), not a place to duplicate business rules.
- Any microservice added later should default to whatever language fits the job best; don't force everything into PHP if it's the wrong tool (e.g. a Python service for document parsing/OCR is fine).

## 4. Multi-Tenancy

- This is a multi-tenant SaaS. Every core module must be tenant-aware from day one (tenant_id scoping on all tables, global scopes in Eloquent, tenant-aware queue jobs and cache keys).
- Avoid designing anything (routes, jobs, cache, storage paths) as if there will only ever be one tenant.
- Plan/feature-flagging (which modules a tenant has access to) should be a first-class concept in Core, since verticals are sold as bundles.

## 5. Build Order

1. **Design system / component library first** — build base UI components and widgets per `DESIGN.md` before building feature screens. Every module UI should be composed from this shared library, not one-off styled per module.
2. **Core modules** (shared by every vertical):
   - Scheduler
   - Notifications
   - Workflows (approvals, state machines, task routing)
   - CRM
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

## 7. Working with Claude Code

- Before adding a new module or service, state which category it falls into (Core / Vertical / Microservice) and why, per Section 2 and Section 5.
- When a task could reasonably be solved either inside the monolith or as a separate service, default to the monolith and flag the tradeoff rather than silently extracting a service.
- When touching multi-tenant data paths, double-check tenant scoping is present — this is a recurring risk area.
- Reference `DESIGN.md` before building any new UI component to avoid inventing a parallel design language.
- Since this is a commercial SaaS product, when proposing a feature or implementation approach, briefly note if there's a simpler version that would still be sellable (MVP bias), especially for the Legal module which is closest to revenue.

## 8. Open Items to Fill In As the Project Grows

- [x] API contract style between Laravel and Vue.js (REST/OpenAPI vs. other)
- [ ] Auth strategy (Sanctum/Passport, SSO plans)
- [x] Billing/subscription module ownership (Core vs. separate service)
- [ ] CI/CD pipeline and deployment process for the Ubuntu VPS
- [ ] Per-tenant infrastructure limits/monitoring approach
- [x] `DESIGN.md` — design tokens, component inventory, and design principles (to be created alongside the first components)

## 9. Development

## Build & Run Commands

As the local host does not have PHP/Composer installed globally, all PHP and artisan commands must be run via Docker using `composer:latest`. Local Node.js / NPM commands can be run directly on the host.

### Local Development Setup
- **Install PHP dependencies**: `docker run --rm -v $(pwd):/app -w /app composer:latest composer install`
- **Install Node dependencies**: `npm install`
- **Run Vite dev server**: `npm run dev`
- **Run Laravel dev server**: `docker run --name nusaevo-web --rm -p 8000:8000 -v $(pwd):/app -w /app composer:latest php artisan serve --host=0.0.0.0`
- **Run DB migrations**: `docker run --rm -v $(pwd):/app -w /app composer:latest php artisan migrate`
- **Run DB seeders**: `docker run --rm -v $(pwd):/app -w /app composer:latest php artisan db:seed`
- **Fresh migration & seed**: `docker run --rm -v $(pwd):/app -w /app composer:latest php artisan migrate:fresh --seed`

### Build Production Assets
- **Vite production build**: `npm run build`

### Code Quality & Formatting
- **PHP Linting (Laravel Pint)**: `docker run --rm -v $(pwd):/app -w /app composer:latest ./vendor/bin/pint`
- **TypeScript Checking**: `npm run build` (runs `vue-tsc`)

### Running Tests
- **Run PHPUnit tests**: `docker run --rm -v $(pwd):/app -w /app composer:latest php artisan test`

---
 
## 10. Codebase Guidelines & Conventions

### 1. Modular Monolith Architecture
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

### 2. Frontend Page Structure
- Vue pages live in `resources/js/Pages/<ModuleName>/Items/` (e.g. `Index.vue`, `Create.vue`, `Edit.vue`).
- Shared frontend layouts, navigation, forms, and table components live in `resources/js/Components/` (`layout/`, `navigation/`, `forms/`, `tables/`, `filters/`, `modals/`, `feedback/`).

### 3. Coding Conventions
- **Controllers**: Keep controllers thin. Validate requests using Form Requests, delegate execution to Service classes, and return Inertia responses.
- **TypeScript**: Use strict TypeScript in Vue files. Explicitly define types and interfaces for backend-passed props.
- **Tailwind CSS**: Use utility classes directly for layouts and UI styling. Maintain clean structure and consistent spacing.
- **Lucide Icons**: Render Lucide icons dynamically in layouts and sidebars using the `<component :is="..." />` helper.