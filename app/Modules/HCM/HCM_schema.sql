-- ============================================================================
-- HCM Module — Schema DDL
-- Human Resources / Human Capital Management — Core Shared Module
-- Target: PostgreSQL 16, one tenant database (DB-per-tenant per CLAUDE.md §4/§7)
-- Schema: HCM (no tenant_id column — DB itself is the isolation boundary)
--
-- Run with:
--   psql "<tenant_db_conn>" -v ON_ERROR_STOP=1 -f HCM_schema.sql
-- ============================================================================

BEGIN;

CREATE SCHEMA IF NOT EXISTS "HCM";
SET search_path TO "HCM";

-- ----------------------------------------------------------------------------
-- 1. ORG STRUCTURE (3C)
-- ----------------------------------------------------------------------------

CREATE TABLE "HCM".org_units (
    id                      BIGSERIAL PRIMARY KEY,
    parent_org_unit_id      BIGINT REFERENCES "HCM".org_units(id),
    name                    VARCHAR(150) NOT NULL,
    code                    VARCHAR(30),
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "HCM".org_units IS 'Department/division/branch tree (3C).';

CREATE TABLE "HCM".jobs (
    id                      BIGSERIAL PRIMARY KEY,
    title                   VARCHAR(150) NOT NULL,
    code                    VARCHAR(30),
    description             TEXT,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "HCM".jobs IS 'Job catalog (master), independent of who fills a seat (3C).';

CREATE TABLE "HCM".positions (
    id                      BIGSERIAL PRIMARY KEY,
    job_id                  BIGINT NOT NULL REFERENCES "HCM".jobs(id),
    org_unit_id             BIGINT NOT NULL REFERENCES "HCM".org_units(id),
    reports_to_position_id  BIGINT REFERENCES "HCM".positions(id),
    title_override          VARCHAR(150),
    headcount_cap           INTEGER,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "HCM".positions IS 'A specific seat; reports_to_position_id drives manager resolution (3C).';

-- ----------------------------------------------------------------------------
-- 2. STATUTORY RATE TABLES — Indonesian compliance (3G)
--    Versioned, effective-dated, admin-loadable. Never hardcoded in app code.
-- ----------------------------------------------------------------------------

CREATE TABLE "HCM".ptkp_statuses (
    id                          BIGSERIAL PRIMARY KEY,
    code                        VARCHAR(10) NOT NULL UNIQUE,   -- e.g. TK/0, K/1, K/3
    description                 VARCHAR(150) NOT NULL,
    annual_non_taxable_amount   NUMERIC(15,2) NOT NULL,
    ter_category                CHAR(1) NOT NULL CHECK (ter_category IN ('A','B','C')),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE
);
COMMENT ON TABLE "HCM".ptkp_statuses IS 'PTKP non-taxable-income categories mapped to PPh 21 TER category (PMK 168/2023).';

CREATE TABLE "HCM".ter_rates (
    id              BIGSERIAL PRIMARY KEY,
    ter_category    CHAR(1) NOT NULL CHECK (ter_category IN ('A','B','C')),
    income_from     NUMERIC(15,2) NOT NULL,
    income_to       NUMERIC(15,2),                              -- NULL = open-ended top bracket
    rate_percent    NUMERIC(5,2) NOT NULL,
    effective_from  DATE NOT NULL,
    effective_to    DATE,
    CHECK (income_to IS NULL OR income_to > income_from)
);
COMMENT ON TABLE "HCM".ter_rates IS 'Monthly TER withholding rate per category/bracket, effective-dated.';

CREATE TABLE "HCM".bpjs_rates (
    id                  BIGSERIAL PRIMARY KEY,
    contribution_type   VARCHAR(20) NOT NULL CHECK (contribution_type IN ('kesehatan','jkk','jkm','jht','jp')),
    risk_class          VARCHAR(20),                            -- JKK only, informational otherwise NULL
    employer_percent    NUMERIC(5,3) NOT NULL DEFAULT 0,
    employee_percent    NUMERIC(5,3) NOT NULL DEFAULT 0,
    wage_floor          NUMERIC(15,2),
    wage_ceiling         NUMERIC(15,2),
    effective_from      DATE NOT NULL,
    effective_to        DATE
);
COMMENT ON TABLE "HCM".bpjs_rates IS 'BPJS Kesehatan / JKK / JKM / JHT / JP contribution rates, effective-dated.';

CREATE TABLE "HCM".regional_minimum_wages (
    id          BIGSERIAL PRIMARY KEY,
    province    VARCHAR(100) NOT NULL,
    city        VARCHAR(100),                                    -- NULL = province-level UMP; set = city/regency UMK
    year        INTEGER NOT NULL,
    amount      NUMERIC(15,2) NOT NULL,
    UNIQUE (province, city, year)
);
COMMENT ON TABLE "HCM".regional_minimum_wages IS 'UMP/UMK reference for contract minimum-wage compliance checks (informational, 3D).';

-- ----------------------------------------------------------------------------
-- 3. EMPLOYEE & EMPLOYMENT (3B, 3D)
-- ----------------------------------------------------------------------------

CREATE TABLE "HCM".employees (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),  -- external-facing (ESS/API), per CLAUDE.md §7A
    employee_number             VARCHAR(30) NOT NULL UNIQUE,
    full_name                   VARCHAR(150) NOT NULL,
    date_of_birth               DATE,
    gender                      VARCHAR(1) CHECK (gender IN ('M','F')),
    nik                         CHAR(16) UNIQUE,                          -- KTP national ID number
    npwp                        VARCHAR(25),                              -- tax ID; absence affects PPh21 rate, see 3G
    bpjs_kesehatan_no           VARCHAR(30),
    bpjs_ketenagakerjaan_no     VARCHAR(30),
    address                     TEXT,
    marital_status              VARCHAR(20) CHECK (marital_status IN ('single','married','divorced','widowed')),
    dependents_count             SMALLINT NOT NULL DEFAULT 0,
    ptkp_status_id              BIGINT REFERENCES "HCM".ptkp_statuses(id),
    religion                    VARCHAR(20),                              -- used only to derive THR holiday date
    bank_name                   VARCHAR(80),
    bank_account_no             VARCHAR(40),
    bank_account_holder         VARCHAR(150),
    position_id                 BIGINT REFERENCES "HCM".positions(id),
    employment_status           VARCHAR(20) NOT NULL DEFAULT 'active'
                                    CHECK (employment_status IN ('active','on_leave','suspended','terminated')),
    hire_date                   DATE NOT NULL,
    termination_date            DATE,
    termination_reason          TEXT,
    linked_partner_id           BIGINT,                                  -- informational only, NOT a FK into CRM.partners (see spec §5)
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "HCM".employees IS 'Single source of truth for internal workforce (3B). Deliberately separate from CRM.partners — see HCM_SPECS.md §5.';
COMMENT ON COLUMN "HCM".employees.linked_partner_id IS 'Optional, informational cross-link to CRM.partners.id — never FK-enforced.';

CREATE TABLE "HCM".employee_position_history (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees(id),
    position_id         BIGINT NOT NULL REFERENCES "HCM".positions(id),
    effective_from      DATE NOT NULL,
    effective_to        DATE,
    changed_by          BIGINT REFERENCES "HCM".employees(id),
    note                TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "HCM".employee_position_history IS 'Audit trail of position/org changes — feeds tenure-based statutory calcs (3B).';

CREATE TABLE "HCM".employment_contracts (
    id                          BIGSERIAL PRIMARY KEY,
    employee_id                 BIGINT NOT NULL REFERENCES "HCM".employees(id),
    contract_type                VARCHAR(10) NOT NULL CHECK (contract_type IN ('PKWT','PKWTT')),
    start_date                  DATE NOT NULL,
    end_date                    DATE,                                    -- required for PKWT, NULL for PKWTT
    base_salary                 NUMERIC(15,2) NOT NULL,
    work_location                VARCHAR(150),
    probation_end_date          DATE,                                    -- PKWTT only per Indonesian law
    status                      VARCHAR(20) NOT NULL DEFAULT 'active'
                                    CHECK (status IN ('active','expired','terminated','renewed')),
    renewed_from_contract_id    BIGINT REFERENCES "HCM".employment_contracts(id),
    document_reference          VARCHAR(100),                            -- DMS document UUID
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (contract_type <> 'PKWT' OR end_date IS NOT NULL)
);
COMMENT ON TABLE "HCM".employment_contracts IS 'Legal basis of employment; PKWT total duration capped at 5 years incl. renewals per PP 35/2021 (3D).';

-- ----------------------------------------------------------------------------
-- 4. TIME & ATTENDANCE (3E)
-- ----------------------------------------------------------------------------

CREATE TABLE "HCM".shifts (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(80) NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    break_minutes   INTEGER NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE "HCM".shift_assignments (
    id              BIGSERIAL PRIMARY KEY,
    employee_id     BIGINT NOT NULL REFERENCES "HCM".employees(id),
    shift_id        BIGINT NOT NULL REFERENCES "HCM".shifts(id),
    work_date       DATE NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (employee_id, work_date)
);

CREATE TABLE "HCM".attendance_logs (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id             BIGINT NOT NULL REFERENCES "HCM".employees(id),
    shift_assignment_id     BIGINT REFERENCES "HCM".shift_assignments(id),
    clock_in_at             TIMESTAMPTZ,
    clock_out_at            TIMESTAMPTZ,
    source                  VARCHAR(20) NOT NULL DEFAULT 'web',
    status                  VARCHAR(20) CHECK (status IN ('on_time','late','early_leave','absent')),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "HCM".attendance_logs IS 'Clock in/out records; feeds Payroll overtime calc (3E → 3G).';

CREATE TABLE "HCM".attendance_corrections (
    id                          BIGSERIAL PRIMARY KEY,
    attendance_log_id           BIGINT REFERENCES "HCM".attendance_logs(id),
    employee_id                 BIGINT NOT NULL REFERENCES "HCM".employees(id),
    requested_clock_in_at       TIMESTAMPTZ,
    requested_clock_out_at      TIMESTAMPTZ,
    reason                      TEXT NOT NULL,
    status                      VARCHAR(20) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending','approved','rejected')),
    workflow_instance_ref       BIGINT,                              -- WNE wne.workflow_instances.id, informational
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "HCM".attendance_corrections IS 'Correction requests, routed through WNE for approval (3E).';

-- ----------------------------------------------------------------------------
-- 5. LEAVE MANAGEMENT (3F)
-- ----------------------------------------------------------------------------

CREATE TABLE "HCM".leave_types (
    id                      BIGSERIAL PRIMARY KEY,
    code                    VARCHAR(30) NOT NULL UNIQUE,
    name                    VARCHAR(100) NOT NULL,
    is_paid                 BOOLEAN NOT NULL DEFAULT TRUE,
    requires_attachment     BOOLEAN NOT NULL DEFAULT FALSE,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE
);
COMMENT ON TABLE "HCM".leave_types IS 'Pre-seeded with Indonesian statutory leave types + tenant-editable custom types (3F).';

CREATE TABLE "HCM".leave_policies (
    id                          BIGSERIAL PRIMARY KEY,
    leave_type_id                BIGINT NOT NULL REFERENCES "HCM".leave_types(id),
    employment_status_scope     VARCHAR(20) NOT NULL DEFAULT 'all'
                                    CHECK (employment_status_scope IN ('all','PKWT','PKWTT')),
    entitlement_days_per_year   NUMERIC(6,2) NOT NULL,
    accrual_method               VARCHAR(20) NOT NULL DEFAULT 'annual_grant'
                                    CHECK (accrual_method IN ('annual_grant','monthly_accrual')),
    carry_over_max_days         NUMERIC(6,2),
    carry_over_expiry_months    INTEGER,
    is_paid                     BOOLEAN NOT NULL DEFAULT TRUE
);
COMMENT ON TABLE "HCM".leave_policies IS 'Per tenant x leave type x employment-status entitlement rules (3F).';

CREATE TABLE "HCM".leave_balances (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees(id),
    leave_type_id       BIGINT NOT NULL REFERENCES "HCM".leave_types(id),
    period_year         INTEGER NOT NULL,
    entitled_days       NUMERIC(6,2) NOT NULL DEFAULT 0,
    used_days           NUMERIC(6,2) NOT NULL DEFAULT 0,
    carried_over_days   NUMERIC(6,2) NOT NULL DEFAULT 0,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (employee_id, leave_type_id, period_year)
);

CREATE TABLE "HCM".leave_requests (
    id                          BIGSERIAL PRIMARY KEY,
    employee_id                 BIGINT NOT NULL REFERENCES "HCM".employees(id),
    leave_type_id                BIGINT NOT NULL REFERENCES "HCM".leave_types(id),
    start_date                  DATE NOT NULL,
    end_date                    DATE NOT NULL,
    days_count                  NUMERIC(6,2) NOT NULL,
    reason                      TEXT,
    status                      VARCHAR(20) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending','approved','rejected','cancelled')),
    attachment_document_ref     VARCHAR(100),                        -- DMS document UUID
    workflow_instance_ref       BIGINT,                              -- WNE wne.workflow_instances.id, informational
    approved_by                 BIGINT REFERENCES "HCM".employees(id),
    approved_at                 TIMESTAMPTZ,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (end_date >= start_date)
);
COMMENT ON TABLE "HCM".leave_requests IS 'Routed through WNE (workflow_code = hcm.leave_approval) on submission (3F).';

-- ----------------------------------------------------------------------------
-- 6. PAYROLL (3G)
-- ----------------------------------------------------------------------------

CREATE TABLE "HCM".payroll_periods (
    id              BIGSERIAL PRIMARY KEY,
    period_month    SMALLINT NOT NULL CHECK (period_month BETWEEN 1 AND 12),
    period_year     INTEGER NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft'
                        CHECK (status IN ('draft','calculated','approved','paid','closed')),
    calculated_at   TIMESTAMPTZ,
    approved_at     TIMESTAMPTZ,
    paid_at         TIMESTAMPTZ,
    closed_at       TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (period_month, period_year)
);

CREATE TABLE "HCM".payroll_run_items (
    id                          BIGSERIAL PRIMARY KEY,
    payroll_period_id           BIGINT NOT NULL REFERENCES "HCM".payroll_periods(id),
    employee_id                 BIGINT NOT NULL REFERENCES "HCM".employees(id),
    base_salary                 NUMERIC(15,2) NOT NULL,
    overtime_hours               NUMERIC(6,2) NOT NULL DEFAULT 0,
    overtime_amount             NUMERIC(15,2) NOT NULL DEFAULT 0,
    allowances                  NUMERIC(15,2) NOT NULL DEFAULT 0,
    gross_pay                   NUMERIC(15,2) NOT NULL,
    bpjs_kesehatan_employee     NUMERIC(15,2) NOT NULL DEFAULT 0,
    bpjs_kesehatan_employer     NUMERIC(15,2) NOT NULL DEFAULT 0,
    bpjs_jkk_employer           NUMERIC(15,2) NOT NULL DEFAULT 0,
    bpjs_jkm_employer           NUMERIC(15,2) NOT NULL DEFAULT 0,
    bpjs_jht_employee           NUMERIC(15,2) NOT NULL DEFAULT 0,
    bpjs_jht_employer           NUMERIC(15,2) NOT NULL DEFAULT 0,
    bpjs_jp_employee            NUMERIC(15,2) NOT NULL DEFAULT 0,
    bpjs_jp_employer            NUMERIC(15,2) NOT NULL DEFAULT 0,
    pph21_amount                NUMERIC(15,2) NOT NULL DEFAULT 0,
    other_deductions            NUMERIC(15,2) NOT NULL DEFAULT 0,
    net_pay                     NUMERIC(15,2) NOT NULL,
    payslip_document_ref        VARCHAR(100),                        -- DMS document UUID
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (payroll_period_id, employee_id)
);
COMMENT ON TABLE "HCM".payroll_run_items IS 'Immutable once payroll_periods.status = closed; corrections happen via next-period adjustment row (3G).';

CREATE TABLE "HCM".thr_run_items (
    id                          BIGSERIAL PRIMARY KEY,
    employee_id                 BIGINT NOT NULL REFERENCES "HCM".employees(id),
    religious_holiday_year      INTEGER NOT NULL,
    tenure_months               INTEGER NOT NULL,
    base_salary                 NUMERIC(15,2) NOT NULL,
    thr_amount                  NUMERIC(15,2) NOT NULL,
    payment_due_date            DATE NOT NULL,                        -- statutory H-7 deadline
    paid_at                     TIMESTAMPTZ,
    payslip_document_ref        VARCHAR(100),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (employee_id, religious_holiday_year)
);
COMMENT ON TABLE "HCM".thr_run_items IS 'Tunjangan Hari Raya — 1x monthly salary, pro-rated under 12 months tenure, paid H-7 (3G).';

-- ----------------------------------------------------------------------------
-- 7. FUTURE-VERSION STUB TABLES
--    Minimal columns now so later builds are additive migrations only.
-- ----------------------------------------------------------------------------

-- 3I. Recruitment / ATS
CREATE TABLE "HCM".candidates (
    id              BIGSERIAL PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150),
    phone           VARCHAR(30),
    source          VARCHAR(50),
    status          VARCHAR(30) NOT NULL DEFAULT 'new',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "HCM".job_requisitions (
    id              BIGSERIAL PRIMARY KEY,
    job_id          BIGINT REFERENCES "HCM".jobs(id),
    org_unit_id     BIGINT REFERENCES "HCM".org_units(id),
    status          VARCHAR(30) NOT NULL DEFAULT 'open',
    opened_at       DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 3J. Performance Management
CREATE TABLE "HCM".performance_cycles (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'draft',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "HCM".goals (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id             BIGINT NOT NULL REFERENCES "HCM".employees(id),
    performance_cycle_id     BIGINT REFERENCES "HCM".performance_cycles(id),
    title                   VARCHAR(200) NOT NULL,
    description             TEXT,
    status                  VARCHAR(30) NOT NULL DEFAULT 'open',
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "HCM".reviews (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id             BIGINT NOT NULL REFERENCES "HCM".employees(id),
    performance_cycle_id     BIGINT REFERENCES "HCM".performance_cycles(id),
    reviewer_id             BIGINT REFERENCES "HCM".employees(id),
    rating                  NUMERIC(4,2),
    comments                TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 3K. Learning / LMS
CREATE TABLE "HCM".courses (
    id              BIGSERIAL PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    description     TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "HCM".enrollments (
    id              BIGSERIAL PRIMARY KEY,
    employee_id     BIGINT NOT NULL REFERENCES "HCM".employees(id),
    course_id       BIGINT NOT NULL REFERENCES "HCM".courses(id),
    status          VARCHAR(30) NOT NULL DEFAULT 'enrolled',
    enrolled_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at    TIMESTAMPTZ
);

CREATE TABLE "HCM".certifications (
    id                      BIGSERIAL PRIMARY KEY,
    employee_id             BIGINT NOT NULL REFERENCES "HCM".employees(id),
    name                    VARCHAR(150) NOT NULL,
    issued_at               DATE,
    expires_at              DATE,
    document_reference      VARCHAR(100)                          -- DMS document UUID
);

-- 3M. Compensation
CREATE TABLE "HCM".salary_bands (
    id              BIGSERIAL PRIMARY KEY,
    job_id          BIGINT NOT NULL REFERENCES "HCM".jobs(id),
    band_name       VARCHAR(50) NOT NULL,
    min_salary      NUMERIC(15,2),
    max_salary      NUMERIC(15,2)
);

-- 3N. Benefits
CREATE TABLE "HCM".benefit_plans (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    description     TEXT,
    provider        VARCHAR(150)
);

CREATE TABLE "HCM".benefit_enrollments (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id         BIGINT NOT NULL REFERENCES "HCM".employees(id),
    benefit_plan_id     BIGINT NOT NULL REFERENCES "HCM".benefit_plans(id),
    enrolled_at         DATE NOT NULL DEFAULT CURRENT_DATE,
    status              VARCHAR(30) NOT NULL DEFAULT 'active'
);

-- ----------------------------------------------------------------------------
-- 8. INDEXES
-- ----------------------------------------------------------------------------

CREATE INDEX idx_positions_org_unit ON "HCM".positions(org_unit_id);
CREATE INDEX idx_positions_reports_to ON "HCM".positions(reports_to_position_id);
CREATE INDEX idx_employees_position ON "HCM".employees(position_id);
CREATE INDEX idx_employees_status ON "HCM".employees(employment_status);
CREATE INDEX idx_contracts_employee ON "HCM".employment_contracts(employee_id);
CREATE INDEX idx_contracts_status_enddate ON "HCM".employment_contracts(status, end_date);
CREATE INDEX idx_attendance_employee_date ON "HCM".attendance_logs(employee_id, clock_in_at);
CREATE INDEX idx_shift_assignments_date ON "HCM".shift_assignments(work_date);
CREATE INDEX idx_leave_requests_employee ON "HCM".leave_requests(employee_id, status);
CREATE INDEX idx_leave_balances_employee ON "HCM".leave_balances(employee_id, period_year);
CREATE INDEX idx_payroll_run_items_period ON "HCM".payroll_run_items(payroll_period_id);
CREATE INDEX idx_payroll_run_items_employee ON "HCM".payroll_run_items(employee_id);
CREATE INDEX idx_ter_rates_category_effective ON "HCM".ter_rates(ter_category, effective_from);
CREATE INDEX idx_bpjs_rates_type_effective ON "HCM".bpjs_rates(contribution_type, effective_from);

COMMIT;
