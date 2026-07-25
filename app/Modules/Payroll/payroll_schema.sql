-- =============================================================================
-- PAYROLL MODULE — SCHEMA DDL
-- Tenant DB, schema PAYROLL (per CLAUDE.md §7 — no tenant_id column; isolation
-- is the database boundary). PostgreSQL 16.
-- Run against a single tenant database, e.g.: psql -f payroll_schema.sql tenant_001
-- =============================================================================

BEGIN;

CREATE SCHEMA IF NOT EXISTS "PAYROLL";
SET search_path TO "PAYROLL", public;

-- =============================================================================
-- SECTION 1 — MASTER / SETUP TABLES
-- =============================================================================

CREATE TABLE "PAYROLL".grades (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30) NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".jkk_risk_categories (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    risk_tier       VARCHAR(20) NOT NULL
        CHECK (risk_tier IN ('very_low','low','medium','high','very_high')),
    employer_pct    NUMERIC(6,4) NOT NULL CHECK (employer_pct >= 0),
    effective_date  DATE NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (code, effective_date)
);

CREATE TABLE "PAYROLL".bank_master (
    id                  BIGSERIAL PRIMARY KEY,
    bank_code           VARCHAR(20) NOT NULL UNIQUE,
    bank_name           VARCHAR(150) NOT NULL,
    payment_file_format VARCHAR(30) NOT NULL DEFAULT 'csv_generic'
        CHECK (payment_file_format IN ('csv_generic','excel_generic','csv_bank_specific')),
    file_template_notes TEXT,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".payroll_calendars (
    id                  BIGSERIAL PRIMARY KEY,
    name                VARCHAR(100) NOT NULL,
    pay_frequency       VARCHAR(20) NOT NULL
        CHECK (pay_frequency IN ('monthly','semi_monthly','weekly','daily')),
    cutoff_day_rule     VARCHAR(100) NOT NULL,
    pay_date_rule       VARCHAR(100) NOT NULL,
    shift_earlier_on_holiday BOOLEAN NOT NULL DEFAULT TRUE,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".payroll_components (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(40) NOT NULL UNIQUE,
    name                VARCHAR(150) NOT NULL,
    type                VARCHAR(20) NOT NULL CHECK (type IN ('earning','deduction')),
    category            VARCHAR(20) NOT NULL
        CHECK (category IN ('fixed','formula','statutory','variable_input')),
    calculation_basis   VARCHAR(20) NOT NULL DEFAULT 'flat_amount'
        CHECK (calculation_basis IN ('flat_amount','percent_of_component','statutory_engine')),
    basis_component_id  BIGINT REFERENCES "PAYROLL".payroll_components(id),
    basis_percent       NUMERIC(7,4),
    statutory_engine    VARCHAR(30)
        CHECK (statutory_engine IS NULL OR statutory_engine IN ('pph21','bpjs_kesehatan','bpjs_ketenagakerjaan')),
    is_taxable          BOOLEAN NOT NULL DEFAULT TRUE,
    is_bpjs_basis       BOOLEAN NOT NULL DEFAULT TRUE,
    is_system_defined   BOOLEAN NOT NULL DEFAULT FALSE,
    gl_account_code     VARCHAR(30),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (
        (calculation_basis <> 'percent_of_component') OR
        (basis_component_id IS NOT NULL AND basis_percent IS NOT NULL)
    ),
    CHECK (
        (calculation_basis <> 'statutory_engine') OR (statutory_engine IS NOT NULL)
    )
);

CREATE TABLE "PAYROLL".salary_structures (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(40) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    grade_id        BIGINT REFERENCES "PAYROLL".grades(id),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".salary_structure_components (
    id                      BIGSERIAL PRIMARY KEY,
    salary_structure_id     BIGINT NOT NULL REFERENCES "PAYROLL".salary_structures(id) ON DELETE CASCADE,
    payroll_component_id    BIGINT NOT NULL REFERENCES "PAYROLL".payroll_components(id),
    default_amount          NUMERIC(15,2),
    default_percent         NUMERIC(7,4),
    sort_order              INTEGER NOT NULL DEFAULT 0,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (salary_structure_id, payroll_component_id)
);

