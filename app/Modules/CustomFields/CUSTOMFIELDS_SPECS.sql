-- =============================================================================
-- CustomFields module — schema + example seed data
-- Source spec: app/Modules/CustomFields/CUSTOMFIELDS_SPECS.md (see that file for
-- full rationale) — also see ARCHITECTURE.md §1.2/§2.3/§2.4 for the original
-- working example (Legal's court_register/hearing_date/priority) this spec
-- formalizes.
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "CUSTOMFIELDS" —
--              every table lives inside one tenant's own database (CLAUDE.md
--              §4/§7A), no tenant_id column anywhere.
--
-- UNLIKE the other *_SPECS.sql files in this repo, `field_defs` and
-- `field_values` are NOT greenfield here — they already exist as a real Laravel
-- migration: database/migrations/tenant/2026_07_17_150001_create_custom_fields_tables.php.
-- The CREATE TABLE statements below mirror that live schema exactly (same
-- column names/types/nullability, including its Laravel-managed nullable
-- `timestamp` columns with no DB-level default). Two things the spec calls for
-- (§3A, §3H) are NOT yet in that migration:
--   1. `field_defs.module_code` — added below via ALTER TABLE ADD COLUMN IF NOT
--      EXISTS, so this script is safe to run against a tenant DB that already
--      has the real migration applied (mirrors how CENTRAL_SPECS.sql extends
--      the already-existing `tenants` table).
--   2. `field_def_audit_logs` (§3H) — entirely new, created below.
-- If/when this script's additions get promoted to a real migration, write them
-- as their own migration file — never edit the migration that's already run
-- (CLAUDE.md §6).
--
-- `field_values.field_def_id` and `.entity_id` are deliberately NOT foreign
-- keys — "app-enforced link to def" per §4 and ARCHITECTURE.md §1.2, since
-- entity_id's target table varies by entity_type. This mirrors the same
-- unenforced-reference discipline used for subject_type/subject_id pointers
-- everywhere else in the platform, even though it looks, at first glance, like
-- it should just be a normal FK to a table in the very same schema.
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"AIINSIGHT"/"ACCOUNTING"/"CRM".
CREATE SCHEMA IF NOT EXISTS "CUSTOMFIELDS";

-- -----------------------------------------------------------------------------
-- §3A / §4. field_defs — what fields exist per entity (mirrors the live
-- migration; TIMESTAMP columns nullable with no DB default, matching Laravel's
-- own $table->timestamps() convention rather than this repo's newer TIMESTAMPTZ
-- NOT NULL DEFAULT now() convention used in the other *_SPECS.sql files).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CUSTOMFIELDS".field_defs (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL UNIQUE,               -- external-facing (CLAUDE.md §7A)
    entity_type     VARCHAR(50) NOT NULL,                -- e.g. legal_case, inventory_item — a registration key, not a FK (§5)
    code            VARCHAR(50) NOT NULL,                -- stable key, e.g. court_register
    label           VARCHAR(255) NOT NULL,
    field_type      VARCHAR(20) NOT NULL CHECK (field_type IN ('text', 'number', 'date', 'select')),
    options         JSON,                                 -- select only: [{label, value}]
    is_required     BOOLEAN NOT NULL DEFAULT FALSE,
    seq             INTEGER NOT NULL DEFAULT 0 CHECK (seq >= 0), -- form order
    status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at      TIMESTAMP(0),
    updated_at      TIMESTAMP(0),
    UNIQUE (entity_type, code)
);

-- §3A / §4: module_code — nullable admin filter/scope only, same code space as
-- SYSCONFIG.tenant_modules.module_code. Not present in the original migration.
ALTER TABLE "CUSTOMFIELDS".field_defs
    ADD COLUMN IF NOT EXISTS module_code VARCHAR(50);

CREATE INDEX IF NOT EXISTS idx_customfields_field_defs_module ON "CUSTOMFIELDS".field_defs (module_code, entity_type);

-- -----------------------------------------------------------------------------
-- §4. field_values — values per entity row (mirrors the live migration exactly)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CUSTOMFIELDS".field_values (
    id              BIGSERIAL PRIMARY KEY,
    field_def_id    BIGINT NOT NULL,                     -- app-enforced link to field_defs.id, deliberately not a FK (see header)
    entity_type     VARCHAR(50) NOT NULL,
    entity_id       BIGINT NOT NULL,                     -- the owning row's PK in whatever table entity_type resolves to; not a FK
    value           TEXT,                                 -- all types stored as text; cast per field_type in the service layer
    created_at      TIMESTAMP(0),
    updated_at      TIMESTAMP(0),
    UNIQUE (field_def_id, entity_type, entity_id)
);

