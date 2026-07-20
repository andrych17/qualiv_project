-- =============================================================================
-- PERFORMANCE_SCHEMA.sql
-- Performance Management Module — Core Shared Module
-- Schema: PERF  (tenant DB — DB-per-tenant isolation, NO tenant_id column,
--                per CLAUDE.md §4/§7 convention)
--
-- Run against a single tenant database. Idempotent-safe re-run via DROP SCHEMA.
-- Validate with:
--   psql "<conn>" -v ON_ERROR_STOP=1 -f PERFORMANCE_SCHEMA.sql
-- =============================================================================

BEGIN;

DROP SCHEMA IF EXISTS "PERF" CASCADE;
CREATE SCHEMA "PERF";

CREATE EXTENSION IF NOT EXISTS pgcrypto;  -- gen_random_uuid()

-- -----------------------------------------------------------------------------
-- Generic updated_at trigger (applied per-table below)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION "PERF".set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- MASTER / LOOKUP TABLES
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PERF.periods — fiscal period definitions (year / quarter / month), self
-- referencing so a month rolls up into a quarter rolls up into a year.
-- Shared by Budgeting, Targets, KPI Values, Forecast, OKR Cycles.
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".periods (
    id               BIGSERIAL PRIMARY KEY,
    code             VARCHAR(20) NOT NULL UNIQUE,          -- e.g. '2026', '2026-Q3', '2026-07'
    period_type      VARCHAR(10) NOT NULL CHECK (period_type IN ('year','quarter','month')),
    start_date       DATE NOT NULL,
    end_date         DATE NOT NULL,
    parent_period_id BIGINT REFERENCES "PERF".periods(id),
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (end_date >= start_date)
);
CREATE INDEX idx_periods_parent ON "PERF".periods(parent_period_id);
CREATE INDEX idx_periods_type ON "PERF".periods(period_type);
CREATE TRIGGER trg_periods_updated_at BEFORE UPDATE ON "PERF".periods
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.perspectives — tenant-editable Balanced-Scorecard categories
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".perspectives (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(50) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_perspectives_updated_at BEFORE UPDATE ON "PERF".perspectives
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.kpi_definitions — tenant metric library
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".kpi_definitions (
    id             BIGSERIAL PRIMARY KEY,
    uuid           UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    code           VARCHAR(50) NOT NULL UNIQUE,
    name           VARCHAR(150) NOT NULL,
    description    TEXT,
    unit           VARCHAR(20) NOT NULL CHECK (unit IN ('number','percent','currency','ratio')),
    direction      VARCHAR(20) NOT NULL CHECK (direction IN ('higher_is_better','lower_is_better')),
    perspective_id BIGINT REFERENCES "PERF".perspectives(id),
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_kpi_definitions_perspective ON "PERF".kpi_definitions(perspective_id);
CREATE TRIGGER trg_kpi_definitions_updated_at BEFORE UPDATE ON "PERF".kpi_definitions
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.okr_cycles — named OKR periods (e.g. "2026 Q3")
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".okr_cycles (
    id         BIGSERIAL PRIMARY KEY,
    code       VARCHAR(20) NOT NULL UNIQUE,
    name       VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (end_date >= start_date)
);
CREATE TRIGGER trg_okr_cycles_updated_at BEFORE UPDATE ON "PERF".okr_cycles
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.badge_definitions — Achievement rule / badge library
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".badge_definitions (
    id             BIGSERIAL PRIMARY KEY,
    code           VARCHAR(50) NOT NULL UNIQUE,
    name           VARCHAR(100) NOT NULL,
    description    TEXT,
    trigger_type   VARCHAR(30) NOT NULL CHECK (trigger_type IN ('target_hit','okr_completed','streak_on_track')),
    trigger_params JSONB NOT NULL DEFAULT '{}'::jsonb,
    icon           VARCHAR(50),
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_badge_definitions_updated_at BEFORE UPDATE ON "PERF".badge_definitions
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- =============================================================================
-- TRANSACTION TABLES
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PERF.budget_hdrs — budget header. subject_type/subject_id is the standard
-- polymorphic seam (company / department / team / vertical record) used by
-- every Core module in this platform (WNE/DMS/CRM/Schedule).
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".budget_hdrs (
    id                    BIGSERIAL PRIMARY KEY,
    uuid                  UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    subject_type          VARCHAR(100) NOT NULL,
    subject_id            BIGINT NOT NULL,
    name                  VARCHAR(150) NOT NULL,
    period_id             BIGINT NOT NULL REFERENCES "PERF".periods(id),
    status                VARCHAR(20) NOT NULL DEFAULT 'draft'
                              CHECK (status IN ('draft','submitted','approved','locked')),
    version               INT NOT NULL DEFAULT 1,
    owner_id              BIGINT,                 -- references central/tenant users table (no FK: separate concern)
    workflow_instance_id  BIGINT,                  -- informational only; set if routed through a WNE approval workflow
    notes                 TEXT,
    created_by            BIGINT,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_budget_hdrs_subject ON "PERF".budget_hdrs(subject_type, subject_id);
