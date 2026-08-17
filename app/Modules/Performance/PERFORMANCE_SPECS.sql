-- =============================================================================
-- Performance module — schema + example seed data
-- Source spec: app/Modules/Performance/PERFORMANCE_SPECS.md (see that file for
-- full rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "PERF" (per §4's
--              explicit schema name, shorter than the module name) — every
--              table lives inside one tenant's own database (CLAUDE.md
--              §4/§7A), no tenant_id column anywhere.
--
-- Deliberately NO dedicated organizational hierarchy table (§5) — "multi-level"
-- KPIs/Budgets/Targets/Scorecards are achieved with the same polymorphic
-- subject_type/subject_id pattern used by WNE/DMS/CRM/Schedule everywhere else
-- in this platform, and "multi-level" OKRs use a self-referencing
-- `parent_okr_id`, the same mechanism as CRM's `parent_partner_id`. No table
-- below carries a real FK for a subject reference — that's the point.
--
-- Performance's own Budgeting is a DIFFERENT concept from Accounting's
-- GL-account/cost-center-scoped Budgeting (ACCOUNTING_SPECS.md §3J) — see §1's
-- explicit disambiguation. `budget_category_accounts.accounting_account_id` is
-- an informational reference, NOT a FK into "ACCOUNTING".accounts — Accounting
-- is an optional install, and even when present, Performance never becomes a
-- second ledger (§3B/§5).
--
-- OKR progress and Objective status are computed ON READ from
-- okr_key_results, never stored/cached (§3E) — there is deliberately no
-- "progress_pct" column on okr_objectives. The seed data below stores the raw
-- start/current/target values only; the worked-example verification queries
-- after the seed data compute progress the same way the application would.
--
-- This file is a reference/planning script, not a Laravel migration — port
-- each section below into `database/migrations/tenant/*.php` per the
-- suggested build order in §5 (KPI library -> Targets -> actuals capture ->
-- Variance Engine -> Budgeting -> Forecast -> OKRs -> Scorecards -> Dashboard
-- -> Achievements).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"AIINSIGHT"/"ACCOUNTING"/"CRM"/"DMS"/"HCM"/"INVENTORY"/"LEGAL"/"PAYROLL"/"CUSTOMFIELDS".
CREATE SCHEMA IF NOT EXISTS "PERF";

-- -----------------------------------------------------------------------------
-- §4. Master / lookup tables
-- -----------------------------------------------------------------------------

-- Fiscal period definitions shared by Budgeting/Targets/Forecast/OKR cycles.
-- A "period" here can represent a year, a quarter, or a month — callers pick
-- the right granularity row for the slice they need (year for a Target's
-- annual period, month for a Budget line's period slice, etc).
CREATE TABLE IF NOT EXISTS "PERF".periods (
    id          BIGSERIAL PRIMARY KEY,
    year        SMALLINT NOT NULL,
    quarter     SMALLINT CHECK (quarter BETWEEN 1 AND 4),
    month       SMALLINT CHECK (month BETWEEN 1 AND 12),
    label       VARCHAR(20) NOT NULL,                     -- "2026", "2026 Q1", "2026-01"
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    UNIQUE (year, quarter, month)
);

CREATE TABLE IF NOT EXISTS "PERF".perspectives (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,                    -- Financial, Customer, Process, Learning & Growth, ... (tenant-editable)
    seq         INTEGER NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PERF".kpi_definitions (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    unit            VARCHAR(10) NOT NULL CHECK (unit IN ('number', 'percent', 'currency', 'ratio')),
    direction       VARCHAR(17) NOT NULL CHECK (direction IN ('higher_is_better', 'lower_is_better')),
    perspective_id  BIGINT REFERENCES "PERF".perspectives (id),
    description     TEXT,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE          -- historical targets/values on a deactivated KPI stay visible (§3C rule)
);

CREATE TABLE IF NOT EXISTS "PERF".okr_cycles (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(50) NOT NULL UNIQUE,               -- e.g. "2026 Q3"
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL
);

CREATE TABLE IF NOT EXISTS "PERF".badge_definitions (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(30) NOT NULL UNIQUE,
    name                VARCHAR(150) NOT NULL,
    trigger_rule_type   VARCHAR(15) NOT NULL CHECK (trigger_rule_type IN ('target_hit', 'okr_completed', 'streak_on_track')),
    trigger_params      JSONB,                             -- e.g. {"streak_length": 3}
    icon                VARCHAR(50),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

-- -----------------------------------------------------------------------------
-- §3B / §4. Budgeting
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PERF".budget_hdrs (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    subject_type            VARCHAR(100) NOT NULL,        -- polymorphic — company/department/team/individual/any vertical record
    subject_id              VARCHAR(100) NOT NULL,
    name                    VARCHAR(150) NOT NULL,
    period_id               BIGINT NOT NULL REFERENCES "PERF".periods (id), -- fiscal period (year, or year+quarter)
    status                  VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'approved', 'locked')),
    owner_id                BIGINT REFERENCES users (id),
    version                 INTEGER NOT NULL DEFAULT 1,
    supersedes_budget_id    BIGINT REFERENCES "PERF".budget_hdrs (id), -- an approved budget is edited only by a new version row (§3B rule)
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (id <> supersedes_budget_id)
);

