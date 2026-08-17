-- =============================================================================
-- Projects module — schema + example seed data
-- Source spec: app/Modules/Projects/PROJECTS_SPECS.md (see that file for full
-- rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), no tenant_id column anywhere. Per
--              CLAUDE.md §5 and the real migration's own comment, this module
--              is gated to Nusaevo's own internal tenant via the 'internal'
--              plan (config/tenant_modules.php), not any sellable tier — it's
--              the team's own Jira-style board, not a customer-facing feature.
--
-- UNLIKE most other *_SPECS.sql files in this repo, this module already has
-- real migrations:
--   database/migrations/tenant/2026_08_01_150000_create_projects_schema_table.php
--   database/migrations/tenant/2026_08_01_150001_create_projects_projects_table.php
--   database/migrations/tenant/2026_08_01_150002_create_projects_issues_table.php
--   database/migrations/tenant/2026_08_01_150003_create_projects_issue_comments_table.php
--   database/migrations/tenant/2026_08_01_150004_create_projects_issue_attachments_table.php
--   database/migrations/tenant/2026_08_01_150005_add_dates_to_projects_table.php
-- The CREATE TABLE statements below mirror that LIVE schema exactly, not
-- PROJECTS_SPECS.md's own §4 storage list, which has drifted from what was
-- actually implemented:
--   - `projects.code` is VARCHAR(20) unique (§4 says varchar(50)).
--   - `issues.code` is VARCHAR(30) unique (§4 says varchar(60)).
--   - `issues` has an extra `reporter_id` column (nullable, FK users) that §4
--     doesn't mention at all.
--   - `issue_comments.user_id` is NULLABLE with ON DELETE SET NULL, not NOT
--     NULL as §4 states, and the text column is named `body`, not `comment`.
--   - `issue_attachments`'s real shape (`uuid`, `user_id`, `original_name`,
--     `storage_key`, `mime_type`, `size`) is substantially different from
--     §4's `file_path`/`file_name`/`file_size` — mirrored the real one.
-- If/when PROJECTS_SPECS.md itself gets corrected to match, this script
-- should be updated alongside it — same gap-check role CUSTOMFIELDS_SPECS.sql
-- plays for that module's own already-built schema.
--
-- CHECK constraints on status/type/priority ARE added below even though the
-- real migrations don't have them (Laravel's string() calls carry no DB-level
-- check) — a reasonable quality addition consistent with this platform's
-- stated "VARCHAR + CHECK, not a native enum" convention, using exactly the
-- value sets PROJECTS_SPECS.md §3B/§3D enumerate.
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted only for consistency with the other *_SPECS.sql files in this repo —
-- "PROJECTS" as a bare identifier already folds to the same lowercase name a
-- quoted one would, but the real migration (2026_08_01_150000_...) explicitly
-- notes unquoted CREATE SCHEMA folds to lowercase while every table reference
-- stays quoted, so quoting here keeps this script consistent with that.
CREATE SCHEMA IF NOT EXISTS "PROJECTS";

CREATE TABLE IF NOT EXISTS "PROJECTS".projects (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL UNIQUE,
    code                VARCHAR(20) NOT NULL UNIQUE,
    name                VARCHAR(255) NOT NULL,
    description         TEXT,
    status              VARCHAR(30) NOT NULL DEFAULT 'active'
                            CHECK (status IN ('planning', 'active', 'on_hold', 'completed', 'cancelled')),
    lead_id             BIGINT REFERENCES users (id) ON DELETE SET NULL,
    next_issue_seq      INTEGER NOT NULL DEFAULT 1,       -- per-project issue numbering, incremented under a row lock in IssueService::create()
    start_date          DATE,
    end_date            DATE,
    created_at          TIMESTAMP(0),
    updated_at          TIMESTAMP(0)
);

CREATE TABLE IF NOT EXISTS "PROJECTS".issues (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL UNIQUE,
    project_id          BIGINT NOT NULL REFERENCES "PROJECTS".projects (id) ON DELETE CASCADE,
    code                VARCHAR(30) NOT NULL UNIQUE,      -- e.g. NUSA-1, NUSA-2 (project.code + next_issue_seq)
    title               VARCHAR(255) NOT NULL,
    description         TEXT,
    type                VARCHAR(20) NOT NULL DEFAULT 'task' CHECK (type IN ('task', 'bug', 'story')),
    status              VARCHAR(30) NOT NULL DEFAULT 'todo' CHECK (status IN ('todo', 'in_progress', 'done')),
    priority            VARCHAR(20) NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high', 'urgent')),
    assignee_id         BIGINT REFERENCES users (id) ON DELETE SET NULL,
    reporter_id         BIGINT REFERENCES users (id) ON DELETE SET NULL,
    due_date            DATE,
    created_at          TIMESTAMP(0),
    updated_at          TIMESTAMP(0)
);