CREATE INDEX idx_budget_hdrs_period ON "PERF".budget_hdrs(period_id);
CREATE INDEX idx_budget_hdrs_status ON "PERF".budget_hdrs(status);
CREATE TRIGGER trg_budget_hdrs_updated_at BEFORE UPDATE ON "PERF".budget_hdrs
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.budget_lines
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".budget_lines (
    id              BIGSERIAL PRIMARY KEY,
    budget_id       BIGINT NOT NULL REFERENCES "PERF".budget_hdrs(id) ON DELETE CASCADE,
    category        VARCHAR(100) NOT NULL,
    period_slice_id BIGINT NOT NULL REFERENCES "PERF".periods(id),   -- typically a 'month' period row
    amount_planned  NUMERIC(18,2) NOT NULL DEFAULT 0,
    notes           TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (budget_id, category, period_slice_id)
);
CREATE INDEX idx_budget_lines_budget ON "PERF".budget_lines(budget_id);
CREATE INDEX idx_budget_lines_period_slice ON "PERF".budget_lines(period_slice_id);
CREATE TRIGGER trg_budget_lines_updated_at BEFORE UPDATE ON "PERF".budget_lines
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.targets — KPI target assignment per subject per period. This IS the
-- "multi-level KPI" mechanism: assign the same KPI to many subjects/levels.
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".targets (
    id            BIGSERIAL PRIMARY KEY,
    kpi_id        BIGINT NOT NULL REFERENCES "PERF".kpi_definitions(id),
    subject_type  VARCHAR(100) NOT NULL,
    subject_id    BIGINT NOT NULL,
    period_id     BIGINT NOT NULL REFERENCES "PERF".periods(id),
    target_value  NUMERIC(18,4) NOT NULL,
    stretch_value NUMERIC(18,4),
    notes         TEXT,
    created_by    BIGINT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (kpi_id, subject_type, subject_id, period_id)
);
CREATE INDEX idx_targets_subject ON "PERF".targets(subject_type, subject_id);
CREATE INDEX idx_targets_period ON "PERF".targets(period_id);
CREATE TRIGGER trg_targets_updated_at BEFORE UPDATE ON "PERF".targets
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.kpi_values — actuals capture (MVP: manual entry; source column reserves
-- room for future automated connectors without a schema change).
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".kpi_values (
    id            BIGSERIAL PRIMARY KEY,
    kpi_id        BIGINT NOT NULL REFERENCES "PERF".kpi_definitions(id),
    subject_type  VARCHAR(100) NOT NULL,
    subject_id    BIGINT NOT NULL,
    period_id     BIGINT NOT NULL REFERENCES "PERF".periods(id),
    actual_value  NUMERIC(18,4) NOT NULL,
    source        VARCHAR(20) NOT NULL DEFAULT 'manual' CHECK (source IN ('manual','system','import')),
    entered_by    BIGINT,
    entered_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (kpi_id, subject_type, subject_id, period_id)
);
CREATE INDEX idx_kpi_values_subject ON "PERF".kpi_values(subject_type, subject_id);
CREATE INDEX idx_kpi_values_period ON "PERF".kpi_values(period_id);
CREATE TRIGGER trg_kpi_values_updated_at BEFORE UPDATE ON "PERF".kpi_values
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.okr_objectives — multi-level via self-referencing parent_okr_id
-- (same alignment pattern as CRM.partners.parent_partner_id).
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".okr_objectives (
    id             BIGSERIAL PRIMARY KEY,
    uuid           UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    cycle_id       BIGINT NOT NULL REFERENCES "PERF".okr_cycles(id),
    subject_type   VARCHAR(100) NOT NULL,
    subject_id     BIGINT NOT NULL,
    parent_okr_id  BIGINT REFERENCES "PERF".okr_objectives(id),
    objective_text TEXT NOT NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'on_track'
                       CHECK (status IN ('on_track','at_risk','off_track','completed')),
    owner_id       BIGINT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_okr_objectives_cycle ON "PERF".okr_objectives(cycle_id);
