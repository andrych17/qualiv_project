-- =============================================================================
-- HCM module — schema + example seed data
-- Source spec: app/Modules/HCM/HCM_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "HCM" — every
--              table lives inside one tenant's own database (CLAUDE.md §4/§7A),
--              no tenant_id column anywhere.
--
-- HCM owns employee IDENTITY only — all statutory payroll calculation (PPh 21,
-- BPJS, THR) and run/payslip storage live in the separate Payroll module
-- (PAYROLL.employee_payroll_profiles is a 1:1 cross-schema extension of
-- HCM.employees.id, §3G/§5). No payroll run/payslip tables are defined here.
--
-- HCM.employees is deliberately NOT the same table as CRM.partners (§5) despite
-- surface similarity — statutory fields (NIK, NPWP, BPJS, contract type) and
-- lifecycle rules (termination vs. merge/deactivate) genuinely differ. Where a
-- cross-link is useful (e.g. a partner who is also an employee),
-- `employees.linked_partner_id` is a plain informational BIGINT, deliberately
-- NOT a foreign key into "CRM".partners — same "app-enforced, not FK-enforced"
-- discipline used for CustomFields' entity_id and every subject_type/subject_id
-- pointer elsewhere in the platform.
--
-- `org_units.accounting_cost_center_id` is likewise informational only, not a
-- FK into "ACCOUNTING".cost_centers — Accounting is an optional install (§3C).
--
-- Future Version submodules (ATS, Performance, LMS, Talent, Compensation,
-- Benefits) get no tables here — §4 explicitly describes their stub tables as
-- "additive migrations only," built when each is actually scheduled, not
-- speculatively now.
--
-- This file is a reference/planning script, not a Laravel migration — port each
-- section below into `database/migrations/tenant/*.php` per CLAUDE.md §5 build
-- order (HCM comes after Purchase/Sales, right before Payroll — which has a
-- hard dependency on HCM existing first, §3G rule).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"AIINSIGHT"/"ACCOUNTING"/"CRM"/"DMS"/"CUSTOMFIELDS".
CREATE SCHEMA IF NOT EXISTS "HCM";

-- -----------------------------------------------------------------------------
-- §3C / §4. Organization & Job Structure — the org chart/job catalog everything
-- else (Employees, Payroll) hangs off.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "HCM".org_units (
    id                          BIGSERIAL PRIMARY KEY,
    parent_org_unit_id          BIGINT REFERENCES "HCM".org_units (id),
    name                        VARCHAR(150) NOT NULL,
    accounting_cost_center_id   BIGINT,                  -- informational only, NOT a FK — Accounting is an optional install (§3C)
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (id <> parent_org_unit_id)
);