CREATE INDEX IF NOT EXISTS idx_projects_issues_project_status ON "PROJECTS".issues (project_id, status);
CREATE INDEX IF NOT EXISTS idx_projects_issues_assignee ON "PROJECTS".issues (assignee_id);
CREATE INDEX IF NOT EXISTS idx_projects_issues_due_date ON "PROJECTS".issues (due_date) WHERE status <> 'done';

CREATE TABLE IF NOT EXISTS "PROJECTS".issue_comments (
    id                  BIGSERIAL PRIMARY KEY,
    issue_id            BIGINT NOT NULL REFERENCES "PROJECTS".issues (id) ON DELETE CASCADE,
    user_id             BIGINT REFERENCES users (id) ON DELETE SET NULL,
    body                TEXT NOT NULL,
    created_at          TIMESTAMP(0),
    updated_at          TIMESTAMP(0)
);

CREATE INDEX IF NOT EXISTS idx_projects_issue_comments_issue ON "PROJECTS".issue_comments (issue_id, created_at);

CREATE TABLE IF NOT EXISTS "PROJECTS".issue_attachments (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL UNIQUE,
    issue_id            BIGINT NOT NULL REFERENCES "PROJECTS".issues (id) ON DELETE CASCADE,
    user_id             BIGINT REFERENCES users (id) ON DELETE SET NULL,
    original_name       VARCHAR(255) NOT NULL,
    storage_key         VARCHAR(255) NOT NULL,            -- "{tenant_id}/projects/{project_id}/{issue_id}/{uuid}.{ext}", see IssueService::attachFile()
    mime_type           VARCHAR(255),
    size                BIGINT NOT NULL,
    created_at          TIMESTAMP(0),
    updated_at          TIMESTAMP(0)
);

CREATE INDEX IF NOT EXISTS idx_projects_issue_attachments_issue ON "PROJECTS".issue_attachments (issue_id);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- One internal project with three issues spanning all three Kanban columns, a
-- comment thread on the in-progress issue, and one attachment on it —
-- exercising every table and both FK-nullable-on-delete columns
-- (assignee_id/reporter_id/user_id).
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- — so it runs through any Postgres client.
-- =============================================================================

BEGIN;

INSERT INTO "PROJECTS".projects (uuid, code, name, description, status, lead_id, next_issue_seq, start_date)
SELECT gen_random_uuid(), 'NUSA', 'Nusaevo Platform', 'Internal build tracker for the ERP platform itself.',
       'active', id, 4, '2026-07-01'
FROM users WHERE email = 'admin@nusaevo.com';

INSERT INTO "PROJECTS".issues (uuid, project_id, code, title, description, type, status, priority, assignee_id, reporter_id, due_date)
SELECT gen_random_uuid(), p.id, 'NUSA-1', 'Write CENTRAL_SPECS.sql', 'Reference schema + seed for the CENTRAL module.',
       'task', 'done', 'medium', staff.id, admin.id, DATE '2026-08-10'
FROM "PROJECTS".projects p, users admin, users staff
WHERE p.code = 'NUSA' AND admin.email = 'admin@nusaevo.com' AND staff.email = 'staff@nusaevo.com'
UNION ALL
SELECT gen_random_uuid(), p.id, 'NUSA-2', 'Fix DataTable subtotal footer rounding', 'Group subtotal footer shows Rp0 on the last page.',
       'bug', 'in_progress', 'high', staff.id, admin.id, DATE '2026-08-20'
FROM "PROJECTS".projects p, users admin, users staff
WHERE p.code = 'NUSA' AND admin.email = 'admin@nusaevo.com' AND staff.email = 'staff@nusaevo.com'
UNION ALL
SELECT gen_random_uuid(), p.id, 'NUSA-3', 'Draft Kanban drag-and-drop UX', 'Explore card layout for overdue/priority indicators.',
       'story', 'todo', 'medium', NULL::bigint, admin.id, NULL::date
FROM "PROJECTS".projects p, users admin
WHERE p.code = 'NUSA' AND admin.email = 'admin@nusaevo.com';

INSERT INTO "PROJECTS".issue_comments (issue_id, user_id, body)
SELECT i.id, admin.id, 'Repro: only happens when the last page has fewer than a full page of rows.'
FROM "PROJECTS".issues i, users admin
WHERE i.code = 'NUSA-2' AND admin.email = 'admin@nusaevo.com'
UNION ALL
SELECT i.id, staff.id, 'Found it — subtotal was reading from the wrong page slice. Fix incoming.'
FROM "PROJECTS".issues i, users staff
WHERE i.code = 'NUSA-2' AND staff.email = 'staff@nusaevo.com';

INSERT INTO "PROJECTS".issue_attachments (uuid, issue_id, user_id, original_name, storage_key, mime_type, size)
SELECT gen_random_uuid(), i.id, staff.id, 'subtotal-bug-screenshot.png',
       '001/projects/' || i.project_id || '/' || i.id || '/' || gen_random_uuid() || '.png',
       'image/png', 184320
FROM "PROJECTS".issues i, users staff
WHERE i.code = 'NUSA-2' AND staff.email = 'staff@nusaevo.com';

COMMIT;