CREATE INDEX idx_okr_objectives_subject ON "PERF".okr_objectives(subject_type, subject_id);
CREATE INDEX idx_okr_objectives_parent ON "PERF".okr_objectives(parent_okr_id);
CREATE TRIGGER trg_okr_objectives_updated_at BEFORE UPDATE ON "PERF".okr_objectives
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.okr_key_results
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".okr_key_results (
    id            BIGSERIAL PRIMARY KEY,
    okr_id        BIGINT NOT NULL REFERENCES "PERF".okr_objectives(id) ON DELETE CASCADE,
    description   VARCHAR(255) NOT NULL,
    metric_type   VARCHAR(20) NOT NULL CHECK (metric_type IN ('numeric','percent','boolean','milestone')),
    start_value   NUMERIC(18,4) NOT NULL DEFAULT 0,
    current_value NUMERIC(18,4) NOT NULL DEFAULT 0,
    target_value  NUMERIC(18,4) NOT NULL,
    weight        NUMERIC(5,2) NOT NULL DEFAULT 100 CHECK (weight >= 0 AND weight <= 100),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_okr_key_results_okr ON "PERF".okr_key_results(okr_id);
CREATE TRIGGER trg_okr_key_results_updated_at BEFORE UPDATE ON "PERF".okr_key_results
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.scorecard_hdrs
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".scorecard_hdrs (
    id           BIGSERIAL PRIMARY KEY,
    uuid         UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name         VARCHAR(150) NOT NULL,
    subject_type VARCHAR(100) NOT NULL,
    subject_id   BIGINT NOT NULL,
    period_id    BIGINT NOT NULL REFERENCES "PERF".periods(id),
    created_by   BIGINT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_scorecard_hdrs_subject ON "PERF".scorecard_hdrs(subject_type, subject_id);