CREATE INDEX IF NOT EXISTS idx_perf_budget_hdrs_subject ON "PERF".budget_hdrs (subject_type, subject_id, period_id);

CREATE TABLE IF NOT EXISTS "PERF".budget_lines (
    id                  BIGSERIAL PRIMARY KEY,
    budget_id           BIGINT NOT NULL REFERENCES "PERF".budget_hdrs (id) ON DELETE CASCADE,
    category            VARCHAR(100) NOT NULL,            -- free label, e.g. "Payroll", "Marketing" — not a GL account
    period_slice_id     BIGINT NOT NULL REFERENCES "PERF".periods (id), -- typically a month row
    amount_planned      NUMERIC(18, 2) NOT NULL,
    UNIQUE (budget_id, category, period_slice_id)
);

-- Tenant-editable, optional. NOT a FK — Accounting is an optional install and
-- Performance must keep working with this table empty (§3B/§5).
CREATE TABLE IF NOT EXISTS "PERF".budget_category_accounts (
    id                      BIGSERIAL PRIMARY KEY,
    category                VARCHAR(100) NOT NULL,
    accounting_account_id   BIGINT NOT NULL,              -- informational reference to "ACCOUNTING".accounts.id, deliberately unenforced
    company_id              BIGINT,                       -- optional scope, informational reference to "ACCOUNTING".companies.id
    UNIQUE (category, accounting_account_id, company_id)
    -- Same NULLS-NOT-DISTINCT caveat noted in the other *_SPECS.sql files:
    -- multiple rows with company_id NULL for the same category/account pair
    -- would not violate this constraint.
);

-- Manual fallback, used only when a category isn't GL-mapped or Accounting
-- isn't installed (§3B) — same shape as PERF.kpi_values (§3D), reused
-- deliberately rather than inventing a second "manual actual" pattern.
CREATE TABLE IF NOT EXISTS "PERF".budget_actuals (
    id                  BIGSERIAL PRIMARY KEY,
    budget_line_id      BIGINT NOT NULL REFERENCES "PERF".budget_lines (id),
    period_id           BIGINT NOT NULL REFERENCES "PERF".periods (id),
    actual_value        NUMERIC(18, 2) NOT NULL,
    entered_by          BIGINT REFERENCES users (id),
    entered_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (budget_line_id, period_id)
);

-- -----------------------------------------------------------------------------
-- §3C / §3D / §4. Targets & KPI Actuals — the "multi-level" mechanism is
-- tagging the subject, not a hierarchy table (§5).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PERF".targets (
    id              BIGSERIAL PRIMARY KEY,
    kpi_id          BIGINT NOT NULL REFERENCES "PERF".kpi_definitions (id),
    subject_type    VARCHAR(100) NOT NULL,
    subject_id      VARCHAR(100) NOT NULL,
    period_id       BIGINT NOT NULL REFERENCES "PERF".periods (id),
    target_value    NUMERIC(18, 4) NOT NULL,
    stretch_value   NUMERIC(18, 4),
    notes           TEXT,
    UNIQUE (kpi_id, subject_type, subject_id, period_id)
);

