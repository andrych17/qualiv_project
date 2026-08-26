# AGENTS.md

Universal guidance for AI coding agents (Antigravity, Claude Code, Cursor, Roo Code, Codex, OpenCode, and others) working in the Nusaevo ERP repository.

---

## 1. Project Overview & Architecture

A **multi-tenant SaaS ERP platform**, architected as a **modular monolith** (Odoo-like) with vertical/niche editions rented to specific industries.
- **First vertical to ship and rent: Legal.**
- Built and maintained by a single developer — architecture choices must strictly optimize for: low operational overhead, clean module boundaries, and high reusability across verticals without duplicating core logic.
- **Web Interface:** Laravel 11/12 + Inertia.js + Vue 3 (Composition API, `<script setup lang="ts">`, Tailwind CSS). Controllers return `Inertia::render(...)`. Do **not** build REST/GraphQL endpoints for web pages.
- **Business Logic:** Always lives in Service classes (`app/Modules/<Module>/Services/`), never in controllers or Vue components.

---

## 2. Multi-Tenancy & Database Architecture

- **Isolation Strategy:** Mode B — **one PostgreSQL database per tenant** (`tenant_{id}`) managed via `stancl/tenancy`.
- **Central DB (`nusaevo`):** Holds tenant registry, user lookup (`tenant_user_lookups`), and tenant plan entitlement (`tenants.plan`).
- **Tenant DB Schemas:** Modules have isolated schemas inside each tenant database:
  `SYSCONFIG`, `WNE`, `DMS`, `CRM`, `SCHEDULE`, `INVENTORY`, `ACCOUNTING`, `SALES`, `PURCHASE`, `HCM`, `PAYROLL`, `PERF`, `PROJECTS`, `AIINSIGHT`, `CUSTOMFIELDS`.
- **Customization Ladder (No `tenant_id` branches):**
  1. Constants (`SYSCONFIG.config_consts`)
  2. Serials (`SYSCONFIG.config_snums`)
  3. Custom Fields (`CUSTOMFIELDS.field_defs` / `field_values`)
  4. Custom Logic (Services reading consts / strategy pattern)
  5. Plans / Modules (`tenants.plan` + `config/tenant_modules.php`)
  6. Vertical Modules (`app/Modules/Legal`, etc.)
  - **Anti-pattern:** `if ($tenantId === '001')`. **Correct:** Seed distinct configurations/custom fields per tenant.

---

## 3. Strict Frontend Component Standards (MANDATORY)

Every UI view in `resources/js/Pages/` **must strictly compose from shared components** in `resources/js/Components/`.
**NEVER create ad-hoc UI primitives, unstyled inputs, or raw HTML overlays.**

### A. Modals & Dialogs (STRICT)
- **MUST** use `@/Components/Modal.vue`:
  ```vue
  <Modal :show="showModal" max-width="md" @close="showModal = false">
    <div class="p-6 bg-white rounded-lg">
      <!-- Content here -->
    </div>
  </Modal>
  ```
- **NEVER** write inline overlays like `<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50...">`.
- **Modal Inner Card:** Must always have an opaque background (`bg-white` or `bg-surface rounded-lg p-6`). Never leave the modal card transparent.
- **Confirmation Prompts:** For delete/confirmation dialogs, **MUST** use `@/Components/modals/ConfirmDialog.vue` triggered via `useConfirm()` composable:
  ```ts
  import { useConfirm } from '@/Composables/useConfirmDialog'
  const { confirm } = useConfirm()

  confirm({
    title: 'Delete Item',
    description: 'Are you sure you want to delete this record?',
    variant: 'destructive',
    onConfirm: () => form.delete(route('item.destroy', item.id)),
  })
  ```
  **NEVER** use browser-native `window.confirm()` or alert popups.