CREATE TABLE "PAYROLL".payroll_groups (
    id                              BIGSERIAL PRIMARY KEY,
    name                            VARCHAR(150) NOT NULL,
    legal_entity_name               VARCHAR(150),
    default_payroll_calendar_id     BIGINT REFERENCES "PAYROLL".payroll_calendars(id),
    default_salary_structure_id     BIGINT REFERENCES "PAYROLL".salary_structures(id),
    default_jkk_risk_category_id    BIGINT REFERENCES "PAYROLL".jkk_risk_categories(id),
    is_active                       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".loan_types (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(40) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    max_term_months INTEGER,
    interest_method VARCHAR(20) NOT NULL DEFAULT 'none' CHECK (interest_method IN ('none','flat')),
    flat_rate_pct   NUMERIC(6,4),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".deduction_rule_configs (
    id                          BIGSERIAL PRIMARY KEY,
    code                        VARCHAR(40) NOT NULL UNIQUE,
    name                        VARCHAR(150) NOT NULL,
    loan_type_id                BIGINT REFERENCES "PAYROLL".loan_types(id),
    insufficient_pay_behavior   VARCHAR(30) NOT NULL DEFAULT 'skip_and_roll_forward'
        CHECK (insufficient_pay_behavior IN ('skip_and_roll_forward','partial_deduction')),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".reimbursement_categories (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(40) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    is_taxable      BOOLEAN NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- SECTION 2 — VERSIONED STATUTORY RULE TABLES (regulation-driven, never hardcoded)
-- =============================================================================

CREATE TABLE "PAYROLL".ptkp_statuses (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(10) NOT NULL,
    description         VARCHAR(150) NOT NULL,
    annual_ptkp_amount  NUMERIC(15,2) NOT NULL CHECK (annual_ptkp_amount >= 0),
    ter_category        VARCHAR(1) NOT NULL CHECK (ter_category IN ('A','B','C')),
    effective_date      DATE NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (code, effective_date)
);

CREATE TABLE "PAYROLL".ter_categories (
    id              BIGSERIAL PRIMARY KEY,
    category        VARCHAR(1) NOT NULL UNIQUE CHECK (category IN ('A','B','C')),
    description     VARCHAR(150) NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".ter_rate_brackets (
    id                      BIGSERIAL PRIMARY KEY,
    ter_category            VARCHAR(1) NOT NULL REFERENCES "PAYROLL".ter_categories(category),
    bracket_lower_bound     NUMERIC(15,2) NOT NULL CHECK (bracket_lower_bound >= 0),
    bracket_upper_bound     NUMERIC(15,2),
    rate_pct                NUMERIC(6,4) NOT NULL CHECK (rate_pct >= 0),
    effective_date          DATE NOT NULL,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (bracket_upper_bound IS NULL OR bracket_upper_bound > bracket_lower_bound)
);

CREATE TABLE "PAYROLL".ter_daily_rates (
    id                      BIGSERIAL PRIMARY KEY,
    bracket_lower_bound     NUMERIC(15,2) NOT NULL CHECK (bracket_lower_bound >= 0),
    bracket_upper_bound     NUMERIC(15,2),
    rate_pct                NUMERIC(6,4) NOT NULL CHECK (rate_pct >= 0),
    effective_date          DATE NOT NULL,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (bracket_upper_bound IS NULL OR bracket_upper_bound > bracket_lower_bound)
);

CREATE TABLE "PAYROLL".pph21_progressive_brackets (
    id                      BIGSERIAL PRIMARY KEY,
    bracket_lower_bound     NUMERIC(18,2) NOT NULL CHECK (bracket_lower_bound >= 0),
    bracket_upper_bound     NUMERIC(18,2),
    rate_pct                NUMERIC(6,4) NOT NULL CHECK (rate_pct >= 0),
    effective_date          DATE NOT NULL,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (bracket_upper_bound IS NULL OR bracket_upper_bound > bracket_lower_bound)
);

CREATE TABLE "PAYROLL".overtime_multiplier_rules (
    id                  BIGSERIAL PRIMARY KEY,
    day_type            VARCHAR(20) NOT NULL CHECK (day_type IN ('weekday','weekly_rest_day','public_holiday')),
    hour_sequence       VARCHAR(20) NOT NULL DEFAULT 'standard'
        CHECK (hour_sequence IN ('first_hour','subsequent_hours','standard')),
    multiplier          NUMERIC(6,4) NOT NULL CHECK (multiplier > 0),
    effective_date      DATE NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (day_type, hour_sequence, effective_date)
);

CREATE TABLE "PAYROLL".bpjs_kesehatan_rules (
    id              BIGSERIAL PRIMARY KEY,
    employer_pct    NUMERIC(6,4) NOT NULL CHECK (employer_pct >= 0),
    employee_pct    NUMERIC(6,4) NOT NULL CHECK (employee_pct >= 0),
    wage_cap        NUMERIC(15,2) NOT NULL CHECK (wage_cap > 0),
    effective_date  DATE NOT NULL UNIQUE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".bpjs_ketenagakerjaan_rules (
    id              BIGSERIAL PRIMARY KEY,
    program         VARCHAR(10) NOT NULL CHECK (program IN ('JHT','JP','JKM','JKP')),
    employer_pct    NUMERIC(6,4) NOT NULL CHECK (employer_pct >= 0),
    employee_pct    NUMERIC(6,4) NOT NULL DEFAULT 0 CHECK (employee_pct >= 0),
    wage_cap        NUMERIC(15,2),
    effective_date  DATE NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (program, effective_date)
);

CREATE TABLE "PAYROLL".severance_rule_tables (
    id                      BIGSERIAL PRIMARY KEY,
    termination_reason      VARCHAR(30) NOT NULL
        CHECK (termination_reason IN ('resignation','termination_with_cause','redundancy','retirement','contract_end','death')),
    tenure_min_years        NUMERIC(5,2) NOT NULL CHECK (tenure_min_years >= 0),
    tenure_max_years        NUMERIC(5,2),
    pesangon_multiplier     NUMERIC(6,2) NOT NULL DEFAULT 0,
    upmk_multiplier         NUMERIC(6,2) NOT NULL DEFAULT 0,
    uph_pct                 NUMERIC(6,4) NOT NULL DEFAULT 0,
    effective_date          DATE NOT NULL,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (tenure_max_years IS NULL OR tenure_max_years > tenure_min_years)
);

-- =============================================================================
-- SECTION 3 — EMPLOYEE MASTER DATA (minimal, Payroll-owned — see spec §5)
-- =============================================================================

CREATE TABLE "PAYROLL".employees (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    employee_number             VARCHAR(30) NOT NULL UNIQUE,
    full_name                   VARCHAR(150) NOT NULL,
    identity_number             VARCHAR(30),
    npwp_number                 VARCHAR(30),
    employment_status           VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (employment_status IN ('active','on_leave','terminated')),
    join_date                   DATE NOT NULL,
    termination_date            DATE,
    termination_reason          VARCHAR(30)
        CHECK (termination_reason IS NULL OR termination_reason IN
            ('resignation','termination_with_cause','redundancy','retirement','contract_end','death')),
    ptkp_status_code             VARCHAR(10) NOT NULL,
    bpjs_kesehatan_number         VARCHAR(30),
    bpjs_ketenagakerjaan_number   VARCHAR(30),
    payroll_group_id              BIGINT NOT NULL REFERENCES "PAYROLL".payroll_groups(id),
    salary_structure_id           BIGINT REFERENCES "PAYROLL".salary_structures(id),
    grade_id                      BIGINT REFERENCES "PAYROLL".grades(id),
    jkk_risk_category_id          BIGINT REFERENCES "PAYROLL".jkk_risk_categories(id),
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (termination_date IS NULL OR termination_date >= join_date)
);

CREATE TABLE "PAYROLL".employee_bank_accounts (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id         BIGINT NOT NULL REFERENCES "PAYROLL".employees(id) ON DELETE CASCADE,
    bank_id             BIGINT NOT NULL REFERENCES "PAYROLL".bank_master(id),
    account_number      VARCHAR(50) NOT NULL,
    account_holder_name VARCHAR(150) NOT NULL,
    is_primary          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- SECTION 4 — PAYROLL PERIODS & RUNS
-- =============================================================================

CREATE TABLE "PAYROLL".payroll_periods (
    id                      BIGSERIAL PRIMARY KEY,
    payroll_group_id        BIGINT NOT NULL REFERENCES "PAYROLL".payroll_groups(id),
    payroll_calendar_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_calendars(id),
    period_start            DATE NOT NULL,
    period_end              DATE NOT NULL,
    cutoff_date             DATE NOT NULL,
    scheduled_pay_date      DATE NOT NULL,
    status                  VARCHAR(20) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open','processing','approved','paid','locked')),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (payroll_group_id, period_start, period_end),
    CHECK (period_end > period_start)
);

CREATE TABLE "PAYROLL".payroll_runs (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    run_type                VARCHAR(20) NOT NULL
        CHECK (run_type IN ('regular','off_cycle','thr','bonus','final','adjustment')),
    payroll_group_id        BIGINT NOT NULL REFERENCES "PAYROLL".payroll_groups(id),
    payroll_period_id       BIGINT REFERENCES "PAYROLL".payroll_periods(id),
    adjustment_of_run_id    BIGINT REFERENCES "PAYROLL".payroll_runs(id),
    status                  VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','submitted','approved','paid','locked','rejected')),
    reason_code              VARCHAR(60),
    workflow_instance_id     BIGINT,
    submitted_at             TIMESTAMPTZ,
    approved_at              TIMESTAMPTZ,
    paid_at                  TIMESTAMPTZ,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (run_type <> 'adjustment' OR adjustment_of_run_id IS NOT NULL)
);

CREATE TABLE "PAYROLL".payroll_run_lines (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    payroll_run_id          BIGINT NOT NULL REFERENCES "PAYROLL".payroll_runs(id) ON DELETE CASCADE,
    employee_id             BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    gross_earnings          NUMERIC(15,2) NOT NULL DEFAULT 0,
    total_deductions        NUMERIC(15,2) NOT NULL DEFAULT 0,
    net_pay                 NUMERIC(15,2) NOT NULL DEFAULT 0,
    employer_cost           NUMERIC(15,2) NOT NULL DEFAULT 0,
    status                  VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','submitted','approved','paid','locked')),
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (payroll_run_id, employee_id)
);

CREATE TABLE "PAYROLL".payroll_run_line_components (
    id                      BIGSERIAL PRIMARY KEY,
    payroll_run_line_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines(id) ON DELETE CASCADE,
    payroll_component_id    BIGINT NOT NULL REFERENCES "PAYROLL".payroll_components(id),
    amount                  NUMERIC(15,2) NOT NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- SECTION 5 — STATUTORY CALCULATION AUDIT TABLES
-- =============================================================================

CREATE TABLE "PAYROLL".pph21_calculations (
    id                      BIGSERIAL PRIMARY KEY,
    payroll_run_line_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines(id) ON DELETE CASCADE,
    employee_id              BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    calculation_mode          VARCHAR(30) NOT NULL
        CHECK (calculation_mode IN ('monthly_ter','annualized_reconciliation','irregular_income','final_severance')),
    ptkp_status_code           VARCHAR(10) NOT NULL,
    ter_category               VARCHAR(1) CHECK (ter_category IS NULL OR ter_category IN ('A','B','C')),
    taxable_gross               NUMERIC(18,2) NOT NULL,
    rate_pct_applied             NUMERIC(6,4),
    tax_amount                   NUMERIC(15,2) NOT NULL,
    rule_effective_date          DATE NOT NULL,
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".bpjs_kesehatan_contributions (
    id                      BIGSERIAL PRIMARY KEY,
    payroll_run_line_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines(id) ON DELETE CASCADE,
    employee_id              BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    wage_base                 NUMERIC(15,2) NOT NULL,
    wage_base_capped           NUMERIC(15,2) NOT NULL,
    employer_amount             NUMERIC(15,2) NOT NULL,
    employee_amount              NUMERIC(15,2) NOT NULL,
    rule_effective_date           DATE NOT NULL,
    created_at                     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".bpjs_ketenagakerjaan_contributions (
    id                      BIGSERIAL PRIMARY KEY,
    payroll_run_line_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines(id) ON DELETE CASCADE,
    employee_id              BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    program                   VARCHAR(10) NOT NULL CHECK (program IN ('JHT','JP','JKK','JKM','JKP')),
    wage_base                  NUMERIC(15,2) NOT NULL,
    wage_base_capped            NUMERIC(15,2) NOT NULL,
    employer_amount              NUMERIC(15,2) NOT NULL,
    employee_amount               NUMERIC(15,2) NOT NULL,
    rule_effective_date            DATE NOT NULL,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (payroll_run_line_id, program)
);

CREATE TABLE "PAYROLL".thr_calculations (
    id                      BIGSERIAL PRIMARY KEY,
    payroll_run_line_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines(id) ON DELETE CASCADE,
    employee_id              BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    tenure_months_at_calc     INTEGER NOT NULL,
    is_prorated                BOOLEAN NOT NULL,
    monthly_salary_basis        NUMERIC(15,2) NOT NULL,
    thr_amount                   NUMERIC(15,2) NOT NULL,
    religious_holiday_date        DATE,
    created_at                     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".severance_calculations (
    id                      BIGSERIAL PRIMARY KEY,
    payroll_run_line_id     BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines(id) ON DELETE CASCADE,
    employee_id              BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    termination_reason        VARCHAR(30) NOT NULL
        CHECK (termination_reason IN ('resignation','termination_with_cause','redundancy','retirement','contract_end','death')),
    tenure_years                NUMERIC(5,2) NOT NULL,
    pesangon_amount              NUMERIC(15,2) NOT NULL DEFAULT 0,
    upmk_amount                   NUMERIC(15,2) NOT NULL DEFAULT 0,
    uph_amount                     NUMERIC(15,2) NOT NULL DEFAULT 0,
    total_severance                 NUMERIC(15,2) NOT NULL DEFAULT 0,
    rule_effective_date               DATE NOT NULL,
    created_at                          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- SECTION 6 — PAYROLL INPUTS
-- =============================================================================

CREATE TABLE "PAYROLL".overtime_entries (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id              BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    payroll_period_id         BIGINT REFERENCES "PAYROLL".payroll_periods(id),
    work_date                   DATE NOT NULL,
    day_type                      VARCHAR(20) NOT NULL CHECK (day_type IN ('weekday','weekly_rest_day','public_holiday')),
    hours                          NUMERIC(5,2) NOT NULL CHECK (hours > 0),
    computed_amount                  NUMERIC(15,2),
    status                            VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','approved','paid','rejected')),
    created_at                         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".variable_earning_entries (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id              BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    payroll_period_id         BIGINT REFERENCES "PAYROLL".payroll_periods(id),
    payroll_component_id       BIGINT NOT NULL REFERENCES "PAYROLL".payroll_components(id),
    amount                       NUMERIC(15,2) NOT NULL,
    description                    TEXT,
    status                           VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','approved','paid','rejected')),
    created_at                         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".commission_entries (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id              BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    payroll_period_id         BIGINT REFERENCES "PAYROLL".payroll_periods(id),
    amount                      NUMERIC(15,2) NOT NULL,
    description                   TEXT,
    status                          VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','approved','paid','rejected')),
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".reimbursement_requests (
    id                          BIGSERIAL PRIMARY KEY,
    employee_id                  BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    reimbursement_category_id     BIGINT NOT NULL REFERENCES "PAYROLL".reimbursement_categories(id),
    payroll_period_id              BIGINT REFERENCES "PAYROLL".payroll_periods(id),
    amount                           NUMERIC(15,2) NOT NULL CHECK (amount > 0),
    description                        TEXT,
    expense_date                        DATE NOT NULL,
    status                                VARCHAR(20) NOT NULL DEFAULT 'requested'
        CHECK (status IN ('requested','approved','paid','rejected')),
    workflow_instance_id                   BIGINT,
    created_at                               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                 TIMESTAMPTZ NOT NULL DEFAULT now()
    -- Receipt attachments live in DMS, subject_type = 'payroll.reimbursement_request'
);

CREATE TABLE "PAYROLL".employee_loans (
    id                          BIGSERIAL PRIMARY KEY,
    employee_id                  BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    loan_type_id                  BIGINT NOT NULL REFERENCES "PAYROLL".loan_types(id),
    principal_amount                NUMERIC(15,2) NOT NULL CHECK (principal_amount > 0),
    term_months                      INTEGER NOT NULL CHECK (term_months > 0),
    monthly_installment                NUMERIC(15,2) NOT NULL CHECK (monthly_installment > 0),
    remaining_balance                    NUMERIC(15,2) NOT NULL,
    start_period_id                        BIGINT REFERENCES "PAYROLL".payroll_periods(id),
    status                                   VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','completed','cancelled')),
    created_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                  TIMESTAMPTZ NOT NULL DEFAULT now()
    -- Loan agreement document lives in DMS, subject_type = 'payroll.employee_loan'
);

CREATE TABLE "PAYROLL".loan_installments (
    id                          BIGSERIAL PRIMARY KEY,
    employee_loan_id              BIGINT NOT NULL REFERENCES "PAYROLL".employee_loans(id) ON DELETE CASCADE,
    payroll_run_line_id             BIGINT REFERENCES "PAYROLL".payroll_run_lines(id),
    installment_number                INTEGER NOT NULL,
    due_amount                          NUMERIC(15,2) NOT NULL,
    deducted_amount                       NUMERIC(15,2) NOT NULL DEFAULT 0,
    status                                  VARCHAR(20) NOT NULL DEFAULT 'due'
        CHECK (status IN ('due','deducted','skipped','partial')),
    created_at                                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (employee_loan_id, installment_number)
);

CREATE TABLE "PAYROLL".salary_advances (
    id                          BIGSERIAL PRIMARY KEY,
    employee_id                  BIGINT NOT NULL REFERENCES "PAYROLL".employees(id),
    amount                         NUMERIC(15,2) NOT NULL CHECK (amount > 0),
    requested_at                     DATE NOT NULL,
    repayment_installments             INTEGER NOT NULL DEFAULT 1 CHECK (repayment_installments > 0),
    remaining_balance                    NUMERIC(15,2) NOT NULL,
    payroll_run_line_id                    BIGINT REFERENCES "PAYROLL".payroll_run_lines(id),
    status                                   VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','completed','cancelled')),
    created_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- SECTION 7 — PAYMENT
-- =============================================================================

CREATE TABLE "PAYROLL".payment_batches (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    payroll_group_id        BIGINT NOT NULL REFERENCES "PAYROLL".payroll_groups(id),
    payroll_period_id       BIGINT REFERENCES "PAYROLL".payroll_periods(id),
    bank_id                  BIGINT NOT NULL REFERENCES "PAYROLL".bank_master(id),
    batch_date                DATE NOT NULL,
    total_amount                NUMERIC(18,2) NOT NULL DEFAULT 0,
    status                        VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','exported','reconciled','failed')),
    exported_file_reference        VARCHAR(255),
    created_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PAYROLL".payment_batch_lines (
    id                          BIGSERIAL PRIMARY KEY,
    payment_batch_id              BIGINT NOT NULL REFERENCES "PAYROLL".payment_batches(id) ON DELETE CASCADE,
    payroll_run_line_id             BIGINT NOT NULL REFERENCES "PAYROLL".payroll_run_lines(id),
    employee_bank_account_id          BIGINT NOT NULL REFERENCES "PAYROLL".employee_bank_accounts(id),
    amount                              NUMERIC(15,2) NOT NULL CHECK (amount > 0),
    status                                VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','paid','failed')),
    created_at                              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (payment_batch_id, payroll_run_line_id)
);

CREATE TABLE "PAYROLL".payment_reconciliations (
    id                          BIGSERIAL PRIMARY KEY,
    payment_batch_line_id         BIGINT NOT NULL REFERENCES "PAYROLL".payment_batch_lines(id) ON DELETE CASCADE,
    reconciled_status               VARCHAR(20) NOT NULL CHECK (reconciled_status IN ('paid','failed')),
    reconciled_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    failure_reason                      TEXT,
    reconciled_by                         VARCHAR(150),
    created_at                              TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- SECTION 8 — ADMINISTRATION / AUDIT
-- =============================================================================

CREATE TABLE "PAYROLL".payroll_access_logs (
    id                      BIGSERIAL PRIMARY KEY,
    action                  VARCHAR(50) NOT NULL CHECK (action IN (
        'view_payslip','run_created','run_submitted','run_approved','run_paid','run_locked',
        'adjustment_created','regulatory_rule_changed','employee_salary_changed',
        'employee_created','employee_terminated','payment_batch_exported'
    )),
    actor_id                 BIGINT,
    actor_name                VARCHAR(150),
    subject_type               VARCHAR(60) NOT NULL,
    subject_id                  BIGINT NOT NULL,
    ip_address                    VARCHAR(45),
    occurred_at                     TIMESTAMPTZ NOT NULL DEFAULT now()
    -- Append-only: no UPDATE/DELETE permitted at the app layer.
);

-- =============================================================================
-- SECTION 9 — INDEXES
-- =============================================================================

CREATE INDEX idx_employees_payroll_group          ON "PAYROLL".employees (payroll_group_id);
CREATE INDEX idx_employees_status                  ON "PAYROLL".employees (employment_status);
CREATE INDEX idx_employee_bank_accounts_employee    ON "PAYROLL".employee_bank_accounts (employee_id);

CREATE INDEX idx_ptkp_statuses_code_date            ON "PAYROLL".ptkp_statuses (code, effective_date);
CREATE INDEX idx_ter_rate_brackets_cat_date         ON "PAYROLL".ter_rate_brackets (ter_category, effective_date);
CREATE INDEX idx_pph21_brackets_date                ON "PAYROLL".pph21_progressive_brackets (effective_date);
CREATE INDEX idx_bpjs_kesehatan_rules_date          ON "PAYROLL".bpjs_kesehatan_rules (effective_date);
CREATE INDEX idx_bpjs_naker_rules_program_date      ON "PAYROLL".bpjs_ketenagakerjaan_rules (program, effective_date);
CREATE INDEX idx_severance_rules_reason_date        ON "PAYROLL".severance_rule_tables (termination_reason, effective_date);
CREATE INDEX idx_jkk_risk_categories_code_date      ON "PAYROLL".jkk_risk_categories (code, effective_date);

CREATE INDEX idx_payroll_periods_group_status       ON "PAYROLL".payroll_periods (payroll_group_id, status);
CREATE INDEX idx_payroll_runs_group_period          ON "PAYROLL".payroll_runs (payroll_group_id, payroll_period_id);
CREATE INDEX idx_payroll_runs_type_status           ON "PAYROLL".payroll_runs (run_type, status);
CREATE INDEX idx_payroll_run_lines_run              ON "PAYROLL".payroll_run_lines (payroll_run_id);
CREATE INDEX idx_payroll_run_lines_employee         ON "PAYROLL".payroll_run_lines (employee_id);
CREATE INDEX idx_payroll_run_line_components_line   ON "PAYROLL".payroll_run_line_components (payroll_run_line_id);

CREATE INDEX idx_pph21_calc_employee                ON "PAYROLL".pph21_calculations (employee_id);
CREATE INDEX idx_bpjs_kesehatan_contrib_employee    ON "PAYROLL".bpjs_kesehatan_contributions (employee_id);
CREATE INDEX idx_bpjs_naker_contrib_employee        ON "PAYROLL".bpjs_ketenagakerjaan_contributions (employee_id);

CREATE INDEX idx_overtime_entries_employee_period          ON "PAYROLL".overtime_entries (employee_id, payroll_period_id);
CREATE INDEX idx_variable_earning_entries_employee_period  ON "PAYROLL".variable_earning_entries (employee_id, payroll_period_id);
CREATE INDEX idx_commission_entries_employee_period        ON "PAYROLL".commission_entries (employee_id, payroll_period_id);
CREATE INDEX idx_reimbursement_requests_employee            ON "PAYROLL".reimbursement_requests (employee_id);
CREATE INDEX idx_employee_loans_employee                    ON "PAYROLL".employee_loans (employee_id);
CREATE INDEX idx_loan_installments_loan                     ON "PAYROLL".loan_installments (employee_loan_id);
CREATE INDEX idx_salary_advances_employee                   ON "PAYROLL".salary_advances (employee_id);

CREATE INDEX idx_payment_batch_lines_batch                  ON "PAYROLL".payment_batch_lines (payment_batch_id);
CREATE INDEX idx_payment_batch_lines_run_line                ON "PAYROLL".payment_batch_lines (payroll_run_line_id);

CREATE INDEX idx_payroll_access_logs_subject                 ON "PAYROLL".payroll_access_logs (subject_type, subject_id);
CREATE INDEX idx_payroll_access_logs_occurred                 ON "PAYROLL".payroll_access_logs (occurred_at);

COMMIT;
