-- =============================================================================
-- SysConfig module — schema + example seed data
-- Source spec: app/Modules/SysConfig/SYSCONFIG_SPECS.md (see that file for
-- full rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), schema "SYSCONFIG", no tenant_id
--              column anywhere.
--
-- THE BASE OF THE DEPENDENCY GRAPH (§5). Unlike every other module scripted
-- so far, SYSCONFIG has ZERO dependency on any other platform module — not
-- even WNE. The only external table referenced anywhere below is Laravel's
-- own `users`. No "CRM".partners, no "DMS".documents, nothing — every other
-- module (including WNE) resolves its own consts/permissions THROUGH this
-- schema, so a reverse dependency would create the exact circularity §5
-- explicitly calls out to avoid.
--
-- One deliberate divergence from every prior *_SPECS.sql's convention: most
-- scripts only leave a comment flagging the NULLS-NOT-DISTINCT caveat on a
-- nullable-column UNIQUE constraint. Here it's actually ENFORCED (Postgres
-- 15+ `UNIQUE NULLS NOT DISTINCT`) on config_consts, because §3B is explicit
-- that "(appl_id, group_id, user_id, const_group, group_code) ... exactly
-- one row per scope combination, no ambiguous duplicates" — and the single
-- most common row shape in this table (a plain module-wide default) has ALL
-- THREE of appl_id/group_id/user_id NULL at once, which a plain UNIQUE
-- constraint would silently let duplicate (Postgres treats NULL <> NULL by
-- default). A commented caveat wouldn't actually satisfy the spec's own
-- stated rule here, so this is the one script where the strict variant earns
-- its keep rather than being a defensive default.
--
-- tenant_modules.module_code's CHECK list is taken verbatim from §3A's own
-- enumeration (WNE, DMS, CRM, SCHEDULE, INVENTORY, ACCOUNTING, PURCHASE,
-- SALES, HCM, PAYROLL, PERFORMANCE, AIINSIGHT, LEGAL) — SYSCONFIG itself,
-- CUSTOMFIELDS, and PROJECTS are deliberately absent from that list (the
-- first two are foundational/always-on per CLAUDE.md §5, and PROJECTS is
-- gated on the 'internal' plan rather than a tenant-facing toggle), so this
-- table only ever governs the modules §3A actually enumerates.
--
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- or other client-specific meta-commands — so it runs through any Postgres
-- client.
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A); unused directly here but kept for convention consistency

CREATE SCHEMA IF NOT EXISTS "SYSCONFIG";

