-- =============================================================================
-- Payroll module — schema + example seed data
-- Source spec: app/Modules/Payroll/PAYROLL_SPECS.md (see that file for full
-- rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "PAYROLL" —
--              every table lives inside one tenant's own database (CLAUDE.md
--              §4/§7A), no tenant_id column anywhere.
--
-- HCM is a HARD dependency (§1/§5) — unlike every other Core-to-Core relation
-- in this platform, Payroll does not degrade gracefully without it.
-- `employee_payroll_profiles` is a 1:1 extension of "HCM".employees(id) (real
-- cross-schema FK, Core-to-Core), never duplicating name/employment-status/
-- tenure. NOTE: §4's own storage list DOES list BPJS numbers and bank account
-- fields on employee_payroll_profiles/employee_bank_accounts even though
-- HCM.employees (see HCM_SPECS.sql) already carries bpjs_kesehatan_no /
-- bpjs_ketenagakerjaan_no / bank_name / bank_account_no / bank_account_holder_name
-- columns of its own — this looks like a genuine overlap between the two
-- specs' storage sections, not something this script silently resolves.
-- Implemented faithfully per THIS spec's literal §4 list; flagged here for
-- whoever builds this for real to decide which copy is authoritative.
--
-- Statutory rates are data, not code (§5, "the core architectural decision of
-- this module") — every PTKP/TER/BPJS/severance table below carries
-- `effective_date` + `is_active` and is resolved "as of" the run's period
-- date, never assumed-latest. `employee_payroll_profiles.ptkp_status_code` and
-- `pph21_calculations.ptkp_status_code` deliberately store the STATUS CODE
-- (e.g. 'TK/0'), not a FK to one specific versioned `ptkp_statuses` row — a
-- pinned FK would incorrectly lock an employee to whichever regulation
-- version happened to be active when the FK was set, defeating the entire
-- point of versioning by effective_date.
--
-- Payroll Lock (§3-Admin) is treated with the same real-trigger rigor
-- INVENTORY_SPECS.sql gives stock_ledger and LEGAL_SPECS.sql gives
-- protocol_entries — the spec calls payroll "the single most trust-sensitive
-- number in the whole ERP" (§1), so `payroll_run_lines` gets a trigger that
-- blocks any further UPDATE once `is_locked` flips true (the flip itself, and
-- all edits before it, remain allowed). `payroll_access_logs` stays
-- comment-only immutable, matching the general audit-log convention used for
-- dms.access_logs/acct.audit_logs elsewhere (no hard trigger) — Payroll Lock
-- is the one place this module singles out as exceptional, not every table.
--
-- Illustrative statutory figures (TER rates, BPJS %, severance multipliers)
-- used in the schema comments and seed data are for demonstration ONLY — they
-- are NOT verified-current Indonesian rates and must never be treated as a
-- compliance source. The whole point of the versioned-table design is that a
-- real implementation loads actual current figures as data, not from this file.
--
-- This file is a reference/planning script, not a Laravel migration — port
-- each section below into `database/migrations/tenant/*.php` per the
-- suggested build order in §5.
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"AIINSIGHT"/"ACCOUNTING"/"CRM"/"DMS"/"HCM"/"INVENTORY"/"LEGAL"/"CUSTOMFIELDS".
CREATE SCHEMA IF NOT EXISTS "PAYROLL";

-- -----------------------------------------------------------------------------
-- §3B / §4. Setup masters — independent lookups first
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PAYROLL".jkk_risk_categories (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(20) NOT NULL UNIQUE,          -- very_low .. very_high
    name            VARCHAR(100) NOT NULL,
    employer_rate   NUMERIC(5, 3) NOT NULL,               -- % contribution, JKK is employer-only
    is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payroll_calendars (
    id                          BIGSERIAL PRIMARY KEY,
    name                        VARCHAR(150) NOT NULL,
    pay_frequency               VARCHAR(15) NOT NULL CHECK (pay_frequency IN ('monthly', 'semi_monthly', 'weekly', 'daily')),
    cutoff_rule                 VARCHAR(255),              -- e.g. "26th of prior month to 25th of current month"
    pay_date_rule                VARCHAR(255),             -- e.g. "last working day of month"
    shift_earlier_on_holiday        BOOLEAN NOT NULL DEFAULT TRUE, -- reads Schedule's calendar if installed, else a simple weekday check (§3B)
    is_active                          BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payroll_components (
    id                      BIGSERIAL PRIMARY KEY,
    code                    VARCHAR(30) NOT NULL UNIQUE,
    name                    VARCHAR(150) NOT NULL,
    type                    VARCHAR(10) NOT NULL CHECK (type IN ('earning', 'deduction')),
    category                VARCHAR(15) NOT NULL CHECK (category IN ('fixed', 'formula', 'statutory', 'variable_input')),
    calculation_basis       VARCHAR(255),                 -- flat amount / "% of Basic Salary" / statutory engine reference — descriptive, not executable here
    is_taxable              BOOLEAN NOT NULL DEFAULT TRUE, -- counts toward PPh 21 gross
    is_bpjs_basis           BOOLEAN NOT NULL DEFAULT FALSE, -- counts toward BPJS contribution base
    gl_account_placeholder  VARCHAR(50),                  -- nullable, Future Version accounting export
    is_system_defined       BOOLEAN NOT NULL DEFAULT FALSE, -- statutory components (PPh21/BPJS) are read-only rows (§3B)
    is_active               BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".grades (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".deduction_rule_configs (
    id                              BIGSERIAL PRIMARY KEY,
    code                            VARCHAR(30) NOT NULL UNIQUE,
    name                            VARCHAR(150) NOT NULL,
    deduction_type                  VARCHAR(10) NOT NULL CHECK (deduction_type IN ('loan', 'advance', 'recurring')),
    insufficient_funds_behavior     VARCHAR(20) NOT NULL DEFAULT 'skip_and_roll_forward'
                                        CHECK (insufficient_funds_behavior IN ('skip_and_roll_forward', 'partial_deduction')),
    is_active                       BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".loan_types (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(30) NOT NULL UNIQUE,
    name                VARCHAR(150) NOT NULL,
    interest_method     VARCHAR(10) NOT NULL DEFAULT 'none' CHECK (interest_method IN ('none', 'flat')),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".reimbursement_categories (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".bank_master (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(20) NOT NULL UNIQUE,          -- e.g. BCA
    name            VARCHAR(150) NOT NULL,
    file_format     VARCHAR(10) NOT NULL DEFAULT 'csv' CHECK (file_format IN ('csv', 'excel')),
    template_spec   JSONB,                                -- column mapping / template description for the export
    is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

-- -----------------------------------------------------------------------------
-- §3B / §4. Salary structures, and the Payroll Group they + calendars feed
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PAYROLL".salary_structures (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    grade_id    BIGINT REFERENCES "PAYROLL".grades (id), -- optional label for grouping, NOT the HCM Compensation banding system (§4)
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".salary_structure_components (
    id                  BIGSERIAL PRIMARY KEY,
    salary_structure_id BIGINT NOT NULL REFERENCES "PAYROLL".salary_structures (id) ON DELETE CASCADE,
    component_id        BIGINT NOT NULL REFERENCES "PAYROLL".payroll_components (id),
    default_amount       NUMERIC(18, 2),                  -- for fixed components
    default_formula          VARCHAR(255),                -- e.g. "% of Basic Salary", for formula components
    seq                          INTEGER NOT NULL DEFAULT 0,
    UNIQUE (salary_structure_id, component_id)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payroll_groups (
    id                              BIGSERIAL PRIMARY KEY,
    name                            VARCHAR(150) NOT NULL,
    legal_entity_ref                VARCHAR(150),          -- free-text/lookup, no separate Legal-Entity module yet (§3B)
    payroll_calendar_id             BIGINT NOT NULL REFERENCES "PAYROLL".payroll_calendars (id),
    default_salary_structure_id     BIGINT REFERENCES "PAYROLL".salary_structures (id),
    default_jkk_risk_category_id    BIGINT REFERENCES "PAYROLL".jkk_risk_categories (id),
    is_active                       BOOLEAN NOT NULL DEFAULT TRUE
);

-- -----------------------------------------------------------------------------
-- §3B / §4. Versioned statutory rule tables — every one carries effective_date
-- + is_active; the calculation engine always resolves "as of" the run's
-- period date (§5), never assumes the latest row, and rows are never
-- hard-deleted (a superseded version stays for historical recalculation).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PAYROLL".ptkp_statuses (
    id                      BIGSERIAL PRIMARY KEY,
    code                    VARCHAR(5) NOT NULL,          -- TK/0, TK/1, ..., K/3
    annual_ptkp_amount      NUMERIC(18, 2) NOT NULL,
    effective_date          DATE NOT NULL,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (code, effective_date)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".ter_categories (
    id                  BIGSERIAL PRIMARY KEY,
    category            CHAR(1) NOT NULL CHECK (category IN ('A', 'B', 'C')),
    ptkp_status_code    VARCHAR(5) NOT NULL,              -- which PTKP status codes map into this TER category
    effective_date      DATE NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (ptkp_status_code, effective_date)             -- a status maps to exactly one category per version
);

CREATE TABLE IF NOT EXISTS "PAYROLL".ter_rate_brackets (
    id                  BIGSERIAL PRIMARY KEY,
    category            CHAR(1) NOT NULL CHECK (category IN ('A', 'B', 'C')),
    income_from         NUMERIC(18, 2) NOT NULL,
    income_to           NUMERIC(18, 2),                   -- NULL = no upper bound
    rate                NUMERIC(5, 3) NOT NULL,           -- effective monthly withholding rate, %
    effective_date      DATE NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".ter_daily_rates (
    id                  BIGSERIAL PRIMARY KEY,
    income_from         NUMERIC(18, 2) NOT NULL,
    income_to           NUMERIC(18, 2),
    rate                NUMERIC(5, 3) NOT NULL,
    effective_date      DATE NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".pph21_progressive_brackets (
    id                  BIGSERIAL PRIMARY KEY,
    bracket_from        NUMERIC(18, 2) NOT NULL,
    bracket_to          NUMERIC(18, 2),
    rate                NUMERIC(5, 2) NOT NULL,           -- annual Pasal 17 progressive rate, %
    effective_date      DATE NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".overtime_multiplier_rules (
    id                  BIGSERIAL PRIMARY KEY,
    day_type            VARCHAR(10) NOT NULL CHECK (day_type IN ('weekday', 'weekend', 'holiday')),
    hour_tier           VARCHAR(30) NOT NULL,              -- e.g. 'first_hour', 'subsequent_hours' per Kepmenaker 1/173 tiers
    multiplier           NUMERIC(4, 2) NOT NULL,
    effective_date          DATE NOT NULL,
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (day_type, hour_tier, effective_date)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".bpjs_kesehatan_rules (
    id                  BIGSERIAL PRIMARY KEY,
    employer_rate       NUMERIC(5, 3) NOT NULL,
    employee_rate       NUMERIC(5, 3) NOT NULL,
    wage_cap            NUMERIC(18, 2) NOT NULL,
    effective_date      DATE NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".bpjs_ketenagakerjaan_rules (
    id                      BIGSERIAL PRIMARY KEY,
    sub_program             VARCHAR(3) NOT NULL CHECK (sub_program IN ('JHT', 'JP', 'JKK', 'JKM', 'JKP')),
    employer_rate           NUMERIC(5, 3) NOT NULL DEFAULT 0,
    employee_rate           NUMERIC(5, 3) NOT NULL DEFAULT 0,
    wage_cap                NUMERIC(18, 2),               -- nullable; only JP has one under current rules (§5)
    jkk_risk_category_id    BIGINT REFERENCES "PAYROLL".jkk_risk_categories (id), -- only meaningful for sub_program = 'JKK'
    effective_date          DATE NOT NULL,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PAYROLL".severance_rule_tables (
    id                      BIGSERIAL PRIMARY KEY,
    severance_component     VARCHAR(10) NOT NULL CHECK (severance_component IN ('pesangon', 'upmk', 'uph')), -- Uang Pesangon / Penghargaan Masa Kerja / Penggantian Hak
    tenure_years_from       NUMERIC(4, 1) NOT NULL,
    tenure_years_to         NUMERIC(4, 1),
    multiplier_months       NUMERIC(4, 1) NOT NULL,       -- months of salary
    effective_date          DATE NOT NULL,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE
);

-- -----------------------------------------------------------------------------
-- §4. Employee-level tables — 1:1 extensions of "HCM".employees(id)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PAYROLL".employee_payroll_profiles (
    id                          BIGSERIAL PRIMARY KEY,
    employee_id                 BIGINT NOT NULL UNIQUE REFERENCES "HCM".employees (id), -- cross-schema FK, Core-to-Core (§5)
    ptkp_status_code            VARCHAR(5) NOT NULL,       -- resolved "as of date" against ptkp_statuses by the engine, never pinned (see header)
    bpjs_kesehatan_no           VARCHAR(30),               -- see header note re: overlap with HCM.employees' own copy
    bpjs_ketenagakerjaan_no     VARCHAR(30),
    payroll_group_id            BIGINT NOT NULL REFERENCES "PAYROLL".payroll_groups (id),
    salary_structure_id         BIGINT REFERENCES "PAYROLL".salary_structures (id), -- optional override of the group default
    jkk_risk_category_id        BIGINT REFERENCES "PAYROLL".jkk_risk_categories (id),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "PAYROLL".employee_bank_accounts (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id             BIGINT NOT NULL REFERENCES "HCM".employees (id), -- see header note re: overlap with HCM.employees' own bank fields
    bank_name               VARCHAR(100) NOT NULL,
    account_number          VARCHAR(50) NOT NULL,
    account_holder_name     VARCHAR(150) NOT NULL,
    is_primary               BOOLEAN NOT NULL DEFAULT TRUE
);

COMMIT;

-- =============================================================================
-- §3C–§3-Admin / §4. Transaction / run tables
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "PAYROLL".payroll_periods (
    id                      BIGSERIAL PRIMARY KEY,
    payroll_group_id        BIGINT NOT NULL REFERENCES "PAYROLL".payroll_groups (id),
    period_start             DATE NOT NULL,
    period_end                  DATE NOT NULL,
    cutoff_date                    DATE,
    scheduled_pay_date                 DATE NOT NULL,
    status                                VARCHAR(12) NOT NULL DEFAULT 'open'
                                              CHECK (status IN ('open', 'processing', 'approved', 'paid', 'locked')),
    UNIQUE (payroll_group_id, period_start, period_end)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payroll_runs (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    run_type                VARCHAR(10) NOT NULL CHECK (run_type IN ('regular', 'off_cycle', 'thr', 'bonus', 'final', 'adjustment')),
    payroll_group_id        BIGINT NOT NULL REFERENCES "PAYROLL".payroll_groups (id),
    payroll_period_id       BIGINT NOT NULL REFERENCES "PAYROLL".payroll_periods (id),
    status                  VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'approved', 'paid', 'locked')),
    reason_code              VARCHAR(100),                -- required for off_cycle (§3E), app-enforced
    adjustment_of_run_id         BIGINT REFERENCES "PAYROLL".payroll_runs (id), -- set for run_type = 'adjustment' (§3I)
    workflow_instance_id             VARCHAR(100),         -- informational reference into WNE, NOT a FK (§4)
    created_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                             TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (id <> adjustment_of_run_id)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payroll_run_lines (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing, future ESS portal (§5)
    run_id              BIGINT NOT NULL REFERENCES "PAYROLL".payroll_runs (id),
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees (id),
    gross_amount        NUMERIC(18, 2) NOT NULL DEFAULT 0,
    total_deductions    NUMERIC(18, 2) NOT NULL DEFAULT 0,
    net_amount          NUMERIC(18, 2) NOT NULL DEFAULT 0, -- "net < 0 blocks submission" (§3D) is an app-layer gate at the state transition, not a DB CHECK — a draft line is allowed to be transiently negative while under review
    employer_cost       NUMERIC(18, 2) NOT NULL DEFAULT 0,
    is_locked            BOOLEAN NOT NULL DEFAULT FALSE   -- flips true when the run pays (Payroll Lock, §3-Admin); see trigger below
);

-- Payroll Lock (§3-Admin): "no direct edit is possible at the app layer" once
-- paid — given real DB teeth here, matching the stock_ledger/protocol_entries
-- precedent, since this spec explicitly calls payroll the platform's most
-- trust-sensitive number. Only blocks edits made AFTER the row is already
-- locked; the lock-flip itself (false -> true) and every edit before it are
-- unaffected.
CREATE OR REPLACE FUNCTION "PAYROLL".prevent_locked_run_line_edit() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'PAYROLL.payroll_run_lines % is locked — corrections must go through a Payroll Adjustment run, never a direct edit (PAYROLL_SPECS.md §3I/§3-Admin)', OLD.id;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_payroll_run_lines_lock ON "PAYROLL".payroll_run_lines;
CREATE TRIGGER trg_payroll_run_lines_lock
    BEFORE UPDATE ON "PAYROLL".payroll_run_lines
    FOR EACH ROW WHEN (OLD.is_locked)
    EXECUTE FUNCTION "PAYROLL".prevent_locked_run_line_edit();

CREATE TABLE IF NOT EXISTS "PAYROLL".payroll_run_line_components (
    id              BIGSERIAL PRIMARY KEY,
    run_line_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines (id) ON DELETE CASCADE,
    component_id    BIGINT NOT NULL REFERENCES "PAYROLL".payroll_components (id),
    amount          NUMERIC(18, 2) NOT NULL,
    description     VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".pph21_calculations (
    id                  BIGSERIAL PRIMARY KEY,
    run_line_id         BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines (id),
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees (id),
    calculation_mode    VARCHAR(25) NOT NULL
                            CHECK (calculation_mode IN ('monthly_ter', 'annualized_reconciliation', 'irregular_income', 'final_severance')),
    gross_income        NUMERIC(18, 2) NOT NULL,
    ptkp_status_code    VARCHAR(5) NOT NULL,
    ter_category        CHAR(1) CHECK (ter_category IN ('A', 'B', 'C')), -- nullable for non-TER modes
    applied_rate        NUMERIC(5, 3),
    tax_amount          NUMERIC(18, 2) NOT NULL,
    calculated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "PAYROLL".bpjs_kesehatan_contributions (
    id                  BIGSERIAL PRIMARY KEY,
    run_line_id         BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines (id),
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees (id),
    wage_base           NUMERIC(18, 2) NOT NULL,
    capped_wage_base    NUMERIC(18, 2) NOT NULL,
    employer_amount     NUMERIC(18, 2) NOT NULL,
    employee_amount     NUMERIC(18, 2) NOT NULL
);

CREATE TABLE IF NOT EXISTS "PAYROLL".bpjs_ketenagakerjaan_contributions (
    id                  BIGSERIAL PRIMARY KEY,
    run_line_id         BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines (id),
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees (id),
    sub_program         VARCHAR(3) NOT NULL CHECK (sub_program IN ('JHT', 'JP', 'JKK', 'JKM', 'JKP')),
    wage_base           NUMERIC(18, 2) NOT NULL,
    capped_wage_base    NUMERIC(18, 2) NOT NULL,
    employer_amount     NUMERIC(18, 2) NOT NULL,
    employee_amount     NUMERIC(18, 2) NOT NULL,
    UNIQUE (run_line_id, sub_program)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".thr_calculations (
    id                          BIGSERIAL PRIMARY KEY,
    run_line_id                 BIGINT REFERENCES "PAYROLL".payroll_run_lines (id), -- nullable: may be computed before the run line exists
    employee_id                 BIGINT NOT NULL REFERENCES "HCM".employees (id),
    tenure_months                INTEGER NOT NULL,
    monthly_salary_basis             NUMERIC(18, 2) NOT NULL,
    proration_factor                    NUMERIC(5, 4) NOT NULL DEFAULT 1, -- < 1 for < 12 months tenure (§3F)
    thr_amount                             NUMERIC(18, 2) NOT NULL,
    religious_holiday_date                    DATE,
    created_at                                   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "PAYROLL".severance_calculations (
    id                      BIGSERIAL PRIMARY KEY,
    run_line_id              BIGINT REFERENCES "PAYROLL".payroll_run_lines (id),
    employee_id                 BIGINT NOT NULL REFERENCES "HCM".employees (id),
    termination_reason              VARCHAR(25) NOT NULL
                                        CHECK (termination_reason IN ('resignation', 'termination_with_cause', 'redundancy', 'retirement', 'contract_end', 'death')),
    tenure_years                       NUMERIC(4, 1) NOT NULL,
    pesangon_amount                       NUMERIC(18, 2) NOT NULL DEFAULT 0,
    upmk_amount                              NUMERIC(18, 2) NOT NULL DEFAULT 0,
    uph_amount                                  NUMERIC(18, 2) NOT NULL DEFAULT 0,
    total_amount                                   NUMERIC(18, 2) GENERATED ALWAYS AS (pesangon_amount + upmk_amount + uph_amount) STORED,
    created_at                                        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "PAYROLL".overtime_entries (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees (id),
    payroll_period_id   BIGINT NOT NULL REFERENCES "PAYROLL".payroll_periods (id),
    work_date           DATE NOT NULL,
    hours               NUMERIC(6, 2) NOT NULL CHECK (hours > 0),
    day_type            VARCHAR(10) NOT NULL CHECK (day_type IN ('weekday', 'weekend', 'holiday')),
    hourly_rate         NUMERIC(18, 2) NOT NULL,          -- Kepmenaker 1/173 x monthly wage
    multiplier          NUMERIC(4, 2) NOT NULL,
    amount              NUMERIC(18, 2) NOT NULL,
    source              VARCHAR(15) NOT NULL DEFAULT 'hcm_attendance' CHECK (source IN ('hcm_attendance', 'manual')) -- MVP default is automatic from HCM.attendance_logs (§2)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".variable_earning_entries (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees (id),
    payroll_period_id   BIGINT NOT NULL REFERENCES "PAYROLL".payroll_periods (id),
    description         VARCHAR(255) NOT NULL,
    amount              NUMERIC(18, 2) NOT NULL
);

CREATE TABLE IF NOT EXISTS "PAYROLL".commission_entries (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees (id),
    payroll_period_id   BIGINT NOT NULL REFERENCES "PAYROLL".payroll_periods (id),
    description         VARCHAR(255),
    amount              NUMERIC(18, 2) NOT NULL
);

CREATE TABLE IF NOT EXISTS "PAYROLL".reimbursement_requests (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees (id),
    category_id         BIGINT REFERENCES "PAYROLL".reimbursement_categories (id),
    amount              NUMERIC(18, 2) NOT NULL,
    description         VARCHAR(255),
    status              VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'paid')),
    payroll_period_id   BIGINT REFERENCES "PAYROLL".payroll_periods (id), -- which period pays it out; nullable until scheduled
    requested_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    reviewed_by         BIGINT REFERENCES users (id),
    reviewed_at         TIMESTAMPTZ
    -- Receipt/proof attaches via DMS (subject_type = 'payroll.reimbursement_requests'), not a column here (§3K).
);

CREATE TABLE IF NOT EXISTS "PAYROLL".employee_loans (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id             BIGINT NOT NULL REFERENCES "HCM".employees (id),
    loan_type_id             BIGINT NOT NULL REFERENCES "PAYROLL".loan_types (id),
    principal_amount             NUMERIC(18, 2) NOT NULL,
    term_months                     INTEGER NOT NULL,
    monthly_installment                NUMERIC(18, 2) NOT NULL,
    remaining_balance                     NUMERIC(18, 2) NOT NULL,
    status                                    VARCHAR(12) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'completed', 'written_off')),
    started_at                                  DATE NOT NULL DEFAULT CURRENT_DATE
    -- Loan agreement document attaches via DMS (subject_type = 'payroll.employee_loans'), not a column here (§3K).
);

CREATE TABLE IF NOT EXISTS "PAYROLL".loan_installments (
    id                  BIGSERIAL PRIMARY KEY,
    loan_id             BIGINT NOT NULL REFERENCES "PAYROLL".employee_loans (id),
    payroll_period_id   BIGINT NOT NULL REFERENCES "PAYROLL".payroll_periods (id),
    due_amount          NUMERIC(18, 2) NOT NULL,
    deducted_amount     NUMERIC(18, 2) NOT NULL DEFAULT 0,
    status              VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'deducted', 'skipped', 'partial')),
    UNIQUE (loan_id, payroll_period_id)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".salary_advances (
    id                              BIGSERIAL PRIMARY KEY,
    employee_id                     BIGINT NOT NULL REFERENCES "HCM".employees (id),
    amount                          NUMERIC(18, 2) NOT NULL,
    deduction_payroll_period_id     BIGINT REFERENCES "PAYROLL".payroll_periods (id),
    status                          VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'deducted', 'cancelled')),
    requested_at                    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payment_batches (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    payroll_period_id   BIGINT NOT NULL REFERENCES "PAYROLL".payroll_periods (id),
    bank_master_id      BIGINT NOT NULL REFERENCES "PAYROLL".bank_master (id),
    status              VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'exported', 'reconciled')),
    exported_at         TIMESTAMPTZ,
    file_object_key     VARCHAR(500),                     -- exported bank file reference (R2)
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payment_batch_lines (
    id              BIGSERIAL PRIMARY KEY,
    batch_id        BIGINT NOT NULL REFERENCES "PAYROLL".payment_batches (id) ON DELETE CASCADE,
    run_line_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines (id),
    amount          NUMERIC(18, 2) NOT NULL,
    status          VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'failed'))
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payment_reconciliations (
    id                  BIGSERIAL PRIMARY KEY,
    batch_line_id       BIGINT NOT NULL REFERENCES "PAYROLL".payment_batch_lines (id),
    reconciled_status   VARCHAR(10) NOT NULL CHECK (reconciled_status IN ('paid', 'failed')),
    failure_reason      VARCHAR(255),
    reconciled_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    reconciled_by       BIGINT REFERENCES users (id)
);