### B. Form Controls & Inputs (`@/Components/forms/`)
- **Text, Email, Number, Date:** `FormInput.vue` (includes label, required asterisk, and error message).
- **Multi-line Text:** `FormTextarea.vue`.
- **Standard Select:** `FormSelect.vue`.
- **Single Searchable Select (In-memory):** `FormSearchableSelect.vue`.
- **Async Remote Searchable Select:** `FormAsyncSearchableSelect.vue` (for large datasets: partners, products, users).
- **Multi-Select Tags/Pills:** `FormMultiSelect.vue`:
  ```vue
  <FormMultiSelect
    v-model="form.selected_ids"
    name="selected_ids"
    label="Assignees"
    placeholder="Select items..."
    :options="optionsList"
    :error="form.errors.selected_ids"
  />
  ```
  **NEVER** build custom loops of checkbox grids for multi-entity selection.
- **Boolean Toggle:** `FormSwitch.vue`.
- **Radio Options:** `FormRadioGroup.vue`.
- **Dynamic Custom Fields:** `CustomFieldInputs.vue` (for EAV fields from `CUSTOMFIELDS`).

### C. Buttons (`@/Components/`)
- **Primary CTA:** `PrimaryButton.vue` (supports `:href` or submit action, loading spinner).
- **Secondary / Cancel:** `SecondaryButton.vue`.
- **Destructive / Delete:** `DangerButton.vue`.
- **NEVER** write raw `<button>` elements with ad-hoc color classes.

### D. Tables & Lists (`@/Components/tables/`)
- **MUST** use `DataTable.vue` (`@/Components/tables/DataTable.vue`).
- Built-in support for server-side pagination, sorting, search filter toolbar, row status rail, expandable rows, and groupBy outline subtotals.

### E. Cards & Layout Containers (`@/Components/cards/`)
- **Panel:** `Panel.vue` (card container with header title, action slot, footer slot, and optional Status Rail).
- **KPI Metrics:** `StatCard.vue` (metric cards with Source Serif 4 typography).

### F. Feedback & Status Badges (`@/Components/feedback/`)
- **Status Badge:** `StatusBadge.vue` with semantic tokens (`variant="success|warning|danger|info|neutral"`).
- **Flash Messages:** `Toast.vue` (triggered via flash session props).

### G. Layout & Headers
- **Page Layout Shell:** `AppLayout.vue` (`@/Components/layout/AppLayout.vue`).
- **Page Header:** `PageHeader.vue` (`@/Components/layout/PageHeader.vue`) for title, subtitle, and action buttons.
- **Sub-navigation Tabs:** `Tabs.vue` (`@/Components/navigation/Tabs.vue`).
- **Module Sub-navigation:** Reusable module subnavs (e.g. `HcmSubNav.vue`, `CrmSubNav.vue`, `InventorySubNav.vue`).

---

## 4. Backend Conventions

- **Directory Structure:**
  - `app/Modules/<ModuleName>/`: `Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`, `Enums/`, `Routes/web.php`.
  - `app/Shared/`: `Actions/`, `DTOs/`, `Enums/`, `Services/`, `Traits/`, `Helpers/`.
- **Controllers:** Keep controllers thin. Validate with Form Requests, execute via Services, and return `Inertia::render(...)`.
- **Database Tables:**
  - Master tables: single noun, e.g. `materials`, `partners`.
  - Transaction tables: 2-part `<SCHEMA>.<prefix>_<level>`, e.g. `SALES.so_hdrs`, `SALES.so_lines`, `PURCHASE.pur_order_hdrs`.
  - Use `bigint` for PK/FK. Add `uuid` for external facing references.

---

## 5. Development Commands

PHP runs inside Docker Compose; host runs Node/npm:

```bash
# Start containers
docker compose up -d

# Frontend dev server (on host)
npm run dev

# Frontend build & TypeScript check
npm run build

# Run migrations & seeders
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed

# PHP Linting (Laravel Pint)
docker compose exec app ./vendor/bin/pint

# Run Tests
docker compose exec app php artisan test
```

---

## 6. Global Agent Rules

1. **NEVER use `git push --force`, `git push -f`, or any form of force push.**
2. **graphify — Codebase Navigation:**
   - DO NOT start with `grep` / `grep_search` / ripgrep to explore code, flow, architecture, or bug tracing.
   - ALWAYS check `graphify-out/graph.json` first: run `graphify query "..."`, `graphify path A B`, or `graphify explain "..."`.
   - Use `graphify-out/wiki/index.md` for navigation.
   - Run `graphify update .` after code edits.