-- -----------------------------------------------------------------------------
-- §3F / §4. Menus, Groups & Access Rights (Trustee) — built first: every
-- other engine in this module (and every other module's permission checks)
-- depends on `groups` existing.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SYSCONFIG".groups (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL UNIQUE,
    description     TEXT,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "SYSCONFIG".group_members (
    id          BIGSERIAL PRIMARY KEY,
    group_id    BIGINT NOT NULL REFERENCES "SYSCONFIG".groups (id) ON DELETE CASCADE,
    user_id     BIGINT NOT NULL REFERENCES users (id),
    joined_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (group_id, user_id)
);

CREATE TABLE IF NOT EXISTS "SYSCONFIG".menus (
    id              BIGSERIAL PRIMARY KEY,
    parent_menu_id  BIGINT REFERENCES "SYSCONFIG".menus (id), -- hierarchical tree, same self-ref pattern as CRM.partners.parent_partner_id
    code            VARCHAR(50) NOT NULL UNIQUE,
    label           VARCHAR(150) NOT NULL,
    route           VARCHAR(255),
    icon            VARCHAR(50),
    module_code     VARCHAR(20),                    -- which module's sidebar section this belongs to (drives §3A hide/show)
    seq             INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (id <> parent_menu_id)
);

CREATE INDEX IF NOT EXISTS idx_sysconfig_menus_parent  ON "SYSCONFIG".menus (parent_menu_id);
CREATE INDEX IF NOT EXISTS idx_sysconfig_menus_module  ON "SYSCONFIG".menus (module_code);

CREATE TABLE IF NOT EXISTS "SYSCONFIG".menu_rights (
    id          BIGSERIAL PRIMARY KEY,
    menu_id     BIGINT NOT NULL REFERENCES "SYSCONFIG".menus (id) ON DELETE CASCADE,
    group_id    BIGINT NOT NULL REFERENCES "SYSCONFIG".groups (id) ON DELETE CASCADE,
    can_create  BOOLEAN NOT NULL DEFAULT FALSE,
    can_read    BOOLEAN NOT NULL DEFAULT FALSE,
    can_update  BOOLEAN NOT NULL DEFAULT FALSE,
    can_delete  BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE (menu_id, group_id)
);

CREATE INDEX IF NOT EXISTS idx_sysconfig_menu_rights_group ON "SYSCONFIG".menu_rights (group_id);

-- -----------------------------------------------------------------------------
-- §3B / §3C / §4. Config Consts Engine — settings + mini-master/enum, same
-- table and engine (§3C is purely a UI lens over this table, not a schema)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SYSCONFIG".config_consts (
    id              BIGSERIAL PRIMARY KEY,
    appl_id         VARCHAR(20),                    -- module scope; same code space as tenant_modules.module_code — plain code, not FK'd (§3B)
    group_id        BIGINT REFERENCES "SYSCONFIG".groups (id), -- user-group scope override
    user_id         BIGINT REFERENCES users (id),   -- individual-user scope override
    const_group     VARCHAR(50) NOT NULL,           -- logical namespace, e.g. 'LEGAL' or 'SYSTEM'
    group_code      VARCHAR(50) NOT NULL,            -- key within the namespace, e.g. 'CASE_PREFIX'
    value           TEXT,                            -- scalar value, cast per value_type in ConfigService (§3E)
    value_type      VARCHAR(10) NOT NULL DEFAULT 'text' CHECK (value_type IN ('text', 'number', 'bool', 'date')),
    num1            NUMERIC(18, 4),                  -- enum-lens payload (§3C): numeric attribute 1
    num2            NUMERIC(18, 4),                  -- enum-lens payload: numeric attribute 2
    str1            VARCHAR(255),                    -- enum-lens payload: display label
    str2            VARCHAR(255),                    -- enum-lens payload: short form/abbreviation
    note            TEXT,
    seq             INT NOT NULL DEFAULT 0,          -- display/sort order
    effective_date  DATE,                            -- for a simple setting that changes on a schedule (§3C rule: NOT for statutory rate tables)
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE NULLS NOT DISTINCT (appl_id, group_id, user_id, const_group, group_code) -- see header note: strictly enforced, not just flagged
);

CREATE INDEX IF NOT EXISTS idx_sysconfig_config_consts_group ON "SYSCONFIG".config_consts (const_group, group_code, is_active);

-- -----------------------------------------------------------------------------
-- §3D / §4. Serial Number Engine
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SYSCONFIG".config_snums (
    id              BIGSERIAL PRIMARY KEY,
    snum_code       VARCHAR(50) NOT NULL UNIQUE,     -- e.g. 'LEGAL_CASE_LASTID'
    description     VARCHAR(255),
    last_cnt        BIGINT NOT NULL DEFAULT 0,       -- current counter value; incremented under SELECT ... FOR UPDATE (§3D, app-layer)
    wrap_high       BIGINT,                          -- nullable wrap-around ceiling
    padding_length  INT,                             -- zero-pad width
    reset_rule      VARCHAR(10) NOT NULL DEFAULT 'never' CHECK (reset_rule IN ('never', 'yearly', 'monthly')),
    last_reset_at   TIMESTAMPTZ,                     -- drives reset_rule's period-boundary check
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3A / §4. Module Activation
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SYSCONFIG".tenant_modules (
    id              BIGSERIAL PRIMARY KEY,
    module_code     VARCHAR(20) NOT NULL UNIQUE
                        CHECK (module_code IN ('WNE', 'DMS', 'CRM', 'SCHEDULE', 'INVENTORY', 'ACCOUNTING',
                                                'PURCHASE', 'SALES', 'HCM', 'PAYROLL', 'PERFORMANCE',
                                                'AIINSIGHT', 'LEGAL')),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,   -- default is opt-out, not opt-in (§3A rule): absence of a row = active
    activated_at    TIMESTAMPTZ,
    activated_by    BIGINT REFERENCES users (id),
    notes           TEXT
);

-- -----------------------------------------------------------------------------
-- §3G / §4. Config Audit Log — append-only, no update/delete at the app layer
-- (comment-only immutability, same convention as DMS.access_logs/
-- ACCOUNTING.audit_logs/PAYROLL.payroll_access_logs — no blocking trigger)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SYSCONFIG".config_audit_logs (
    id              BIGSERIAL PRIMARY KEY,
    table_name      VARCHAR(30) NOT NULL CHECK (table_name IN ('config_consts', 'config_snums', 'tenant_modules')),
    record_id       BIGINT NOT NULL,
    action          VARCHAR(20) NOT NULL CHECK (action IN ('created', 'updated', 'deactivated', 'serial_corrected')),
    actor_id        BIGINT REFERENCES users (id),
    before_value    JSONB,
    after_value     JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sysconfig_config_audit_logs_record ON "SYSCONFIG".config_audit_logs (table_name, record_id, created_at);