CREATE TABLE IF NOT EXISTS "PAYROLL".payroll_access_logs (
    id              BIGSERIAL PRIMARY KEY,
    action          VARCHAR(30) NOT NULL CHECK (action IN (
                        'view_payslip', 'run_created', 'run_submitted', 'run_approved', 'run_paid',
                        'run_locked', 'adjustment_created', 'regulatory_rule_changed', 'employee_salary_changed'
                    )),
    actor_id        BIGINT REFERENCES users (id),
    subject_type    VARCHAR(50),
    subject_id      VARCHAR(100),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_payroll_access_logs_subject ON "PAYROLL".payroll_access_logs (subject_type, subject_id);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Setup for one Payroll Group (Monthly Staff), current-year statutory rule
-- rows (PTKP/TER/BPJS), a Regular Payroll run for HCM_SPECS.sql's employee
-- (Siti Aminah, EMP-0001) with overtime and a loan installment, a THR run for
-- the same employee, and a Final Payroll run with severance for a second
-- (newly seeded, terminated) employee, Andi Wijaya.
--
-- Assumes HCM_SPECS.sql has already run (for Siti Aminah / EMP-0001) and
-- Laravel's own `users` table has the demo accounts from
-- database/seeders/DatabaseSeeder.php. Andi Wijaya is inserted directly into
-- "HCM".employees here since HCM_SPECS.sql's own seed doesn't include a
-- terminated employee to exercise Final Payroll against.
--
-- All rate/bracket/multiplier figures below are illustrative placeholders
-- (see the file header) — round numbers chosen for a readable worked example,
-- not verified-current Indonesian statutory rates.
--
-- Header-creating and dependent statements are kept SEPARATE top-level
-- statements throughout (see DMS_SPECS.sql's header note) to avoid
-- PostgreSQL's same-statement, same-snapshot limitation on data-modifying
-- CTEs.
-- =============================================================================

BEGIN;

INSERT INTO "PAYROLL".jkk_risk_categories (code, name, employer_rate) VALUES
    ('low', 'Low Risk (Office)', 0.24);

INSERT INTO "PAYROLL".payroll_calendars (name, pay_frequency, cutoff_rule, pay_date_rule)
VALUES ('Monthly - PT Demo Legal Firm', 'monthly', '26th of prior month to 25th of current month', 'Last working day of month');

INSERT INTO "PAYROLL".payroll_components (code, name, type, category, is_taxable, is_bpjs_basis, is_system_defined) VALUES
    ('BASIC_SALARY', 'Basic Salary', 'earning', 'fixed', TRUE, TRUE, FALSE),
    ('POSITION_ALLOWANCE', 'Position Allowance', 'earning', 'fixed', TRUE, TRUE, FALSE),
    ('OVERTIME', 'Overtime', 'earning', 'variable_input', TRUE, FALSE, FALSE),
    ('PPH21', 'PPh 21', 'deduction', 'statutory', FALSE, FALSE, TRUE),
    ('BPJS_KESEHATAN_EE', 'BPJS Kesehatan (Employee)', 'deduction', 'statutory', FALSE, FALSE, TRUE),
    ('BPJS_JHT_EE', 'BPJS JHT (Employee)', 'deduction', 'statutory', FALSE, FALSE, TRUE),
    ('LOAN_INSTALLMENT', 'Loan Installment', 'deduction', 'variable_input', FALSE, FALSE, FALSE);

INSERT INTO "PAYROLL".salary_structures (name) VALUES ('Staff Grade 2');

WITH ss AS (SELECT id FROM "PAYROLL".salary_structures WHERE name = 'Staff Grade 2')
INSERT INTO "PAYROLL".salary_structure_components (salary_structure_id, component_id, default_amount, seq)
SELECT ss.id, c.id, v.amount, v.seq
FROM ss, "PAYROLL".payroll_components c, (VALUES
    ('BASIC_SALARY', 15000000, 10), ('POSITION_ALLOWANCE', 1000000, 20)
) AS v(code, amount, seq)
WHERE c.code = v.code;

INSERT INTO "PAYROLL".payroll_groups (name, legal_entity_ref, payroll_calendar_id, default_salary_structure_id, default_jkk_risk_category_id)
SELECT 'Monthly Staff - PT Demo Legal Firm', 'PT Demo Legal Firm', cal.id, ss.id, jkk.id
FROM "PAYROLL".payroll_calendars cal, "PAYROLL".salary_structures ss, "PAYROLL".jkk_risk_categories jkk
WHERE cal.name = 'Monthly - PT Demo Legal Firm' AND ss.name = 'Staff Grade 2' AND jkk.code = 'low';

INSERT INTO "PAYROLL".loan_types (code, name) VALUES ('EMPLOYEE_LOAN', 'Employee Loan');

-- --- Versioned statutory rules, effective 2026-01-01 -------------------------

INSERT INTO "PAYROLL".ptkp_statuses (code, annual_ptkp_amount, effective_date) VALUES
    ('TK/0', 54000000, '2026-01-01'), ('K/1', 63000000, '2026-01-01');

INSERT INTO "PAYROLL".ter_categories (category, ptkp_status_code, effective_date) VALUES
    ('A', 'TK/0', '2026-01-01'), ('B', 'K/1', '2026-01-01');

INSERT INTO "PAYROLL".ter_rate_brackets (category, income_from, income_to, rate, effective_date) VALUES
    ('B', 16000000, 17000000, 8.0, '2026-01-01');

INSERT INTO "PAYROLL".overtime_multiplier_rules (day_type, hour_tier, multiplier, effective_date) VALUES
    ('weekday', 'first_hour', 1.5, '2026-01-01');

INSERT INTO "PAYROLL".bpjs_kesehatan_rules (employer_rate, employee_rate, wage_cap, effective_date)
VALUES (4.0, 1.0, 12000000, '2026-01-01');

INSERT INTO "PAYROLL".bpjs_ketenagakerjaan_rules (sub_program, employer_rate, employee_rate, wage_cap, jkk_risk_category_id, effective_date)
SELECT 'JHT', 3.7, 2.0, NULL::numeric, NULL::bigint, DATE '2026-01-01'
UNION ALL
SELECT 'JKK', jkk.employer_rate, 0, NULL::numeric, jkk.id, DATE '2026-01-01' FROM "PAYROLL".jkk_risk_categories jkk WHERE jkk.code = 'low';

INSERT INTO "PAYROLL".severance_rule_tables (severance_component, tenure_years_from, tenure_years_to, multiplier_months, effective_date) VALUES
    ('pesangon', 6, 7, 7, '2026-01-01'),
    ('upmk', 6, 9, 2, '2026-01-01');

-- --- Employee payroll profile for Siti Aminah (HCM_SPECS.sql's EMP-0001) ----

INSERT INTO "PAYROLL".employee_payroll_profiles (employee_id, ptkp_status_code, payroll_group_id, salary_structure_id, jkk_risk_category_id)
SELECT e.id, 'K/1', pg.id, ss.id, jkk.id
FROM "HCM".employees e, "PAYROLL".payroll_groups pg, "PAYROLL".salary_structures ss, "PAYROLL".jkk_risk_categories jkk
WHERE e.employee_no = 'EMP-0001' AND pg.name = 'Monthly Staff - PT Demo Legal Firm' AND ss.name = 'Staff Grade 2' AND jkk.code = 'low';

INSERT INTO "PAYROLL".employee_loans (employee_id, loan_type_id, principal_amount, term_months, monthly_installment, remaining_balance)
SELECT e.id, lt.id, 6000000, 12, 500000, 6000000
FROM "HCM".employees e, "PAYROLL".loan_types lt
WHERE e.employee_no = 'EMP-0001' AND lt.code = 'EMPLOYEE_LOAN';

-- --- Payroll period for March 2026 -------------------------------------------

INSERT INTO "PAYROLL".payroll_periods (payroll_group_id, period_start, period_end, cutoff_date, scheduled_pay_date)
SELECT id, '2026-02-26', '2026-03-25', '2026-03-25', '2026-03-31'
FROM "PAYROLL".payroll_groups WHERE name = 'Monthly Staff - PT Demo Legal Firm';

-- --- Overtime input for the period -------------------------------------------

INSERT INTO "PAYROLL".overtime_entries (employee_id, payroll_period_id, work_date, hours, day_type, hourly_rate, multiplier, amount)
SELECT e.id, pp.id, '2026-03-10', 2, 'weekday', 86705.20, 1.5, 260115.60
FROM "HCM".employees e, "PAYROLL".payroll_periods pp
WHERE e.employee_no = 'EMP-0001' AND pp.period_start = '2026-02-26';

-- --- Regular Payroll run for March 2026 --------------------------------------

INSERT INTO "PAYROLL".payroll_runs (run_type, payroll_group_id, payroll_period_id, status)
SELECT 'regular', pg.id, pp.id, 'paid'
FROM "PAYROLL".payroll_groups pg, "PAYROLL".payroll_periods pp
WHERE pg.name = 'Monthly Staff - PT Demo Legal Firm' AND pp.period_start = '2026-02-26';

INSERT INTO "PAYROLL".payroll_run_lines (run_id, employee_id, gross_amount, total_deductions, net_amount, employer_cost, is_locked)
SELECT r.id, e.id, 16260000, 2240800, 14019200, 17370400, TRUE
FROM "PAYROLL".payroll_runs r, "HCM".employees e
WHERE r.run_type = 'regular' AND e.employee_no = 'EMP-0001';

INSERT INTO "PAYROLL".payroll_run_line_components (run_line_id, component_id, amount)
SELECT rl.id, c.id, v.amount
FROM "PAYROLL".payroll_run_lines rl
JOIN "PAYROLL".payroll_runs r ON r.id = rl.run_id AND r.run_type = 'regular'
CROSS JOIN "PAYROLL".payroll_components c
JOIN (VALUES
    ('BASIC_SALARY', 15000000), ('POSITION_ALLOWANCE', 1000000), ('OVERTIME', 260000),
    ('PPH21', -1300800), ('BPJS_KESEHATAN_EE', -120000), ('BPJS_JHT_EE', -320000), ('LOAN_INSTALLMENT', -500000)
) AS v(code, amount) ON v.code = c.code;

INSERT INTO "PAYROLL".pph21_calculations (run_line_id, employee_id, calculation_mode, gross_income, ptkp_status_code, ter_category, applied_rate, tax_amount)
SELECT rl.id, e.id, 'monthly_ter', 16260000, 'K/1', 'B', 8.0, 1300800
FROM "PAYROLL".payroll_run_lines rl
JOIN "PAYROLL".payroll_runs r ON r.id = rl.run_id AND r.run_type = 'regular'
JOIN "HCM".employees e ON e.id = rl.employee_id;

INSERT INTO "PAYROLL".bpjs_kesehatan_contributions (run_line_id, employee_id, wage_base, capped_wage_base, employer_amount, employee_amount)
SELECT rl.id, e.id, 16000000, 12000000, 480000, 120000
FROM "PAYROLL".payroll_run_lines rl
JOIN "PAYROLL".payroll_runs r ON r.id = rl.run_id AND r.run_type = 'regular'
JOIN "HCM".employees e ON e.id = rl.employee_id;

INSERT INTO "PAYROLL".bpjs_ketenagakerjaan_contributions (run_line_id, employee_id, sub_program, wage_base, capped_wage_base, employer_amount, employee_amount)
SELECT rl.id, e.id, 'JHT', 16000000, 16000000, 592000, 320000
FROM "PAYROLL".payroll_run_lines rl
JOIN "PAYROLL".payroll_runs r ON r.id = rl.run_id AND r.run_type = 'regular'
JOIN "HCM".employees e ON e.id = rl.employee_id
UNION ALL
SELECT rl.id, e.id, 'JKK', 16000000, 16000000, 38400, 0
FROM "PAYROLL".payroll_run_lines rl
JOIN "PAYROLL".payroll_runs r ON r.id = rl.run_id AND r.run_type = 'regular'
JOIN "HCM".employees e ON e.id = rl.employee_id;

INSERT INTO "PAYROLL".loan_installments (loan_id, payroll_period_id, due_amount, deducted_amount, status)
SELECT el.id, pp.id, 500000, 500000, 'deducted'
FROM "PAYROLL".employee_loans el, "HCM".employees e, "PAYROLL".payroll_periods pp
WHERE el.employee_id = e.id AND e.employee_no = 'EMP-0001' AND pp.period_start = '2026-02-26';

WITH bm AS (
    INSERT INTO "PAYROLL".bank_master (code, name) VALUES ('BCA', 'Bank BCA') RETURNING id
)
INSERT INTO "PAYROLL".payment_batches (payroll_period_id, bank_master_id, status, exported_at)
SELECT pp.id, bm.id, 'reconciled', now()
FROM "PAYROLL".payroll_periods pp, bm
WHERE pp.period_start = '2026-02-26';

WITH batch AS (
    SELECT pb.id FROM "PAYROLL".payment_batches pb
    JOIN "PAYROLL".payroll_periods pp ON pp.id = pb.payroll_period_id AND pp.period_start = '2026-02-26'
), line AS (
    INSERT INTO "PAYROLL".payment_batch_lines (batch_id, run_line_id, amount, status)
    SELECT batch.id, rl.id, rl.net_amount, 'paid'
    FROM batch, "PAYROLL".payroll_run_lines rl
    JOIN "PAYROLL".payroll_runs r ON r.id = rl.run_id AND r.run_type = 'regular'
    RETURNING id
)
INSERT INTO "PAYROLL".payment_reconciliations (batch_line_id, reconciled_status, reconciled_by)
SELECT line.id, 'paid', (SELECT id FROM users WHERE email = 'admin@nusaevo.com') FROM line;

INSERT INTO "PAYROLL".payroll_access_logs (action, actor_id, subject_type, subject_id)
SELECT 'run_paid', (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'payroll.payroll_runs', r.id::text
FROM "PAYROLL".payroll_runs r WHERE r.run_type = 'regular';

-- --- THR run for the same employee, same period -----------------------------

INSERT INTO "PAYROLL".thr_calculations (employee_id, tenure_months, monthly_salary_basis, proration_factor, thr_amount, religious_holiday_date)
SELECT e.id, 25, 16000000, 1.0, 16000000, '2026-03-20'
FROM "HCM".employees e WHERE e.employee_no = 'EMP-0001';

INSERT INTO "PAYROLL".payroll_runs (run_type, payroll_group_id, payroll_period_id, status)
SELECT 'thr', pg.id, pp.id, 'paid'
FROM "PAYROLL".payroll_groups pg, "PAYROLL".payroll_periods pp
WHERE pg.name = 'Monthly Staff - PT Demo Legal Firm' AND pp.period_start = '2026-02-26';

WITH thr_line AS (
    INSERT INTO "PAYROLL".payroll_run_lines (run_id, employee_id, gross_amount, total_deductions, net_amount, employer_cost, is_locked)
    SELECT r.id, e.id, 16000000, 800000, 15200000, 16000000, TRUE
    FROM "PAYROLL".payroll_runs r, "HCM".employees e
    WHERE r.run_type = 'thr' AND e.employee_no = 'EMP-0001'
    RETURNING id
)
UPDATE "PAYROLL".thr_calculations tc
SET run_line_id = thr_line.id
FROM thr_line
WHERE tc.employee_id = (SELECT id FROM "HCM".employees WHERE employee_no = 'EMP-0001');

INSERT INTO "PAYROLL".pph21_calculations (run_line_id, employee_id, calculation_mode, gross_income, ptkp_status_code, tax_amount)
SELECT rl.id, e.id, 'irregular_income', 16000000, 'K/1', 800000
FROM "PAYROLL".payroll_run_lines rl
JOIN "PAYROLL".payroll_runs r ON r.id = rl.run_id AND r.run_type = 'thr'
JOIN "HCM".employees e ON e.id = rl.employee_id;

-- --- Final Payroll for a second, terminated employee -------------------------

INSERT INTO "HCM".employees (employee_no, full_name, hire_date, employment_status, termination_date, termination_reason, marital_status, dependents_count)
VALUES ('EMP-0002', 'Andi Wijaya', '2020-02-01', 'terminated', '2026-07-31', 'Restrukturisasi', 'single', 0);

INSERT INTO "PAYROLL".employee_payroll_profiles (employee_id, ptkp_status_code, payroll_group_id, salary_structure_id, jkk_risk_category_id)
SELECT e.id, 'TK/0', pg.id, ss.id, jkk.id
FROM "HCM".employees e, "PAYROLL".payroll_groups pg, "PAYROLL".salary_structures ss, "PAYROLL".jkk_risk_categories jkk
WHERE e.employee_no = 'EMP-0002' AND pg.name = 'Monthly Staff - PT Demo Legal Firm' AND ss.name = 'Staff Grade 2' AND jkk.code = 'low';

INSERT INTO "PAYROLL".payroll_periods (payroll_group_id, period_start, period_end, cutoff_date, scheduled_pay_date)
SELECT id, '2026-06-26', '2026-07-25', '2026-07-25', '2026-07-31'
FROM "PAYROLL".payroll_groups WHERE name = 'Monthly Staff - PT Demo Legal Firm';

INSERT INTO "PAYROLL".severance_calculations (employee_id, termination_reason, tenure_years, pesangon_amount, upmk_amount, uph_amount)
SELECT id, 'redundancy', 6.5, 70000000, 20000000, 13500000
FROM "HCM".employees WHERE employee_no = 'EMP-0002';

INSERT INTO "PAYROLL".payroll_runs (run_type, payroll_group_id, payroll_period_id, status)
SELECT 'final', pg.id, pp.id, 'paid'
FROM "PAYROLL".payroll_groups pg, "PAYROLL".payroll_periods pp
WHERE pg.name = 'Monthly Staff - PT Demo Legal Firm' AND pp.period_start = '2026-06-26';

WITH final_line AS (
    INSERT INTO "PAYROLL".payroll_run_lines (run_id, employee_id, gross_amount, total_deductions, net_amount, employer_cost, is_locked)
    SELECT r.id, e.id, 103500000, 2000000, 101500000, 103500000, TRUE
    FROM "PAYROLL".payroll_runs r, "HCM".employees e
    WHERE r.run_type = 'final' AND e.employee_no = 'EMP-0002'
    RETURNING id
)
UPDATE "PAYROLL".severance_calculations sc
SET run_line_id = final_line.id
FROM final_line
WHERE sc.employee_id = (SELECT id FROM "HCM".employees WHERE employee_no = 'EMP-0002');

INSERT INTO "PAYROLL".pph21_calculations (run_line_id, employee_id, calculation_mode, gross_income, ptkp_status_code, tax_amount)
SELECT rl.id, e.id, 'final_severance', 103500000, 'TK/0', 2000000
FROM "PAYROLL".payroll_run_lines rl
JOIN "PAYROLL".payroll_runs r ON r.id = rl.run_id AND r.run_type = 'final'
JOIN "HCM".employees e ON e.id = rl.employee_id;

INSERT INTO "PAYROLL".payroll_access_logs (action, actor_id, subject_type, subject_id)
SELECT 'run_paid', (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'payroll.payroll_runs', r.id::text
FROM "PAYROLL".payroll_runs r WHERE r.run_type = 'final';

COMMIT;