CREATE INDEX IF NOT EXISTS idx_customfields_field_values_entity ON "CUSTOMFIELDS".field_values (entity_type, entity_id);

-- -----------------------------------------------------------------------------
-- §3H / §4. field_def_audit_logs — append-only, no update/delete at app layer,
-- same immutable-audit posture as dms.access_logs / wne.wrkflow_audit_logs /
-- acct.audit_logs / sysconfig.config_audit_logs. NEW table, not yet migrated.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CUSTOMFIELDS".field_def_audit_logs (
    id              BIGSERIAL PRIMARY KEY,
    field_def_id    BIGINT NOT NULL REFERENCES "CUSTOMFIELDS".field_defs (id), -- a real FK is safe here: defs are never hard-deleted (§3A rule)
    action          VARCHAR(15) NOT NULL CHECK (action IN ('created', 'updated', 'deactivated')),
    actor_id        BIGINT REFERENCES users (id),
    before_snapshot JSONB,
    after_snapshot  JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_customfields_field_def_audit_logs_def ON "CUSTOMFIELDS".field_def_audit_logs (field_def_id, created_at);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Reuses ARCHITECTURE.md §1.4's own worked example almost verbatim: Legal's
-- court_register/hearing_date/priority custom fields for entity_type =
-- 'legal_case' (Firm A flavor: court_register + priority required, priority =
-- urgent), plus a second entity_type (inventory_item) to demonstrate this is a
-- genuinely cross-module, data-driven mechanism, not Legal-specific code.
--
-- field_values.entity_id below does NOT need a real LEGAL.cases row to exist —
-- that FK is deliberately unenforced (see header) — but for a real tenant it
-- would correspond to an actual LEGAL.cases.id.
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com). Written with plain
-- subqueries and data-modifying CTEs only — no psql \gset — so it runs through
-- any Postgres client.
-- =============================================================================

BEGIN;

INSERT INTO "CUSTOMFIELDS".field_defs (uuid, entity_type, module_code, code, label, field_type, options, is_required, seq, status) VALUES
    (gen_random_uuid(), 'legal_case', 'LEGAL', 'court_register', 'Court Register', 'text', NULL, TRUE, 10, 'active'),
    (gen_random_uuid(), 'legal_case', 'LEGAL', 'hearing_date', 'Hearing Date', 'date', NULL, FALSE, 20, 'active'),
    (gen_random_uuid(), 'legal_case', 'LEGAL', 'priority', 'Priority', 'select',
        '[{"label": "Normal", "value": "normal"}, {"label": "Urgent", "value": "urgent"}]', TRUE, 30, 'active'),
    (gen_random_uuid(), 'inventory_item', 'INVENTORY', 'fragile', 'Fragile', 'select',
        '[{"label": "Yes", "value": "yes"}, {"label": "No", "value": "no"}]', FALSE, 10, 'active');

-- Example values for a Firm-A-flavored Legal case (entity_id = 1), matching
-- ARCHITECTURE.md §1.4's demo seed (court_register/priority required, priority
-- = urgent, which — combined with LEGAL.URGENT_SETS_PENDING — is what makes
-- CustomLogicEngine::beforeSave force status = pending on that case).
INSERT INTO "CUSTOMFIELDS".field_values (field_def_id, entity_type, entity_id, value)
SELECT id, 'legal_case', 1, 'PN.JKT.PST/123/2026' FROM "CUSTOMFIELDS".field_defs WHERE entity_type = 'legal_case' AND code = 'court_register'
UNION ALL
SELECT id, 'legal_case', 1, '2026-09-15' FROM "CUSTOMFIELDS".field_defs WHERE entity_type = 'legal_case' AND code = 'hearing_date'
UNION ALL
SELECT id, 'legal_case', 1, 'urgent' FROM "CUSTOMFIELDS".field_defs WHERE entity_type = 'legal_case' AND code = 'priority';

-- Audit trail: one 'created' entry per def just seeded above
INSERT INTO "CUSTOMFIELDS".field_def_audit_logs (field_def_id, action, actor_id, after_snapshot)
SELECT fd.id, 'created',
       (SELECT id FROM users WHERE email = 'admin@nusaevo.com'),
       jsonb_build_object('entity_type', fd.entity_type, 'code', fd.code, 'field_type', fd.field_type, 'is_required', fd.is_required)
FROM "CUSTOMFIELDS".field_defs fd;

COMMIT;
