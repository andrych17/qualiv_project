-- =============================================================================
-- AIInsight module — schema + example seed data
-- Source spec: app/Modules/AIINSIGHT_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_001`), schema "AIINSIGHT" — NOT the
--              Central DB. Every table here lives inside one tenant's own database
--              (CLAUDE.md §4/§7A) — there is deliberately no tenant_id column
--              anywhere below; DB-per-tenant is the isolation boundary.
--
-- This module is Core but NOT standalone-sellable (§1) — it has nothing to say
-- about an empty tenant DB, so seed data below assumes other modules (Legal, CRM)
-- already have rows; the audit-log examples reference them by name only, not FK,
-- since AIInsight has zero compile-time dependency on any other module's schema.
--
-- `users` (Laravel's own tenant-scoped table, created by
-- database/migrations/tenant/0001_01_01_000000_create_users_table.php) already
-- exists and is only referenced here, never (re)created.
--
-- This file is a reference/planning script, not a Laravel migration — port each
-- section below into `database/migrations/tenant/*.php` when this module is
-- actually built (CLAUDE.md §5 lists it as specced-but-not-yet-built, gated on a
-- confirmed Zero Data Retention agreement with Anthropic before any
-- confidentiality-sensitive tenant goes live — see AIINSIGHT_SPECS.md §5).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted: unquoted CREATE SCHEMA folds to lowercase "aiinsight", but every table
-- below is referenced as "AIINSIGHT"."..." (quoted) — names must match exactly,
-- same convention already used for "PROJECTS"/"CUSTOMFIELDS" (see those modules'
-- own tenant migrations).
CREATE SCHEMA IF NOT EXISTS "AIINSIGHT";

-- -----------------------------------------------------------------------------
-- §3A / §4. Conversations — persisted per USER, not just per tenant (a paralegal's
-- thread must never leak into a partner's, even inside the same tenant DB).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "AIINSIGHT".conversations (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing (chat URL)
    user_id         BIGINT NOT NULL REFERENCES users (id),
    title           VARCHAR(255),                        -- derived from the first question; nullable until then
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_aiinsight_conversations_user ON "AIINSIGHT".conversations (user_id);

-- -----------------------------------------------------------------------------
-- §3B / §3C / §4. Query audit — append-only. The safety net that makes "an LLM
-- touching tenant data unsupervised" defensible (§3A). No update/delete at the
-- app layer, same audit-integrity rule as DMS.access_logs.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "AIINSIGHT".query_audit (
    id              BIGSERIAL PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users (id),
    conversation_id BIGINT REFERENCES "AIINSIGHT".conversations (id), -- nullable: ad hoc/test queries
    question        TEXT,                                 -- the natural-language question that triggered this
    sql_text        TEXT NOT NULL,                         -- the actual SQL the run_readonly_query tool executed
    row_count       INTEGER NOT NULL DEFAULT 0,
    latency_ms      INTEGER NOT NULL,
    model_used      VARCHAR(10) NOT NULL DEFAULT 'haiku'
                        CHECK (model_used IN ('haiku', 'sonnet')), -- §3B cost-control escalation rule
    is_unannotated  BOOLEAN NOT NULL DEFAULT FALSE,        -- §3C: touched a schema/table with no annotation entry
    executed_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_aiinsight_query_audit_user ON "AIINSIGHT".query_audit (user_id, executed_at);
CREATE INDEX IF NOT EXISTS idx_aiinsight_query_audit_conversation ON "AIINSIGHT".query_audit (conversation_id);

-- -----------------------------------------------------------------------------
-- §3A / §4. Messages — assistant turns that ran a query store the query_audit
-- reference alongside the rendered answer, so the answer is never trusted without
-- a reconstructable trail of what actually ran.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "AIINSIGHT".messages (
    id              BIGSERIAL PRIMARY KEY,
    conversation_id BIGINT NOT NULL REFERENCES "AIINSIGHT".conversations (id) ON DELETE CASCADE,
    role            VARCHAR(10) NOT NULL CHECK (role IN ('user', 'assistant')),
    content         TEXT NOT NULL,                         -- the question (user) or rendered answer (assistant)
    query_audit_id  BIGINT REFERENCES "AIINSIGHT".query_audit (id), -- set only on assistant turns that ran a query
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_aiinsight_messages_conversation ON "AIINSIGHT".messages (conversation_id, created_at);

-- -----------------------------------------------------------------------------
-- §3C / §4. Schema annotations — table/column -> human description. Auto-drafted
-- once from information_schema + a one-time LLM pass, then hand-edited (§5: no
-- scheduled refresh job in MVP). column_name NULL = table-level description.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "AIINSIGHT".schema_annotations (
    id              BIGSERIAL PRIMARY KEY,
    schema_name     VARCHAR(63) NOT NULL,
    table_name      VARCHAR(63) NOT NULL,
    column_name     VARCHAR(63),                           -- NULL = describes the table itself
    description     TEXT NOT NULL,
    source          VARCHAR(20) NOT NULL DEFAULT 'auto_draft'
                        CHECK (source IN ('auto_draft', 'llm_draft', 'manual')),
    updated_by      BIGINT REFERENCES users (id),           -- NULL when still the auto-draft
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Note: Postgres treats NULL column_name values as distinct, so this only fully
-- guards column-level rows; enforce "one table-level row per table" (column_name
-- IS NULL) in the service layer, or add a partial unique index for that case —
-- the same NULLS-NOT-DISTINCT caveat noted in CENTRAL_SPECS.sql's dunning policy.
CREATE UNIQUE INDEX IF NOT EXISTS uq_aiinsight_schema_annotations
    ON "AIINSIGHT".schema_annotations (schema_name, table_name, column_name);

CREATE INDEX IF NOT EXISTS idx_aiinsight_schema_annotations_table
    ON "AIINSIGHT".schema_annotations (schema_name, table_name);

-- -----------------------------------------------------------------------------
-- §3D / §4. Usage counters — rolling monthly token/query counts, checked before
-- every ask() call (§3D rule: budget enforcement happens BEFORE the Claude API
-- call, never after).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "AIINSIGHT".usage_counters (
    id              BIGSERIAL PRIMARY KEY,
    period          CHAR(7) NOT NULL UNIQUE,                -- 'YYYY-MM', one row per calendar month
    queries_used    INTEGER NOT NULL DEFAULT 0,
    tokens_used     BIGINT NOT NULL DEFAULT 0,
    query_budget    INTEGER NOT NULL,                       -- from the tenant's "AI Insights" add-on SKU (§3D)
    token_budget    BIGINT NOT NULL,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3C / §4. Schema access rules — per-tenant schema/table denylist or allowlist,
-- editable by the tenant admin (e.g. exclude CUSTOMFIELDS raw storage that has
-- never been vetted for sensitive content).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "AIINSIGHT".schema_access_rules (
    id              BIGSERIAL PRIMARY KEY,
    schema_name     VARCHAR(63) NOT NULL,
    table_name      VARCHAR(63),                            -- NULL = the whole schema
    rule_type       VARCHAR(10) NOT NULL CHECK (rule_type IN ('allow', 'deny')),
    reason          VARCHAR(255),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_aiinsight_schema_access_rules_scope
    ON "AIINSIGHT".schema_access_rules (schema_name, table_name);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Assumes a tenant DB already seeded with database/seeders/DatabaseSeeder.php's
-- demo users (admin@nusaevo.com, staff@nusaevo.com, ...) and, for the query_audit
-- example below, a "LEGAL"."cases" table with a `status` column
-- (database/migrations/tenant/2026_07_17_140001_create_legal_cases_table.php) —
-- adjust the sql_text if running against a DB without that table.
-- =============================================================================

BEGIN;

-- §3D. Current month's usage counters — an "AI Insights" add-on budget example
INSERT INTO "AIINSIGHT".usage_counters (period, queries_used, tokens_used, query_budget, token_budget)
VALUES (to_char(now(), 'YYYY-MM'), 12, 45000, 500, 2000000)
ON CONFLICT (period) DO NOTHING;

-- §3C. Schema annotations — a mix of table-level and column-level entries
INSERT INTO "AIINSIGHT".schema_annotations (schema_name, table_name, column_name, description, source) VALUES
    ('LEGAL', 'cases', NULL,          'One row per legal case/matter handled by the firm.', 'llm_draft'),
    ('LEGAL', 'cases', 'code',        'Human-readable case code, e.g. A-001 (prefix set per firm, see SYSCONFIG.config_consts LEGAL.CASE_PREFIX).', 'manual'),
    ('LEGAL', 'cases', 'status',      'Case lifecycle status: open, pending, closed.', 'manual'),
    ('CRM', 'leads', NULL,            'Prospective clients moving through the CRM pipeline (New -> Contacted -> Qualified -> Converted/Disqualified).', 'llm_draft'),
    ('ACCOUNTING', 'gl_journals', 'amt_net', 'Journal line amount excluding tax (PPN/PPh handled separately).', 'manual')
ON CONFLICT (schema_name, table_name, column_name) DO NOTHING;

-- §3C. Schema access rules — exclude raw EAV storage that has not been vetted for
-- sensitive content, per the example already given in the spec's own text
INSERT INTO "AIINSIGHT".schema_access_rules (schema_name, table_name, rule_type, reason) VALUES
    ('CUSTOMFIELDS', 'field_values', 'deny', 'Raw EAV values not yet vetted for sensitive content.')
ON CONFLICT DO NOTHING;

-- §3A/§3B/§3C. Example conversation: one question, one query, one answer
WITH admin_user AS (
    SELECT id FROM users WHERE email = 'admin@nusaevo.com'
), new_conversation AS (
    INSERT INTO "AIINSIGHT".conversations (user_id, title)
    SELECT id, 'Overdue cases this week' FROM admin_user
    RETURNING id, user_id
), new_query_audit AS (
    INSERT INTO "AIINSIGHT".query_audit (user_id, conversation_id, question, sql_text, row_count, latency_ms, model_used, is_unannotated)
    SELECT
        new_conversation.user_id,
        new_conversation.id,
        'Which cases are overdue?',
        'SELECT code, title, status FROM "LEGAL".cases WHERE status = ''pending'' LIMIT 500',
        7,
        842,
        'haiku',
        FALSE
    FROM new_conversation
    RETURNING id, conversation_id
)
INSERT INTO "AIINSIGHT".messages (conversation_id, role, content, query_audit_id)
SELECT conversation_id, 'user', 'Which cases are overdue?', NULL FROM new_query_audit
UNION ALL
SELECT conversation_id, 'assistant', 'You have 7 pending cases. The oldest is A-014, open since last month.', id FROM new_query_audit;

COMMIT;