CREATE TABLE IF NOT EXISTS "HCM".jobs (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(50) NOT NULL UNIQUE,
    title       VARCHAR(150) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "HCM".positions (
    id                          BIGSERIAL PRIMARY KEY,
    job_id                      BIGINT NOT NULL REFERENCES "HCM".jobs (id),
    org_unit_id                 BIGINT NOT NULL REFERENCES "HCM".org_units (id),
    reports_to_position_id      BIGINT REFERENCES "HCM".positions (id), -- drives manager resolution platform-wide (§3C)
    headcount_cap               INTEGER,                 -- optional, informational only in MVP — no enforcement engine yet
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (id <> reports_to_position_id)
);

CREATE INDEX IF NOT EXISTS idx_hcm_positions_reports_to ON "HCM".positions (reports_to_position_id);

-- -----------------------------------------------------------------------------
-- §3F / §4. Leave lookups — leave_types is tenant-editable and pre-seeded with
-- Indonesian statutory types (§3F); leave_policies scopes entitlement per type
-- × contract type. (The spec's own §4 phrase is "per ... leave type ×
-- employment status" — modeled here as `contract_type` [PKWT/PKWTT, nullable =
-- applies to both] rather than the employees.employment_status enum
-- [active/on_leave/...], since a policy that varied by "currently on leave"
-- would be circular; contract type is what Indonesian leave entitlement
-- actually turns on in practice.)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "HCM".leave_types (
    id                      BIGSERIAL PRIMARY KEY,
    code                    VARCHAR(30) NOT NULL UNIQUE,
    name                    VARCHAR(100) NOT NULL,
    is_paid                 BOOLEAN NOT NULL DEFAULT TRUE,
    requires_attachment     BOOLEAN NOT NULL DEFAULT FALSE, -- e.g. sick note past a configurable threshold (§3F)
    is_active               BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "HCM".leave_policies (
    id                          BIGSERIAL PRIMARY KEY,
    leave_type_id                BIGINT NOT NULL REFERENCES "HCM".leave_types (id),
    contract_type                  VARCHAR(5) CHECK (contract_type IN ('PKWT', 'PKWTT')), -- NULL = applies to both
    entitlement_days_per_year        NUMERIC(5, 2) NOT NULL,
    accrual_method                     VARCHAR(15) NOT NULL DEFAULT 'annual_grant'
                                           CHECK (accrual_method IN ('annual_grant', 'monthly_accrual')),
    carry_over_max_days                   NUMERIC(5, 2) NOT NULL DEFAULT 0, -- 0 = no carryover
    carry_over_expiry_months                INTEGER,
    is_paid                                   BOOLEAN NOT NULL DEFAULT TRUE, -- policy-level override of the leave type's default
    -- Same NULLS-NOT-DISTINCT caveat noted in the other *_SPECS.sql files:
    -- multiple NULL-contract_type rows for the same leave_type would NOT
    -- violate this constraint — enforce "at most one blanket policy per leave
    -- type" in the service layer if that matters.
    UNIQUE (leave_type_id, contract_type)
);

CREATE TABLE IF NOT EXISTS "HCM".shifts (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    break_minutes   INTEGER NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

-- Versioned/effective-dated (§4) — a contract-creation compliance check, not a
-- Payroll-run input (that distinction is why this table stays in HCM, §4).
CREATE TABLE IF NOT EXISTS "HCM".regional_minimum_wages (
    id                      BIGSERIAL PRIMARY KEY,
    region_code             VARCHAR(20) NOT NULL,        -- e.g. UMP_DKI_JAKARTA
    region_name             VARCHAR(150) NOT NULL,
    effective_date          DATE NOT NULL,
    monthly_wage_amount     NUMERIC(14, 2) NOT NULL,
    UNIQUE (region_code, effective_date)
);

-- -----------------------------------------------------------------------------
-- §3B / §4. Employees — the single source of truth for "who works here"
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "HCM".employees (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing (CLAUDE.md §7A)
    employee_no                 VARCHAR(30) NOT NULL UNIQUE, -- tenant-configurable format
    full_name                   VARCHAR(255) NOT NULL,
    date_of_birth                DATE,
    gender                          VARCHAR(10) CHECK (gender IN ('male', 'female', 'other')),
    nik                                CHAR(16),          -- KTP / national ID number
    npwp                                  VARCHAR(30),    -- optional; affects PPh 21 rate category if absent (§3B/§3G)
    bpjs_kesehatan_no                        VARCHAR(30),
    bpjs_ketenagakerjaan_no                     VARCHAR(30),
    address                                        TEXT,
    marital_status                                    VARCHAR(10) CHECK (marital_status IN ('single', 'married', 'divorced', 'widowed')),
    dependents_count                                     INTEGER NOT NULL DEFAULT 0, -- drives ptkp_status, computed/stored on Payroll's side (§3G)
    religion                                                VARCHAR(20), -- used only to compute THR timing (§3B) — never a filter/report field
    hire_date                                                  DATE NOT NULL,
    employment_status                                             VARCHAR(12) NOT NULL DEFAULT 'active'
                                                                       CHECK (employment_status IN ('active', 'on_leave', 'suspended', 'terminated')),
    position_id                                                       BIGINT REFERENCES "HCM".positions (id), -- nullable: a new hire may be created before a seat is finalized
    bank_name                                                            VARCHAR(100),
    bank_account_no                                                         VARCHAR(50),
    bank_account_holder_name                                                   VARCHAR(150),
    linked_partner_id                                                             BIGINT, -- informational only, NOT a FK into "CRM".partners (§5)
    termination_date                                                                 DATE,
    termination_reason                                                                  VARCHAR(255),
    created_at                                                                             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                                                TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK ((employment_status = 'terminated') = (termination_date IS NOT NULL))
);

CREATE INDEX IF NOT EXISTS idx_hcm_employees_position ON "HCM".employees (position_id, employment_status);

CREATE TABLE IF NOT EXISTS "HCM".employee_position_history (
    id              BIGSERIAL PRIMARY KEY,
    employee_id     BIGINT NOT NULL REFERENCES "HCM".employees (id),
    position_id     BIGINT NOT NULL REFERENCES "HCM".positions (id),
    effective_from  DATE NOT NULL,
    effective_to    DATE,                                 -- NULL = current
    changed_by      BIGINT REFERENCES users (id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_hcm_employee_position_history_employee ON "HCM".employee_position_history (employee_id, effective_from);

-- -----------------------------------------------------------------------------
-- §3D / §4. Employment & Contracts — the legal basis of employment
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "HCM".employment_contracts (
    id                          BIGSERIAL PRIMARY KEY,
    employee_id                  BIGINT NOT NULL REFERENCES "HCM".employees (id),
    contract_type                   VARCHAR(5) NOT NULL CHECK (contract_type IN ('PKWT', 'PKWTT')),
    start_date                         DATE NOT NULL,
    end_date                              DATE,           -- required for PKWT, must be NULL for PKWTT (checked below)
    base_salary                              NUMERIC(14, 2) NOT NULL,
    work_location                               VARCHAR(150),
    probation_end_date                             DATE,  -- PKWTT only — Indonesian law disallows probation on PKWT (§3D)
    status                                            VARCHAR(12) NOT NULL DEFAULT 'active'
                                                          CHECK (status IN ('active', 'expired', 'terminated', 'renewed')),
    renewed_from_contract_id                                BIGINT REFERENCES "HCM".employment_contracts (id), -- PP 35/2021 5-year PKWT cap tracking (§3D)
    created_at                                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Document reference (contract PDF, etc.) is NOT a column here — DMS's own
    -- `documents` row points back via subject_type = 'hcm.employment_contracts'
    -- / subject_id = this row's id (§3B/§4), never the other way around.
    CHECK (contract_type <> 'PKWT' OR end_date IS NOT NULL),
    CHECK (contract_type <> 'PKWT' OR probation_end_date IS NULL)
);

CREATE INDEX IF NOT EXISTS idx_hcm_employment_contracts_employee ON "HCM".employment_contracts (employee_id, status);
CREATE INDEX IF NOT EXISTS idx_hcm_employment_contracts_end_date ON "HCM".employment_contracts (end_date) WHERE status = 'active';

-- -----------------------------------------------------------------------------
-- §3E / §4. Time & Attendance
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "HCM".shift_assignments (
    id              BIGSERIAL PRIMARY KEY,
    employee_id     BIGINT NOT NULL REFERENCES "HCM".employees (id),
    shift_id        BIGINT NOT NULL REFERENCES "HCM".shifts (id),
    work_date       DATE NOT NULL,
    UNIQUE (employee_id, work_date)
);

CREATE TABLE IF NOT EXISTS "HCM".attendance_logs (
    id              BIGSERIAL PRIMARY KEY,
    employee_id     BIGINT NOT NULL REFERENCES "HCM".employees (id),
    clock_in_at     TIMESTAMPTZ,
    clock_out_at    TIMESTAMPTZ,
    source          VARCHAR(10) NOT NULL DEFAULT 'web' CHECK (source IN ('web')), -- reserved values added when biometric/mobile ship (§3E)
    exception_flag  VARCHAR(10) CHECK (exception_flag IN ('on_time', 'late', 'absent')), -- computed against the assigned shift (§3E)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_hcm_attendance_logs_employee ON "HCM".attendance_logs (employee_id, created_at);

CREATE TABLE IF NOT EXISTS "HCM".attendance_corrections (
    id                          BIGSERIAL PRIMARY KEY,
    attendance_log_id            BIGINT NOT NULL REFERENCES "HCM".attendance_logs (id),
    employee_id                     BIGINT NOT NULL REFERENCES "HCM".employees (id),
    requested_clock_in_at              TIMESTAMPTZ,
    requested_clock_out_at                TIMESTAMPTZ,
    reason                                    TEXT,
    status                                       VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    reviewed_by                                     BIGINT REFERENCES users (id),
    reviewed_at                                        TIMESTAMPTZ,
    created_at                                            TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3F / §4. Leave transaction tables
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "HCM".leave_balances (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id          BIGINT NOT NULL REFERENCES "HCM".employees (id),
    leave_type_id           BIGINT NOT NULL REFERENCES "HCM".leave_types (id),
    period_year                 SMALLINT NOT NULL,
    entitled_days                   NUMERIC(5, 2) NOT NULL DEFAULT 0,
    used_days                          NUMERIC(5, 2) NOT NULL DEFAULT 0,
    carried_over_days                     NUMERIC(5, 2) NOT NULL DEFAULT 0,
    UNIQUE (employee_id, leave_type_id, period_year)
);

CREATE TABLE IF NOT EXISTS "HCM".leave_requests (
    id                  BIGSERIAL PRIMARY KEY,
    employee_id          BIGINT NOT NULL REFERENCES "HCM".employees (id),
    leave_type_id            BIGINT NOT NULL REFERENCES "HCM".leave_types (id),
    start_date                   DATE NOT NULL,
    end_date                        DATE NOT NULL,
    reason                              TEXT,
    status                                 VARCHAR(10) NOT NULL DEFAULT 'pending'
                                               CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
    reviewed_by                              BIGINT REFERENCES users (id),
    reviewed_at                                 TIMESTAMPTZ,
    created_at                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (end_date >= start_date)
);

CREATE INDEX IF NOT EXISTS idx_hcm_leave_requests_employee ON "HCM".leave_requests (employee_id, status);

-- -----------------------------------------------------------------------------
-- §4. Future-Version stub tables (ATS, Performance, LMS, Talent, Compensation,
-- Benefits) are deliberately NOT created here — §4 describes them as "empty/
-- minimal at launch, additive migrations only," i.e. built when each submodule
-- is actually scheduled (§3I–§3N), not spun up speculatively in the MVP schema:
--   HCM.candidates, HCM.job_requisitions          (ATS, §3I)
--   HCM.performance_cycles, HCM.goals, HCM.reviews (Performance, §3J)
--   HCM.courses, HCM.enrollments, HCM.certifications (LMS, §3K)
--   HCM.salary_bands                              (Compensation, §3M)
--   HCM.benefit_plans, HCM.benefit_enrollments     (Benefits, §3N)
-- -----------------------------------------------------------------------------

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- An org chart (Operations -> Litigation), a job/position pair with a reporting
-- line, Indonesian statutory leave types + an Annual leave policy, a shift, a
-- regional minimum wage row, one employee on a PKWTT contract with an initial
-- position-history entry, a shift assignment + attendance log, and both a
-- historical (approved, already deducted) and a new (pending) leave request.
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset —
-- so it runs through any Postgres client. Document-creating and dependent-
-- update statements are kept as SEPARATE top-level statements (see
-- DMS_SPECS.sql's header note) to avoid PostgreSQL's same-statement,
-- same-snapshot limitation on data-modifying CTEs.
-- =============================================================================

BEGIN;

WITH ops AS (
    INSERT INTO "HCM".org_units (name) VALUES ('Operations') RETURNING id
)
INSERT INTO "HCM".org_units (parent_org_unit_id, name)
SELECT id, 'Litigation' FROM ops;

INSERT INTO "HCM".jobs (code, title) VALUES
    ('MANAGING_PARTNER', 'Managing Partner'),
    ('SENIOR_ASSOCIATE', 'Senior Associate');

WITH mp_position AS (
    INSERT INTO "HCM".positions (job_id, org_unit_id)
    SELECT j.id, ou.id FROM "HCM".jobs j, "HCM".org_units ou
    WHERE j.code = 'MANAGING_PARTNER' AND ou.name = 'Operations'
    RETURNING id
)
INSERT INTO "HCM".positions (job_id, org_unit_id, reports_to_position_id)
SELECT j.id, ou.id, mp_position.id
FROM mp_position, "HCM".jobs j, "HCM".org_units ou
WHERE j.code = 'SENIOR_ASSOCIATE' AND ou.name = 'Litigation';

-- Indonesian statutory leave types, pre-seeded per §3F
INSERT INTO "HCM".leave_types (code, name, is_paid, requires_attachment) VALUES
    ('ANNUAL', 'Cuti Tahunan', TRUE, FALSE),
    ('SICK', 'Cuti Sakit', TRUE, TRUE),
    ('MATERNITY', 'Cuti Melahirkan', TRUE, FALSE),
    ('PATERNITY', 'Cuti Ayah', TRUE, FALSE),
    ('MARRIAGE', 'Cuti Menikah', TRUE, FALSE),
    ('BEREAVEMENT', 'Cuti Duka', TRUE, FALSE),
    ('HAJJ', 'Cuti Haji', FALSE, FALSE);

INSERT INTO "HCM".leave_policies (leave_type_id, contract_type, entitlement_days_per_year, accrual_method, carry_over_max_days)
SELECT id, 'PKWTT', 12, 'annual_grant', 6 FROM "HCM".leave_types WHERE code = 'ANNUAL';

INSERT INTO "HCM".shifts (name, start_time, end_time, break_minutes) VALUES
    ('Kantor 09-17', '09:00', '17:00', 60);

INSERT INTO "HCM".regional_minimum_wages (region_code, region_name, effective_date, monthly_wage_amount)
VALUES ('UMP_DKI_JAKARTA', 'DKI Jakarta', '2026-01-01', 5396761);

-- One employee on a PKWTT contract, holding the Senior Associate position
INSERT INTO "HCM".employees (employee_no, full_name, date_of_birth, gender, nik, npwp, bpjs_kesehatan_no, bpjs_ketenagakerjaan_no, marital_status, dependents_count, religion, hire_date, employment_status, position_id, bank_name, bank_account_no, bank_account_holder_name)
SELECT 'EMP-0001', 'Siti Aminah', '1992-04-10', 'female', '3171234567890001', '09.876.543.2-109.000',
       '0001234567890', '11223344556',
       'married', 1, 'Islam', '2024-01-15', 'active', p.id, 'Bank BCA', '9988776655', 'Siti Aminah'
FROM "HCM".positions p
JOIN "HCM".jobs j ON j.id = p.job_id
WHERE j.code = 'SENIOR_ASSOCIATE';

INSERT INTO "HCM".employee_position_history (employee_id, position_id, effective_from)
SELECT e.id, e.position_id, e.hire_date FROM "HCM".employees e WHERE e.employee_no = 'EMP-0001';

INSERT INTO "HCM".employment_contracts (employee_id, contract_type, start_date, base_salary, work_location, probation_end_date, status)
SELECT id, 'PKWTT', hire_date, 15000000, 'Jakarta HQ', hire_date + INTERVAL '3 months', 'active'
FROM "HCM".employees WHERE employee_no = 'EMP-0001';

INSERT INTO "HCM".shift_assignments (employee_id, shift_id, work_date)
SELECT e.id, s.id, CURRENT_DATE
FROM "HCM".employees e, "HCM".shifts s
WHERE e.employee_no = 'EMP-0001' AND s.name = 'Kantor 09-17';

INSERT INTO "HCM".attendance_logs (employee_id, clock_in_at, clock_out_at, exception_flag)
SELECT id, CURRENT_DATE + TIME '08:55', CURRENT_DATE + TIME '17:10', 'on_time'
FROM "HCM".employees WHERE employee_no = 'EMP-0001';

-- Leave balance for 2026: 12 entitled, 2 already used (the approved request below)
INSERT INTO "HCM".leave_balances (employee_id, leave_type_id, period_year, entitled_days, used_days)
SELECT e.id, lt.id, 2026, 12, 2
FROM "HCM".employees e, "HCM".leave_types lt
WHERE e.employee_no = 'EMP-0001' AND lt.code = 'ANNUAL';

-- A historical approved leave request (already reflected in the balance above)
-- and a new pending one (not yet deducted — deduction happens on approval, §3F rule)
INSERT INTO "HCM".leave_requests (employee_id, leave_type_id, start_date, end_date, reason, status, reviewed_by, reviewed_at)
SELECT e.id, lt.id, DATE '2026-06-02', DATE '2026-06-03', 'Family event', 'approved',
       (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), TIMESTAMPTZ '2026-05-28 10:00:00+07'
FROM "HCM".employees e, "HCM".leave_types lt
WHERE e.employee_no = 'EMP-0001' AND lt.code = 'ANNUAL'
UNION ALL
SELECT e.id, lt.id, CURRENT_DATE + 14, CURRENT_DATE + 16, 'Personal travel', 'pending', NULL::bigint, NULL::timestamptz
FROM "HCM".employees e, "HCM".leave_types lt
WHERE e.employee_no = 'EMP-0001' AND lt.code = 'ANNUAL';

COMMIT;