CREATE INDEX idx_scorecard_hdrs_period ON "PERF".scorecard_hdrs(period_id);
CREATE TRIGGER trg_scorecard_hdrs_updated_at BEFORE UPDATE ON "PERF".scorecard_hdrs
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.scorecard_items — composition only; does not duplicate KPI/OKR values,
-- only weight + computed snapshot (refreshed by VarianceService on read/save).
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".scorecard_items (
    id             BIGSERIAL PRIMARY KEY,
    scorecard_id   BIGINT NOT NULL REFERENCES "PERF".scorecard_hdrs(id) ON DELETE CASCADE,
    perspective_id BIGINT NOT NULL REFERENCES "PERF".perspectives(id),
    metric_type    VARCHAR(10) NOT NULL CHECK (metric_type IN ('kpi','okr')),
    kpi_id         BIGINT REFERENCES "PERF".kpi_definitions(id),
    okr_id         BIGINT REFERENCES "PERF".okr_objectives(id),
    weight         NUMERIC(5,2) NOT NULL CHECK (weight >= 0 AND weight <= 100),
    actual_value   NUMERIC(18,4),
    target_value   NUMERIC(18,4),
    score          NUMERIC(6,2),
    status         VARCHAR(20) CHECK (status IN ('on_track','warning','breach')),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (
        (metric_type = 'kpi' AND kpi_id IS NOT NULL AND okr_id IS NULL) OR
        (metric_type = 'okr' AND okr_id IS NOT NULL AND kpi_id IS NULL)
    )
);
CREATE INDEX idx_scorecard_items_scorecard ON "PERF".scorecard_items(scorecard_id);
CREATE INDEX idx_scorecard_items_perspective ON "PERF".scorecard_items(perspective_id);
CREATE TRIGGER trg_scorecard_items_updated_at BEFORE UPDATE ON "PERF".scorecard_items
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.forecast_hdrs — versioned; a revision creates a new row, never
-- overwrites (same non-destructive principle as DMS document versions).
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".forecast_hdrs (
    id           BIGSERIAL PRIMARY KEY,
    uuid         UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    subject_type VARCHAR(100) NOT NULL,
    subject_id   BIGINT NOT NULL,
    budget_id    BIGINT REFERENCES "PERF".budget_hdrs(id),
    period_id    BIGINT NOT NULL REFERENCES "PERF".periods(id),
    version      INT NOT NULL DEFAULT 1,
    method       VARCHAR(20) NOT NULL DEFAULT 'manual' CHECK (method IN ('manual','trend','statistical')),
    created_by   BIGINT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (subject_type, subject_id, period_id, version)
);
CREATE INDEX idx_forecast_hdrs_subject ON "PERF".forecast_hdrs(subject_type, subject_id);
CREATE INDEX idx_forecast_hdrs_budget ON "PERF".forecast_hdrs(budget_id);
CREATE TRIGGER trg_forecast_hdrs_updated_at BEFORE UPDATE ON "PERF".forecast_hdrs
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.forecast_lines
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".forecast_lines (
    id              BIGSERIAL PRIMARY KEY,
    forecast_id     BIGINT NOT NULL REFERENCES "PERF".forecast_hdrs(id) ON DELETE CASCADE,
    period_slice_id BIGINT NOT NULL REFERENCES "PERF".periods(id),
    forecast_value  NUMERIC(18,2) NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (forecast_id, period_slice_id)
);
CREATE INDEX idx_forecast_lines_forecast ON "PERF".forecast_lines(forecast_id);
CREATE TRIGGER trg_forecast_lines_updated_at BEFORE UPDATE ON "PERF".forecast_lines
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

-- -----------------------------------------------------------------------------
-- PERF.achievements — factual recognition log (MVP: no points/leaderboard)
-- -----------------------------------------------------------------------------
CREATE TABLE "PERF".achievements (
    id               BIGSERIAL PRIMARY KEY,
    subject_type     VARCHAR(100) NOT NULL,
    subject_id       BIGINT NOT NULL,
    badge_id         BIGINT NOT NULL REFERENCES "PERF".badge_definitions(id),
    earned_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    trigger_ref_type VARCHAR(50),         -- e.g. 'kpi_value', 'okr_objective'
    trigger_ref_id   BIGINT,
    awarded_by       BIGINT,              -- NULL = system auto-awarded
    notes            TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_achievements_subject ON "PERF".achievements(subject_type, subject_id);
CREATE INDEX idx_achievements_badge ON "PERF".achievements(badge_id);
CREATE TRIGGER trg_achievements_updated_at BEFORE UPDATE ON "PERF".achievements
    FOR EACH ROW EXECUTE FUNCTION "PERF".set_updated_at();

COMMIT;

-- =============================================================================
-- End of PERFORMANCE_SCHEMA.sql — 16 tables in schema "PERF"
-- (5 master/lookup + 11 transaction/log tables)
-- =============================================================================
