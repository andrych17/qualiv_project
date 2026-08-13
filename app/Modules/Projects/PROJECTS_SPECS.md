# Projects Module
## Core Shared Module — Project Management, Issue Tracking, Kanban Board, Attachments & Comments

# 1. Backgrounds

> Pain point and business value.

Every organization manages internal and client-facing work items — client deliverables, system rollouts, software bugs, operational improvements, and compliance audits:

- Without a centralized project and issue tracking system, task allocation lives in unstructured emails, chat messages, or external spreadsheets.
- Team leads lack real-time visibility into project backlog status, workload distribution across team members, and overdue deliverables.
- Issues related to client accounts, legal matters, or inventory audits end up disconnected from the core ERP platform, leading to duplicated effort and missing historical context.
- Standalone project management tools require separate user licensing, lack multi-tenant data isolation, and cannot seamlessly integrate with ERP shared services (Users, Custom Fields, Notifications, Document Management).

**Client requirements:**
- **Unified Project Registry**: Manage projects with tenant-wide unique codes, name, description, lead owner, start/end dates, and status lifecycle.
- **Interactive Kanban Board**: Visual status columns (To Do, In Progress, Done) with HTML5 drag-and-drop status transitions, Jira-style quick assignee assignment, priority indicators, and overdue due-date alerts.
- **Dual View Support**: Switch between visual Kanban Board and structured List view (DataTable grouped by status with subtotal count footers).
- **Issue Backlog Management**: Track work items categorized by type (Task, Bug, Story), priority (Low, Medium, High, Urgent), assignee, and due date.
- **Attachments & Comments**: Support file attachments and threaded comments per issue to maintain a complete audit trail.
- **Multi-Tenant Schema Isolation**: All project data lives under the tenant-scoped `PROJECTS` PostgreSQL schema (`PROJECTS.projects`, `PROJECTS.issues`, `PROJECTS.issue_comments`, `PROJECTS.issue_attachments`).
- **Custom Fields & Extensibility**: Support tenant-specific custom fields via `CUSTOMFIELDS` without core database migrations.

---

# 2. Goals

> Designated features solving the Backgrounds above.

- **Project Master Registry**: Tenant-scoped CRUD for Projects with automated UUID generation, sequence-based issue key prefixing, and assignment of project leads from the user directory.
- **Kanban Board Engine (`Show.vue`)**: Full-featured Vue 3 / HTML5 drag-and-drop board supporting:
  - Drag-and-drop issue status transitions (`todo` → `in_progress` → `done`).
  - Quick-assign searchable combobox (`FormSearchableSelect`) directly on issue cards.
  - Priority badge indicators (Low, Medium, High, Urgent) and attachment indicators (`Paperclip`).
  - Overdue due-date visual indicators with automatic sorting (overdue items surface first).
- **List View & DataTable Subtotals**: Alternative table view with client-side sorting, code/title search, and status grouping showing task counts per column.
- **Issue Detail & Activity Log**: Manage issue lifecycle, descriptions, due dates, assignee changes, file uploads, and comment history.
- **Multi-tenant Security**: All models inherit tenant schema scoping (`PROJECTS` schema) and strict user permissions.

---

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Projects Index (`Projects/Index.vue`)

**Function / Features**
- Overview table of all tenant projects.
- Search by project code or name.
- Filter by status (`planning`, `active`, `on_hold`, `completed`, `cancelled`).
- Paginated table showing project code, name, lead name, issue count, creation date, and action links (View Board, Edit, Delete).
- Bulk deletion support for selected projects.

**Rules / Logic**
- Server-side pagination via `TableQuery::applySort`.
- Issue count computed via `withCount('issues')`.

## 3B. Project Entry & Edit (`Projects/Create.vue` & `Edit.vue`)

**Fields**
- `code`: Short unique project code (e.g. `PRJ-001`, `WEBSITE`).
- `name`: Full project title.
- `description`: Text description / project scope.
- `lead_id`: Selectable project lead from active user directory (`FormSearchableSelect`).
- `status`: Project state (`planning`, `active`, `on_hold`, `completed`, `cancelled`).
- `start_date` & `end_date`: Optional project timeline bounds.

**Rules / Logic**
- Auto-generates `uuid` on creation if not provided.
- Validates code uniqueness within the tenant schema.

## 3C. Project Board & Kanban (`Projects/Show.vue`)

**Layout & Views**
- **Tabs / View Toggle**: Switch between **Board** (Kanban columns) and **List** (DataTable).
- **Header Info**: Displays project code, name, lead, date range, and quick stats.
- **Quick Task Bar**: Single-row form to create a new task directly into `To Do` column with title, type, priority, and assignee.
- **Kanban Columns**:
  - `To Do`
  - `In Progress`
  - `Done`