CREATE TABLE IF NOT EXISTS "PERF".kpi_values (
    id              BIGSERIAL PRIMARY KEY,
    kpi_id          BIGINT NOT NULL REFERENCES "PERF".kpi_definitions (id),
    subject_type    VARCHAR(100) NOT NULL,
    subject_id      VARCHAR(100) NOT NULL,
    period_id       BIGINT NOT NULL REFERENCES "PERF".periods (id),
    actual_value    NUMERIC(18, 4) NOT NULL,
    source          VARCHAR(10) NOT NULL DEFAULT 'manual' CHECK (source IN ('manual')), -- reserved values for future automated connectors (§3D)
    entered_by      BIGINT REFERENCES users (id),
    entered_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (kpi_id, subject_type, subject_id, period_id)
);

CREATE INDEX IF NOT EXISTS idx_perf_kpi_values_subject ON "PERF".kpi_values (subject_type, subject_id, period_id);

-- -----------------------------------------------------------------------------
-- §3E / §4. OKR Management
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PERF".okr_objectives (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    cycle_id            BIGINT NOT NULL REFERENCES "PERF".okr_cycles (id),
    subject_type        VARCHAR(100) NOT NULL,
    subject_id          VARCHAR(100) NOT NULL,
    objective_text      VARCHAR(500) NOT NULL,
    parent_okr_id       BIGINT REFERENCES "PERF".okr_objectives (id), -- alignment to a higher-level Objective (§3E), same idea as CRM's parent_partner_id
    status               VARCHAR(10) NOT NULL DEFAULT 'on_track' CHECK (status IN ('on_track', 'at_risk', 'off_track', 'completed')),
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (id <> parent_okr_id)
);

CREATE INDEX IF NOT EXISTS idx_perf_okr_objectives_parent ON "PERF".okr_objectives (parent_okr_id);

CREATE TABLE IF NOT EXISTS "PERF".okr_key_results (
    id                  BIGSERIAL PRIMARY KEY,
    okr_id              BIGINT NOT NULL REFERENCES "PERF".okr_objectives (id) ON DELETE CASCADE,
    description         VARCHAR(255) NOT NULL,
    metric_type         VARCHAR(10) NOT NULL CHECK (metric_type IN ('numeric', 'percent', 'boolean', 'milestone')),
    start_value         NUMERIC(18, 4) NOT NULL DEFAULT 0,
    current_value       NUMERIC(18, 4) NOT NULL DEFAULT 0,
    target_value        NUMERIC(18, 4) NOT NULL,
    weight              NUMERIC(5, 2) NOT NULL DEFAULT 1  -- for the Objective's weighted progress %, computed on read (§3E)
);

-- -----------------------------------------------------------------------------
-- §3F / §4. Scorecard Builder & Viewer — a view/composition, never a
-- duplicate of KPI/OKR values (§3F rule).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PERF".scorecard_hdrs (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name            VARCHAR(150) NOT NULL,
    subject_type    VARCHAR(100) NOT NULL,
    subject_id      VARCHAR(100) NOT NULL,
    period_id       BIGINT NOT NULL REFERENCES "PERF".periods (id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "PERF".scorecard_items (
    id                  BIGSERIAL PRIMARY KEY,
    scorecard_id        BIGINT NOT NULL REFERENCES "PERF".scorecard_hdrs (id) ON DELETE CASCADE,
    perspective_id       BIGINT NOT NULL REFERENCES "PERF".perspectives (id),
    metric_type              VARCHAR(3) NOT NULL CHECK (metric_type IN ('kpi', 'okr')),
    kpi_id                       BIGINT REFERENCES "PERF".kpi_definitions (id), -- set when metric_type = 'kpi'
    okr_id                          BIGINT REFERENCES "PERF".okr_objectives (id), -- set when metric_type = 'okr'
    weight                              NUMERIC(5, 2) NOT NULL, -- must sum to 100% per perspective, app-validated on save (§3F)
    computed_actual                        NUMERIC(18, 4),
    computed_target                           NUMERIC(18, 4),
    computed_score                               NUMERIC(6, 2),
    CHECK ((metric_type = 'kpi') = (kpi_id IS NOT NULL)),
    CHECK ((metric_type = 'okr') = (okr_id IS NOT NULL))
);

-- -----------------------------------------------------------------------------
-- §3H / §4. Forecast — versions forward, never overwrites (§3H rule)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PERF".forecast_hdrs (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    subject_type    VARCHAR(100) NOT NULL,
    subject_id      VARCHAR(100) NOT NULL,
    budget_id       BIGINT REFERENCES "PERF".budget_hdrs (id), -- optional — a forecast can stand alone against a KPI target instead (§3H)
    period_id       BIGINT NOT NULL REFERENCES "PERF".periods (id),
    version         INTEGER NOT NULL DEFAULT 1,
    method          VARCHAR(10) NOT NULL DEFAULT 'manual' CHECK (method IN ('manual')), -- reserved values for Future Version statistical methods (§3H)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (subject_type, subject_id, period_id, version)
);