-- =============================================================================
-- NOTE — Future Version tables intentionally NOT created here (§2 MVP scope
-- boundary): a central.tenant_module_grants override table (lives in the
-- CENTRAL database, not here, when built), and any WNE-routed const-change
-- approval workflow table (reuses WNE's own wrkflow_instances, no new table).
-- =============================================================================

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Demonstrates: module activation (opt-out default vs. an explicit row),
-- the config_consts two-tier/three-row scope-override chain worked through
-- in §3E's own SIGNING_REMINDER_DAYS example, a mini-master/enum list
-- (GENDER), the serial-number engine (LEGAL_CASE_LASTID, matching
-- ARCHITECTURE.md §3.2.A's own example), the menu/group/rights trustee
-- schema, and a few audit-log rows for the writes above.
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- or other client-specific meta-commands — so it runs through any Postgres
-- client.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Groups + members
-- ---------------------------------------------------------------------------

INSERT INTO "SYSCONFIG".groups (name, description) VALUES
    ('Notaries', 'PPAT/Notary practitioners with case-signing authority'),
    ('Paralegals', 'Support staff assisting on case filings');

INSERT INTO "SYSCONFIG".group_members (group_id, user_id)
SELECT g.id, u.id FROM "SYSCONFIG".groups g, users u
WHERE g.name = 'Notaries' AND u.email = 'staff@nusaevo.com';

-- ---------------------------------------------------------------------------
-- 2. Menus + rights (a small Legal sidebar section)
-- ---------------------------------------------------------------------------

INSERT INTO "SYSCONFIG".menus (code, label, module_code, seq) VALUES
    ('LEGAL_ROOT', 'Legal', 'LEGAL', 10);

INSERT INTO "SYSCONFIG".menus (parent_menu_id, code, label, route, module_code, seq)
SELECT m.id, 'LEGAL_CASES', 'Cases', '/legal/cases', 'LEGAL', 1
FROM "SYSCONFIG".menus m WHERE m.code = 'LEGAL_ROOT'
UNION ALL
SELECT m.id, 'LEGAL_DEEDS', 'Deeds', '/legal/deeds', 'LEGAL', 2
FROM "SYSCONFIG".menus m WHERE m.code = 'LEGAL_ROOT';

INSERT INTO "SYSCONFIG".menu_rights (menu_id, group_id, can_create, can_read, can_update, can_delete)
SELECT m.id, g.id, TRUE, TRUE, TRUE, FALSE
FROM "SYSCONFIG".menus m, "SYSCONFIG".groups g
WHERE m.code = 'LEGAL_CASES' AND g.name = 'Notaries'
UNION ALL
SELECT m.id, g.id, FALSE, TRUE, FALSE, FALSE
FROM "SYSCONFIG".menus m, "SYSCONFIG".groups g
WHERE m.code = 'LEGAL_CASES' AND g.name = 'Paralegals';

-- ---------------------------------------------------------------------------
-- 3. Module activation — LEGAL/PAYROLL explicitly on; AIINSIGHT explicitly
--    off (not yet ready for this tenant); every other entitled module has NO
--    row at all, demonstrating the opt-out-not-opt-in default (§3A rule)
-- ---------------------------------------------------------------------------

INSERT INTO "SYSCONFIG".tenant_modules (module_code, is_active, activated_at, activated_by, notes)
SELECT 'LEGAL', TRUE, now(), u.id, 'Primary vertical for this tenant.'
FROM users u WHERE u.email = 'admin@nusaevo.com'
UNION ALL
SELECT 'PAYROLL', TRUE, now(), u.id, NULL
FROM users u WHERE u.email = 'admin@nusaevo.com'
UNION ALL
SELECT 'AIINSIGHT', FALSE, now(), u.id, 'Pending Zero Data Retention agreement before go-live (CLAUDE.md §5).'
FROM users u WHERE u.email = 'admin@nusaevo.com';

-- ---------------------------------------------------------------------------
-- 4. Config consts: two plain settings, a mini-master enum list, and the
--    §3E worked example (module default -> group override -> user override)
-- ---------------------------------------------------------------------------

INSERT INTO "SYSCONFIG".config_consts (appl_id, const_group, group_code, value, value_type, note) VALUES
    ('LEGAL', 'LEGAL', 'CASE_PREFIX', 'PPAT', 'text', 'Prefix used by PrefixedCaseCodeGenerator (ARCHITECTURE.md §3.2.A).'),
    ('LEGAL', 'LEGAL', 'URGENT_SETS_PENDING', '3', 'number', 'Case is flagged urgent once this many sets are pending signature.');

-- Mini-master/enum lens (§3C): GENDER as data, not a hardcoded PHP enum
INSERT INTO "SYSCONFIG".config_consts (const_group, group_code, value_type, str1, str2, seq) VALUES
    ('GENDER', 'M', 'text', 'Male', 'M', 1),
    ('GENDER', 'F', 'text', 'Female', 'F', 2);

-- §3E's own worked example: module default, then group override, then user override
INSERT INTO "SYSCONFIG".config_consts (appl_id, const_group, group_code, value, value_type, note)
VALUES ('LEGAL', 'LEGAL', 'SIGNING_REMINDER_DAYS', '7', 'number', 'Module-wide default for all Legal users.');

INSERT INTO "SYSCONFIG".config_consts (appl_id, group_id, const_group, group_code, value, value_type, note)
SELECT 'LEGAL', g.id, 'LEGAL', 'SIGNING_REMINDER_DAYS', '3', 'number', 'Notaries need a shorter lead time — they sign, not just file.'
FROM "SYSCONFIG".groups g WHERE g.name = 'Notaries';

INSERT INTO "SYSCONFIG".config_consts (appl_id, user_id, const_group, group_code, value, value_type, note)
SELECT 'LEGAL', u.id, 'LEGAL', 'SIGNING_REMINDER_DAYS', '1', 'number', 'This specific PPAT asked for a same-day reminder only.'
FROM users u WHERE u.email = 'staff@nusaevo.com';

-- ---------------------------------------------------------------------------
-- 5. Serial number engine — matches ARCHITECTURE.md §3.2.A's own example
-- ---------------------------------------------------------------------------

INSERT INTO "SYSCONFIG".config_snums (snum_code, description, last_cnt, padding_length, reset_rule, last_reset_at)
VALUES ('LEGAL_CASE_LASTID', 'Running case number for PrefixedCaseCodeGenerator.', 42, 4, 'yearly', TIMESTAMPTZ '2026-01-01 00:00:00+07');

-- ---------------------------------------------------------------------------
-- 6. Audit log entries for the writes above (append-only, illustrative subset)
-- ---------------------------------------------------------------------------

INSERT INTO "SYSCONFIG".config_audit_logs (table_name, record_id, action, actor_id, after_value)
SELECT 'config_consts', cc.id, 'created', u.id,
       jsonb_build_object('const_group', cc.const_group, 'group_code', cc.group_code, 'value', cc.value)
FROM "SYSCONFIG".config_consts cc, users u
WHERE cc.const_group = 'LEGAL' AND cc.group_code = 'CASE_PREFIX' AND cc.appl_id = 'LEGAL' AND u.email = 'admin@nusaevo.com'
UNION ALL
SELECT 'tenant_modules', tm.id, 'created', u.id,
       jsonb_build_object('module_code', tm.module_code, 'is_active', tm.is_active)
FROM "SYSCONFIG".tenant_modules tm, users u
WHERE tm.module_code = 'AIINSIGHT' AND u.email = 'admin@nusaevo.com'
UNION ALL
SELECT 'config_snums', cs.id, 'created', u.id,
       jsonb_build_object('snum_code', cs.snum_code, 'last_cnt', cs.last_cnt)
FROM "SYSCONFIG".config_snums cs, users u
WHERE cs.snum_code = 'LEGAL_CASE_LASTID' AND u.email = 'admin@nusaevo.com';

COMMIT;

-- =============================================================================
-- Sanity check counts
-- =============================================================================
SELECT 'groups' AS table_name, COUNT(*) FROM "SYSCONFIG".groups
UNION ALL SELECT 'menus', COUNT(*) FROM "SYSCONFIG".menus
UNION ALL SELECT 'menu_rights', COUNT(*) FROM "SYSCONFIG".menu_rights
UNION ALL SELECT 'tenant_modules', COUNT(*) FROM "SYSCONFIG".tenant_modules
UNION ALL SELECT 'config_consts', COUNT(*) FROM "SYSCONFIG".config_consts
UNION ALL SELECT 'config_snums', COUNT(*) FROM "SYSCONFIG".config_snums
UNION ALL SELECT 'config_audit_logs', COUNT(*) FROM "SYSCONFIG".config_audit_logs;