- **Card Elements**:
  - Issue Code & Title (link to Issue Edit/Detail).
  - Type & Priority badge (colored by urgency).
  - Quick-Assignee dropdown (`FormSearchableSelect` on card).
  - Attachment count (`Paperclip` icon + count).
  - Due date badge (highlighted red when overdue).

**Drag & Drop & State Rules**
- HTML5 `onDragStart` captures issue ID.
- `onDrop` triggers PATCH request to `projects.issues.updateStatus` with `preserveScroll: true`.
- Changing assignee on card triggers PATCH request to `projects.issues.updateAssignee` with `preserveScroll: true`.
- Overdue items (`due_date < today` and `status != done`) display a prominent warning badge and sort to top of column.

## 3D. Issue Management (`Projects/Issues/Edit.vue`)

**Fields & Schema**
- `code`: Sequence-based issue code (e.g., `PRJ-001-1`, `PRJ-001-2`).
- `title`: Short task summary.
- `type`: `task`, `bug`, `story`.
- `status`: `todo`, `in_progress`, `done`.
- `priority`: `low`, `medium`, `high`, `urgent`.
- `assignee_id`: User ID of assigned team member.
- `due_date`: Date by which issue must be completed.
- `description`: Full task details / Markdown.
- **Attachments**: File upload support (`PROJECTS.issue_attachments`).
- **Comments**: Threaded discussion (`PROJECTS.issue_comments`).

---

# 4. Storage

> Tables and schema layout under tenant `PROJECTS` PostgreSQL schema.

### Tables
1. `PROJECTS.projects`
   - `id`: `bigserial primary key`
   - `uuid`: `uuid not null`
   - `code`: `varchar(50) not null`
   - `name`: `varchar(255) not null`
   - `description`: `text nullable`
   - `status`: `varchar(30) default 'active'`
   - `lead_id`: `bigint nullable references USERS(id)`
   - `start_date`: `date nullable`
   - `end_date`: `date nullable`
   - `next_issue_seq`: `integer default 1` — deliberately **not** routed through
     `SYSCONFIG.config_snums` (customization ladder rung 2). `config_snums` is one row per
     fixed, known `snum_code` — a good fit for a small set of tenant/module-wide counters
     (e.g. `LEGAL_CASE_LASTID`), but a poor one here: every project needs its own independent
     issue counter, and project codes are user-defined at creation time, not a fixed set.
     Routing this through `config_snums` would mean writing a new `SYSCONFIG` row from
     `PROJECTS` on every project creation. Same carve-out already established for
     `LEGAL.protocol_entries.sequence_number` (`SYSCONFIG_SPECS.md` §3D: "not a replacement
     for a module's own composite-scoped ledger numbering... `config_snums` is for simple
     tenant/module-wide running numbers only") — a per-row, per-parent counter correctly stays
     local to its own table, locked on its own row (`IssueService::create()`,
     `lockForUpdate()`), not routed through the generic engine.
   - `created_at`, `updated_at`: `timestamps`

2. `PROJECTS.issues`
   - `id`: `bigserial primary key`
   - `project_id`: `bigint not null references PROJECTS.projects(id) on delete cascade`
   - `code`: `varchar(60) not null`
   - `title`: `varchar(255) not null`
   - `type`: `varchar(30) default 'task'`
   - `status`: `varchar(30) default 'todo'`
   - `priority`: `varchar(30) default 'medium'`
   - `assignee_id`: `bigint nullable references USERS(id)`
   - `due_date`: `date nullable`
   - `description`: `text nullable`
   - `created_at`, `updated_at`: `timestamps`

3. `PROJECTS.issue_comments`
   - `id`: `bigserial primary key`
   - `issue_id`: `bigint not null references PROJECTS.issues(id) on delete cascade`
   - `user_id`: `bigint not null references USERS(id)`
   - `comment`: `text not null`
   - `created_at`, `updated_at`: `timestamps`

4. `PROJECTS.issue_attachments`
   - `id`: `bigserial primary key`
   - `issue_id`: `bigint not null references PROJECTS.issues(id) on delete cascade`
   - `file_path`: `varchar(255) not null`
   - `file_name`: `varchar(255) not null`
   - `file_size`: `bigint not null`
   - `created_at`, `updated_at`: `timestamps`

---

# 5. Technical Notes

- **Frontend Tech Stack**: Vue 3 (Options/Composition API with `<script setup lang="ts">`), Inertia.js, Tailwind CSS, Lucide icons (`Paperclip`).
- **Drag & Drop Implementation**: Pure HTML5 drag/drop API (`dragstart`, `dragover`, `drop`) without heavy external NPM dependencies.
- **Optimistic UI & Scroll Preservation**: All status and assignee updates use Inertia's `preserveScroll: true` to prevent scroll jumping on board updates.
- **Tenant Isolation**: Schema-based multi-tenancy (`PROJECTS.*`) automatically isolated per tenant database.
- **Custom Fields**: Compatible with `CUSTOMFIELDS` registry for per-tenant field extensions on projects and issues.