CREATE TABLE IF NOT EXISTS "PERF".forecast_lines (
    id                  BIGSERIAL PRIMARY KEY,
    forecast_id         BIGINT NOT NULL REFERENCES "PERF".forecast_hdrs (id) ON DELETE CASCADE,
    period_slice_id     BIGINT NOT NULL REFERENCES "PERF".periods (id),
    forecast_value      NUMERIC(18, 2) NOT NULL,
    UNIQUE (forecast_id, period_slice_id)
);

-- -----------------------------------------------------------------------------
-- §3I / §4. Achievements — a factual log, no gamification (§2/§3I)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PERF".achievements (
    id                  BIGSERIAL PRIMARY KEY,
    subject_type        VARCHAR(100) NOT NULL,
    subject_id          VARCHAR(100) NOT NULL,
    badge_id            BIGINT NOT NULL REFERENCES "PERF".badge_definitions (id),
    earned_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    trigger_reference   VARCHAR(255),                     -- descriptive reference to the KPI/OKR/period that triggered it
    awarded_by          BIGINT REFERENCES users (id)      -- NULL = system-auto-awarded (§3I)
);

CREATE INDEX IF NOT EXISTS idx_perf_achievements_subject ON "PERF".achievements (subject_type, subject_id);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- A company-level FY2026 budget (one line manually-actualed, one GL-category
-- mapping demonstrated), a Revenue + Client Satisfaction KPI with Q1 targets
-- and actuals (one beating target, one missing it — to show both a healthy
-- and an unhealthy variance case), an aligned two-level OKR pair, a Q1
-- scorecard composing both a KPI and an OKR item, a two-period forecast tied
-- to the budget, and one auto-awarded achievement.
--
-- All subjects use subject_type = 'performance.company' /
-- 'performance.team' with a simple string id ('1') — there is no real
-- Company/Team table to point at, which is exactly the point of the
-- polymorphic, hierarchy-table-free design (§5).
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com). Written with plain
-- subqueries and data-modifying CTEs only — no psql \gset — so it runs
-- through any Postgres client. Header-creating and dependent statements are
-- kept separate where a later statement would need to see a same-statement
-- sibling insert via a table scan (see DMS_SPECS.sql's header note).
-- =============================================================================

BEGIN;

INSERT INTO "PERF".periods (year, quarter, month, label, start_date, end_date) VALUES
    (2026, NULL, NULL, '2026', '2026-01-01', '2026-12-31'),
    (2026, 1, NULL, '2026 Q1', '2026-01-01', '2026-03-31'),
    (2026, NULL, 1, '2026-01', '2026-01-01', '2026-01-31'),
    (2026, NULL, 2, '2026-02', '2026-02-01', '2026-02-28');

INSERT INTO "PERF".perspectives (code, name, seq) VALUES
    ('FINANCIAL', 'Financial', 10), ('CUSTOMER', 'Customer', 20),
    ('PROCESS', 'Process', 30), ('LEARNING_GROWTH', 'Learning & Growth', 40);

INSERT INTO "PERF".kpi_definitions (code, name, unit, direction, perspective_id)
SELECT 'REVENUE', 'Revenue', 'currency', 'higher_is_better', id FROM "PERF".perspectives WHERE code = 'FINANCIAL'
UNION ALL
SELECT 'CSAT', 'Client Satisfaction', 'percent', 'higher_is_better', id FROM "PERF".perspectives WHERE code = 'CUSTOMER';

INSERT INTO "PERF".okr_cycles (name, start_date, end_date) VALUES ('2026 Q1', '2026-01-01', '2026-03-31');

INSERT INTO "PERF".badge_definitions (code, name, trigger_rule_type) VALUES
    ('TARGET_HIT', 'Target Hit', 'target_hit');

-- --- Budgeting: company-level FY2026 budget ----------------------------------

INSERT INTO "PERF".budget_hdrs (subject_type, subject_id, name, period_id, status, owner_id)
SELECT 'performance.company', '1', 'FY2026 Operating Budget', id, 'approved', (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "PERF".periods WHERE label = '2026';

INSERT INTO "PERF".budget_lines (budget_id, category, period_slice_id, amount_planned)
SELECT b.id, 'Marketing', p.id, 10000000
FROM "PERF".budget_hdrs b, "PERF".periods p
WHERE b.name = 'FY2026 Operating Budget' AND p.label = '2026-01'
UNION ALL
SELECT b.id, 'Marketing', p.id, 10000000
FROM "PERF".budget_hdrs b, "PERF".periods p
WHERE b.name = 'FY2026 Operating Budget' AND p.label = '2026-02';

-- Manual actual for January (no GL mapping for "Marketing" seeded below, so
-- this line falls back to manual entry per §3B's actual-sourcing rule)
INSERT INTO "PERF".budget_actuals (budget_line_id, period_id, actual_value, entered_by)
SELECT bl.id, p.id, 9500000, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "PERF".budget_lines bl
JOIN "PERF".budget_hdrs b ON b.id = bl.budget_id AND b.name = 'FY2026 Operating Budget'
JOIN "PERF".periods p ON p.id = bl.period_slice_id AND p.label = '2026-01'
WHERE bl.category = 'Marketing';

-- Example GL-category mapping for a DIFFERENT category ("Payroll") —
-- demonstrates the optional mechanism without requiring "ACCOUNTING".accounts
-- to actually exist, since this reference is deliberately unenforced
INSERT INTO "PERF".budget_category_accounts (category, accounting_account_id)
VALUES ('Payroll', 12);

-- --- Targets & KPI actuals, Q1 2026 ------------------------------------------

INSERT INTO "PERF".targets (kpi_id, subject_type, subject_id, period_id, target_value, stretch_value)
SELECT k.id, 'performance.company', '1', p.id, 300000000, 350000000
FROM "PERF".kpi_definitions k, "PERF".periods p
WHERE k.code = 'REVENUE' AND p.label = '2026 Q1'
UNION ALL
SELECT k.id, 'performance.company', '1', p.id, 90, NULL
FROM "PERF".kpi_definitions k, "PERF".periods p
WHERE k.code = 'CSAT' AND p.label = '2026 Q1';

-- Revenue beats target (on-track); CSAT misses it (a breach case, both shown
-- on purpose so the seed exercises both a healthy and unhealthy variance)
INSERT INTO "PERF".kpi_values (kpi_id, subject_type, subject_id, period_id, actual_value, entered_by)
SELECT k.id, 'performance.company', '1', p.id, 310000000, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "PERF".kpi_definitions k, "PERF".periods p
WHERE k.code = 'REVENUE' AND p.label = '2026 Q1'
UNION ALL
SELECT k.id, 'performance.company', '1', p.id, 78, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "PERF".kpi_definitions k, "PERF".periods p
WHERE k.code = 'CSAT' AND p.label = '2026 Q1';

-- --- OKRs: a company Objective with an aligned team Objective underneath ----

INSERT INTO "PERF".okr_objectives (cycle_id, subject_type, subject_id, objective_text, status)
SELECT c.id, 'performance.company', '1', 'Grow revenue by 15% this quarter', 'on_track'
FROM "PERF".okr_cycles c WHERE c.name = '2026 Q1';

INSERT INTO "PERF".okr_key_results (okr_id, description, metric_type, start_value, current_value, target_value, weight)
SELECT o.id, 'Increase MRR to Rp25,000,000', 'numeric', 20000000, 23000000, 25000000, 1
FROM "PERF".okr_objectives o WHERE o.objective_text = 'Grow revenue by 15% this quarter';

INSERT INTO "PERF".okr_objectives (cycle_id, subject_type, subject_id, objective_text, parent_okr_id, status)
SELECT c.id, 'performance.team', '1', 'Close 5 new enterprise deals', parent.id, 'at_risk'
FROM "PERF".okr_cycles c, "PERF".okr_objectives parent
WHERE c.name = '2026 Q1' AND parent.objective_text = 'Grow revenue by 15% this quarter';

INSERT INTO "PERF".okr_key_results (okr_id, description, metric_type, start_value, current_value, target_value, weight)
SELECT o.id, 'Number of enterprise deals closed', 'numeric', 0, 3, 5, 1
FROM "PERF".okr_objectives o WHERE o.objective_text = 'Close 5 new enterprise deals';

-- --- Scorecard composing the Revenue KPI and the company OKR ----------------

INSERT INTO "PERF".scorecard_hdrs (name, subject_type, subject_id, period_id)
SELECT 'Company Scorecard Q1 2026', 'performance.company', '1', id
FROM "PERF".periods WHERE label = '2026 Q1';

INSERT INTO "PERF".scorecard_items (scorecard_id, perspective_id, metric_type, kpi_id, weight, computed_actual, computed_target, computed_score)
SELECT sc.id, persp.id, 'kpi', k.id, 60, 310000000, 300000000, 103.33
FROM "PERF".scorecard_hdrs sc, "PERF".perspectives persp, "PERF".kpi_definitions k
WHERE sc.name = 'Company Scorecard Q1 2026' AND persp.code = 'FINANCIAL' AND k.code = 'REVENUE';

INSERT INTO "PERF".scorecard_items (scorecard_id, perspective_id, metric_type, okr_id, weight, computed_actual, computed_target, computed_score)
SELECT sc.id, persp.id, 'okr', o.id, 40, 23000000, 25000000, 92.00 -- (23M-20M)/(25M-20M) = 60%... illustrative rounded score, not a strict formula match
FROM "PERF".scorecard_hdrs sc, "PERF".perspectives persp, "PERF".okr_objectives o
WHERE sc.name = 'Company Scorecard Q1 2026' AND persp.code = 'PROCESS' AND o.objective_text = 'Grow revenue by 15% this quarter';

-- --- Forecast tied to the budget ---------------------------------------------

INSERT INTO "PERF".forecast_hdrs (subject_type, subject_id, budget_id, period_id, version)
SELECT 'performance.company', '1', b.id, p.id, 1
FROM "PERF".budget_hdrs b, "PERF".periods p
WHERE b.name = 'FY2026 Operating Budget' AND p.label = '2026';

INSERT INTO "PERF".forecast_lines (forecast_id, period_slice_id, forecast_value)
SELECT f.id, p.id, 10200000
FROM "PERF".forecast_hdrs f, "PERF".periods p
WHERE f.subject_type = 'performance.company' AND p.label = '2026-01'
UNION ALL
SELECT f.id, p.id, 10500000
FROM "PERF".forecast_hdrs f, "PERF".periods p
WHERE f.subject_type = 'performance.company' AND p.label = '2026-02';

-- --- Achievement: Revenue target exceeded, auto-awarded ----------------------

INSERT INTO "PERF".achievements (subject_type, subject_id, badge_id, trigger_reference, awarded_by)
SELECT 'performance.company', '1', b.id, 'KPI Revenue, 2026 Q1: actual 310,000,000 exceeded target 300,000,000', NULL
FROM "PERF".badge_definitions b WHERE b.code = 'TARGET_HIT';

COMMIT;
