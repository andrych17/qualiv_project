-- =============================================================================
-- PAYROLL MODULE — SAMPLE / SEED DATA
-- Depends on payroll_schema.sql having been run first.
-- Explicit primary key IDs are used throughout for referential predictability
-- and cross-reference readability, per project convention.
--
-- *** IMPORTANT — READ BEFORE USING IN PRODUCTION ***
-- The statutory rule tables below (PTKP, TER brackets, PPh21 progressive
-- brackets, BPJS rules, overtime multipliers, severance multipliers) are
-- seeded with a REPRESENTATIVE, ABBREVIATED data set for development and
-- demo purposes — not the full, verbatim regulatory annex.
--   - PTKP amounts, TER category groupings (A/B/C), and the 5-bracket annual
--     progressive rate table (5/15/25/30/35%) reflect current published law
--     and are reasonably safe as-is.
--   - The TER monthly rate brackets (PMK 168/2023) are officially ~30-39
--     narrow brackets PER category. Only a representative subset is seeded
--     here (enough to exercise the engine end-to-end). Load the FULL official
--     bracket table from the PMK 168/2023 annex via the Regulatory Rules
--     admin screen (§3B) before running real payroll.
--   - BPJS Kesehatan/Ketenagakerjaan percentages and wage caps, and the
--     severance multiplier table, are simplified representative values.
--     Verify current figures against BPJS Kesehatan/BPJS Ketenagakerjaan and
--     UU Cipta Kerja / PP 35/2021 before go-live, then correct via the
--     versioned rule tables (never by editing application code).
-- =============================================================================

BEGIN;
SET search_path TO "PAYROLL", public;

-- =============================================================================
-- 1. VERSIONED STATUTORY RULES — effective 2026-01-01
-- =============================================================================

-- PTKP statuses + TER category mapping
INSERT INTO "PAYROLL".ptkp_statuses (id, code, description, annual_ptkp_amount, ter_category, effective_date) VALUES
    (1, 'TK/0', 'Tidak Kawin, 0 tanggungan',        54000000, 'A', '2026-01-01'),
    (2, 'TK/1', 'Tidak Kawin, 1 tanggungan',         58500000, 'A', '2026-01-01'),
    (3, 'TK/2', 'Tidak Kawin, 2 tanggungan',         63000000, 'B', '2026-01-01'),
    (4, 'TK/3', 'Tidak Kawin, 3 tanggungan',         67500000, 'B', '2026-01-01'),
    (5, 'K/0',  'Kawin, 0 tanggungan',               58500000, 'A', '2026-01-01'),
    (6, 'K/1',  'Kawin, 1 tanggungan',               63000000, 'B', '2026-01-01'),
    (7, 'K/2',  'Kawin, 2 tanggungan',               67500000, 'B', '2026-01-01'),
    (8, 'K/3',  'Kawin, 3 tanggungan',               72000000, 'C', '2026-01-01');

INSERT INTO "PAYROLL".ter_categories (id, category, description) VALUES
    (1, 'A', 'TER Kategori A — PTKP TK/0, TK/1, K/0'),
    (2, 'B', 'TER Kategori B — PTKP TK/2, TK/3, K/1, K/2'),
    (3, 'C', 'TER Kategori C — PTKP K/3');

-- TER monthly rate brackets — REPRESENTATIVE SUBSET per category (see notice above)
INSERT INTO "PAYROLL".ter_rate_brackets (ter_category, bracket_lower_bound, bracket_upper_bound, rate_pct, effective_date) VALUES
    -- Category A
    ('A',           0,   5400000, 0.0000, '2026-01-01'),
    ('A',     5400000,   5650000, 0.0025, '2026-01-01'),
    ('A',     5650000,   6300000, 0.0075, '2026-01-01'),
    ('A',     6300000,   8550000, 0.0150, '2026-01-01'),
    ('A',     8550000,  10050000, 0.0200, '2026-01-01'),
    ('A',    10050000,  12500000, 0.0400, '2026-01-01'),
    ('A',    12500000,  16950000, 0.0700, '2026-01-01'),
    ('A',    16950000,  24150000, 0.0900, '2026-01-01'),
    ('A',    24150000,  35400000, 0.1400, '2026-01-01'),
    ('A',    35400000,  56300000, 0.1900, '2026-01-01'),
    ('A',    56300000, 103000000, 0.2400, '2026-01-01'),
    ('A',   103000000, 337000000, 0.2800, '2026-01-01'),
    ('A',   337000000,1400000000, 0.3300, '2026-01-01'),
    ('A',  1400000000,       NULL, 0.3400, '2026-01-01'),
    -- Category B
    ('B',           0,   6200000, 0.0000, '2026-01-01'),
    ('B',     6200000,   6500000, 0.0050, '2026-01-01'),
    ('B',     6500000,   7300000, 0.0150, '2026-01-01'),
    ('B',     7300000,   9200000, 0.0250, '2026-01-01'),
    ('B',     9200000,  11600000, 0.0350, '2026-01-01'),
    ('B',    11600000,  13600000, 0.0500, '2026-01-01'),
    ('B',    13600000,  16000000, 0.0800, '2026-01-01'),
    ('B',    16000000,  27700000, 0.1300, '2026-01-01'),
    ('B',    27700000,  38900000, 0.1800, '2026-01-01'),
    ('B',    38900000,  59300000, 0.2300, '2026-01-01'),
    ('B',    59300000, 117400000, 0.2800, '2026-01-01'),
    ('B',   117400000,1360000000, 0.3300, '2026-01-01'),
    ('B',  1360000000,       NULL, 0.3400, '2026-01-01'),
    -- Category C
    ('C',           0,   6600000, 0.0000, '2026-01-01'),
    ('C',     6600000,   6950000, 0.0050, '2026-01-01'),
    ('C',     6950000,   7350000, 0.0150, '2026-01-01'),
    ('C',     7350000,   9800000, 0.0250, '2026-01-01'),
    ('C',     9800000,  16400000, 0.0500, '2026-01-01'),
    ('C',    16400000,  27000000, 0.1000, '2026-01-01'),
    ('C',    27000000,  39900000, 0.1600, '2026-01-01'),
    ('C',    39900000,  61200000, 0.2100, '2026-01-01'),
    ('C',    61200000, 121000000, 0.2600, '2026-01-01'),
    ('C',   121000000,1450000000, 0.3300, '2026-01-01'),
    ('C',  1450000000,       NULL, 0.3400, '2026-01-01');

-- TER daily rates (non-permanent/daily workers)
INSERT INTO "PAYROLL".ter_daily_rates (bracket_lower_bound, bracket_upper_bound, rate_pct, effective_date) VALUES
    (      0,  450000, 0.0000, '2026-01-01'),
    ( 450000, 2500000, 0.0050, '2026-01-01');

-- Annual Pasal 17 progressive brackets (December reconciliation + irregular income)
INSERT INTO "PAYROLL".pph21_progressive_brackets (bracket_lower_bound, bracket_upper_bound, rate_pct, effective_date) VALUES
    (          0,   60000000, 0.05, '2026-01-01'),
    (   60000000,  250000000, 0.15, '2026-01-01'),
    (  250000000,  500000000, 0.25, '2026-01-01'),
    (  500000000, 5000000000, 0.30, '2026-01-01'),
    ( 5000000000,       NULL, 0.35, '2026-01-01');

-- Overtime multiplier rules (Kepmenaker 102/2004 basis, simplified — see notice above)
INSERT INTO "PAYROLL".overtime_multiplier_rules (day_type, hour_sequence, multiplier, effective_date) VALUES
    ('weekday',          'first_hour',        1.50, '2026-01-01'),
    ('weekday',          'subsequent_hours',  2.00, '2026-01-01'),
    ('weekly_rest_day',  'standard',          2.00, '2026-01-01'),
    ('public_holiday',   'standard',          3.00, '2026-01-01');

-- BPJS Kesehatan
INSERT INTO "PAYROLL".bpjs_kesehatan_rules (employer_pct, employee_pct, wage_cap, effective_date) VALUES
    (0.04, 0.01, 12000000, '2026-01-01');

-- BPJS Ketenagakerjaan (JKK handled separately via jkk_risk_categories)
INSERT INTO "PAYROLL".bpjs_ketenagakerjaan_rules (program, employer_pct, employee_pct, wage_cap, effective_date) VALUES
    ('JHT', 0.0370, 0.0200, NULL,       '2026-01-01'),
    ('JP',  0.0200, 0.0100, 10547400,   '2026-01-01'),
    ('JKM', 0.0030, 0.0000, NULL,       '2026-01-01'),
    ('JKP', 0.0000, 0.0000, NULL,       '2026-01-01');   -- government/JKK-JKM-funded, no direct employer add-on

INSERT INTO "PAYROLL".jkk_risk_categories (id, code, name, risk_tier, employer_pct, effective_date) VALUES
    (1, 'VL', 'Sangat Rendah (kantor/admin)', 'very_low',  0.0024, '2026-01-01'),
    (2, 'L',  'Rendah',                        'low',       0.0054, '2026-01-01'),
    (3, 'M',  'Sedang',                        'medium',    0.0089, '2026-01-01'),
    (4, 'H',  'Tinggi',                        'high',      0.0127, '2026-01-01'),
    (5, 'VH', 'Sangat Tinggi',                 'very_high', 0.0174, '2026-01-01');

-- Severance (UU Cipta Kerja / PP 35/2021 basis, simplified — see notice above)
-- Pesangon (multiplier = months of pay), by tenure band, termination_reason = 'redundancy'
INSERT INTO "PAYROLL".severance_rule_tables (termination_reason, tenure_min_years, tenure_max_years, pesangon_multiplier, upmk_multiplier, uph_pct, effective_date) VALUES
    ('redundancy', 0,  1,  1, 0, 0.15, '2026-01-01'),
    ('redundancy', 1,  2,  2, 0, 0.15, '2026-01-01'),
    ('redundancy', 2,  3,  3, 0, 0.15, '2026-01-01'),
    ('redundancy', 3,  4,  4, 0, 0.15, '2026-01-01'),
    ('redundancy', 4,  5,  5, 0, 0.15, '2026-01-01'),
    ('redundancy', 5,  6,  6, 0, 0.15, '2026-01-01'),
    ('redundancy', 6,  7,  7, 0, 0.15, '2026-01-01'),
    ('redundancy', 7,  8,  8, 0, 0.15, '2026-01-01'),
    ('redundancy', 8, NULL, 9, 0, 0.15, '2026-01-01'),
    -- UPMK kicks in from 3 years tenure regardless of pesangon band
    ('redundancy', 3,  6,  0, 2, 0.15, '2026-01-01'),
    ('redundancy', 6,  9,  0, 3, 0.15, '2026-01-01'),
    ('redundancy', 9, 12,  0, 4, 0.15, '2026-01-01'),
    ('redundancy',12, NULL,0, 5, 0.15, '2026-01-01'),
    -- Resignation: no statutory pesangon/UPMK, UPH only (unused leave, relocation, etc.)
    ('resignation', 0, NULL, 0, 0, 0.15, '2026-01-01'),
    -- Retirement: full pesangon + UPMK (2x pesangon multiplier per UU Cipta Kerja, simplified here as redundancy-equivalent)
    ('retirement', 0, NULL, 9, 5, 0.15, '2026-01-01');

-- =============================================================================
-- 2. SETUP / MASTER DATA
-- =============================================================================

INSERT INTO "PAYROLL".bank_master (id, bank_code, bank_name, payment_file_format) VALUES
    (1, 'BCA', 'Bank Central Asia',        'csv_bank_specific'),
    (2, 'MDR', 'Bank Mandiri',             'csv_bank_specific'),
    (3, 'BNI', 'Bank Negara Indonesia',    'csv_bank_specific');

INSERT INTO "PAYROLL".grades (id, code, name, description) VALUES
    (1, 'STAFF-1', 'Staff Grade 1', 'Entry-level staff'),
    (2, 'STAFF-2', 'Staff Grade 2', 'Mid-level staff'),
    (3, 'MGR-1',   'Manager Grade 1', 'First-line manager');

INSERT INTO "PAYROLL".payroll_calendars (id, name, pay_frequency, cutoff_day_rule, pay_date_rule, shift_earlier_on_holiday) VALUES
    (1, 'Monthly — Standard', 'monthly', '26th of prior month to 25th of current month', 'Last working day of month', TRUE);

-- Payroll Components: earnings
INSERT INTO "PAYROLL".payroll_components (id, code, name, type, category, calculation_basis, is_taxable, is_bpjs_basis, is_system_defined) VALUES
    (1, 'BASIC',       'Basic Salary',            'earning', 'fixed',   'flat_amount', TRUE,  TRUE,  FALSE),
    (2, 'POS_ALLOW',   'Position Allowance',       'earning', 'fixed',   'flat_amount', TRUE,  TRUE,  FALSE),
    (3, 'TRANS_ALLOW', 'Transport Allowance',      'earning', 'fixed',   'flat_amount', TRUE,  FALSE, FALSE),
    (4, 'OT_PAY',      'Overtime Pay',             'earning', 'variable_input', 'flat_amount', TRUE, FALSE, FALSE),
    (5, 'BONUS',       'Discretionary Bonus',      'earning', 'variable_input', 'flat_amount', TRUE, FALSE, FALSE),
    (6, 'COMMISSION',  'Sales Commission',         'earning', 'variable_input', 'flat_amount', TRUE, FALSE, FALSE),
    (7, 'REIMBURSE',   'Reimbursement',            'earning', 'variable_input', 'flat_amount', FALSE, FALSE, FALSE),
    (8, 'THR_PAY',     'THR (Tunjangan Hari Raya)','earning', 'formula', 'flat_amount', TRUE, FALSE, FALSE);

-- Payroll Components: statutory deductions (system-defined, delegate to engines)
INSERT INTO "PAYROLL".payroll_components (id, code, name, type, category, calculation_basis, statutory_engine, is_taxable, is_bpjs_basis, is_system_defined) VALUES
    (9,  'PPH21',        'PPh 21 Withholding',              'deduction', 'statutory', 'statutory_engine', 'pph21',                 FALSE, FALSE, TRUE),
    (10, 'BPJS_KES_EE',  'BPJS Kesehatan (Employee)',        'deduction', 'statutory', 'statutory_engine', 'bpjs_kesehatan',        FALSE, FALSE, TRUE),
    (11, 'BPJS_TK_EE',   'BPJS Ketenagakerjaan (Employee)',  'deduction', 'statutory', 'statutory_engine', 'bpjs_ketenagakerjaan',  FALSE, FALSE, TRUE);

-- Payroll Components: non-statutory deductions
INSERT INTO "PAYROLL".payroll_components (id, code, name, type, category, calculation_basis, is_taxable, is_bpjs_basis) VALUES
    (12, 'LOAN_INST', 'Loan Installment',    'deduction', 'variable_input', 'flat_amount', FALSE, FALSE),
    (13, 'ADVANCE',   'Salary Advance Repayment', 'deduction', 'variable_input', 'flat_amount', FALSE, FALSE);

INSERT INTO "PAYROLL".salary_structures (id, code, name, grade_id) VALUES
    (1, 'SS-STAFF-1', 'Staff Grade 1 Structure', 1),
    (2, 'SS-STAFF-2', 'Staff Grade 2 Structure', 2),
    (3, 'SS-MGR-1',   'Manager Grade 1 Structure', 3);

INSERT INTO "PAYROLL".salary_structure_components (salary_structure_id, payroll_component_id, default_amount, sort_order) VALUES
    (1, 1, 6000000, 1), (1, 2, 500000, 2), (1, 3, 500000, 3),
    (2, 1, 9000000, 1), (2, 2, 1500000, 2), (2, 3, 750000, 3),
    (3, 1, 15000000, 1), (3, 2, 3000000, 2), (3, 3, 1000000, 3);

INSERT INTO "PAYROLL".payroll_groups (id, name, legal_entity_name, default_payroll_calendar_id, default_salary_structure_id, default_jkk_risk_category_id) VALUES
    (1, 'Monthly Staff — PT Nusa Evo Digital', 'PT Nusa Evo Digital', 1, 2, 1);

INSERT INTO "PAYROLL".loan_types (id, code, name, max_term_months, interest_method) VALUES
    (1, 'KOOP', 'Koperasi Loan', 12, 'none'),
    (2, 'EMER', 'Emergency Loan', 6, 'none');

INSERT INTO "PAYROLL".deduction_rule_configs (id, code, name, loan_type_id, insufficient_pay_behavior) VALUES
    (1, 'KOOP-DEFAULT', 'Koperasi Loan — Default Rule', 1, 'skip_and_roll_forward'),
    (2, 'EMER-DEFAULT', 'Emergency Loan — Default Rule', 2, 'partial_deduction');

INSERT INTO "PAYROLL".reimbursement_categories (id, code, name, is_taxable) VALUES
    (1, 'TRANSPORT', 'Transport / Fuel', FALSE),
    (2, 'MEDICAL',   'Medical (non-BPJS)', FALSE),
    (3, 'OFFICE',    'Office Supplies', FALSE);

-- =============================================================================
-- 3. EMPLOYEES
-- =============================================================================

INSERT INTO "PAYROLL".employees
    (id, employee_number, full_name, identity_number, npwp_number, employment_status,
     join_date, ptkp_status_code, bpjs_kesehatan_number, bpjs_ketenagakerjaan_number,
     payroll_group_id, salary_structure_id, grade_id, jkk_risk_category_id)
VALUES
    (1, 'EMP-0001', 'Siti Rahayu',      '3273010101900001', '09.123.456.7-013.000', 'active', '2022-03-01', 'TK/0', '0001234567890', '11223344556', 1, 1, 1, 1),
    (2, 'EMP-0002', 'Budi Santoso',     '3273010101880002', '09.123.456.8-013.000', 'active', '2021-06-15', 'K/1',  '0001234567891', '11223344557', 1, 2, 2, 1),
    (3, 'EMP-0003', 'Dewi Anggraini',   '3273010101920003', '09.123.456.9-013.000', 'active', '2023-01-10', 'K/0',  '0001234567892', '11223344558', 1, 2, 2, 1),
    (4, 'EMP-0004', 'Rudi Hartono',     '3273010101850004', '09.123.457.0-013.000', 'active', '2019-09-01', 'K/3',  '0001234567893', '11223344559', 1, 3, 3, 1),
    (5, 'EMP-0005', 'Ayu Lestari',      '3273010101950005', '09.123.457.1-013.000', 'terminated', '2020-02-01', 'TK/1', '0001234567894', '11223344560', 1, 1, 1, 1);

UPDATE "PAYROLL".employees SET termination_date = '2026-06-30', termination_reason = 'redundancy' WHERE id = 5;

INSERT INTO "PAYROLL".employee_bank_accounts (employee_id, bank_id, account_number, account_holder_name, is_primary) VALUES
    (1, 1, '1234567890', 'Siti Rahayu', TRUE),
    (2, 2, '2234567890', 'Budi Santoso', TRUE),
    (3, 1, '3234567890', 'Dewi Anggraini', TRUE),
    (4, 3, '4234567890', 'Rudi Hartono', TRUE),
    (5, 1, '5234567890', 'Ayu Lestari', TRUE);

-- =============================================================================
-- 4. PAYROLL PERIODS
-- =============================================================================

INSERT INTO "PAYROLL".payroll_periods
    (id, payroll_group_id, payroll_calendar_id, period_start, period_end, cutoff_date, scheduled_pay_date, status)
VALUES
    (1, 1, 1, '2026-06-26', '2026-07-25', '2026-07-25', '2026-07-31', 'locked'),
    (2, 1, 1, '2026-07-26', '2026-08-25', '2026-08-25', '2026-08-31', 'open');

-- =============================================================================
-- 5. REGULAR PAYROLL RUN — period 1, already paid & locked
-- =============================================================================

INSERT INTO "PAYROLL".payroll_runs
    (id, run_type, payroll_group_id, payroll_period_id, status, submitted_at, approved_at, paid_at)
VALUES
    (1, 'regular', 1, 1, 'locked', '2026-07-26 09:00:00+07', '2026-07-27 10:00:00+07', '2026-07-31 08:00:00+07');

-- Payroll Run Lines: employees 1-4 (employee 5 already terminated before this period)
INSERT INTO "PAYROLL".payroll_run_lines
    (id, payroll_run_id, employee_id, gross_earnings, total_deductions, net_pay, employer_cost, status)
VALUES
    (1, 1, 1, 7000000, 495000,  6505000, 7402500, 'locked'),
    (2, 1, 2, 11250000, 1123000, 10127000, 11898750, 'locked'),
    (3, 1, 3, 11250000, 968000, 10282000, 11898750, 'locked'),
    (4, 1, 4, 19000000, 2431000, 16569000, 20077500, 'locked');

-- Line components — Employee 1 (Siti Rahayu, Staff Grade 1)
INSERT INTO "PAYROLL".payroll_run_line_components (payroll_run_line_id, payroll_component_id, amount) VALUES
    (1, 1,  6000000),   -- Basic
    (1, 2,   500000),   -- Position Allowance
    (1, 3,   500000),   -- Transport Allowance
    (1, 9,   -95000),   -- PPh21 (TER A)
    (1, 10,  -70000),   -- BPJS Kesehatan employee 1%
    (1, 11, -140000);   -- BPJS Ketenagakerjaan employee (JHT 2% + JP 1%) approx on 7,000,000 basis

-- Line components — Employee 2 (Budi Santoso, Staff Grade 2, K/1)
INSERT INTO "PAYROLL".payroll_run_line_components (payroll_run_line_id, payroll_component_id, amount) VALUES
    (2, 1,  9000000),
    (2, 2,  1500000),
    (2, 3,   750000),
    (2, 9,  -450000),
    (2, 10, -112500),
    (2, 11, -337500);

-- Line components — Employee 3 (Dewi Anggraini, Staff Grade 2, K/0)
INSERT INTO "PAYROLL".payroll_run_line_components (payroll_run_line_id, payroll_component_id, amount) VALUES
    (3, 1,  9000000),
    (3, 2,  1500000),
    (3, 3,   750000),
    (3, 9,  -300000),
    (3, 10, -112500),
    (3, 11, -337500);

-- Line components — Employee 4 (Rudi Hartono, Manager Grade 1, K/3)
INSERT INTO "PAYROLL".payroll_run_line_components (payroll_run_line_id, payroll_component_id, amount) VALUES
    (4, 1, 15000000),
    (4, 2,  3000000),
    (4, 3,  1000000),
    (4, 9, -1550000),
    (4, 10, -120000),      -- capped at wage_cap 12,000,000 * 1%
    (4, 11, -570000);

-- PPh 21 audit detail (monthly TER mode)
INSERT INTO "PAYROLL".pph21_calculations
    (payroll_run_line_id, employee_id, calculation_mode, ptkp_status_code, ter_category, taxable_gross, rate_pct_applied, tax_amount, rule_effective_date)
VALUES
    (1, 1, 'monthly_ter', 'TK/0', 'A', 7000000,  0.0200, 95000,  '2026-01-01'),
    (2, 2, 'monthly_ter', 'K/1',  'B', 11250000, 0.0500, 450000, '2026-01-01'),
    (3, 3, 'monthly_ter', 'K/0',  'A', 11250000, 0.0400, 300000, '2026-01-01'),
    (4, 4, 'monthly_ter', 'K/3',  'C', 19000000, 0.1000, 1550000,'2026-01-01');

-- BPJS Kesehatan contributions
INSERT INTO "PAYROLL".bpjs_kesehatan_contributions
    (payroll_run_line_id, employee_id, wage_base, wage_base_capped, employer_amount, employee_amount, rule_effective_date)
VALUES
    (1, 1, 7000000,  7000000,  280000, 70000,  '2026-01-01'),
    (2, 2, 11250000, 11250000, 450000, 112500, '2026-01-01'),
    (3, 3, 11250000, 11250000, 450000, 112500, '2026-01-01'),
    (4, 4, 19000000, 12000000, 480000, 120000, '2026-01-01');  -- capped at 12,000,000

-- BPJS Ketenagakerjaan contributions (JHT, JP, JKK, JKM per employee)
INSERT INTO "PAYROLL".bpjs_ketenagakerjaan_contributions
    (payroll_run_line_id, employee_id, program, wage_base, wage_base_capped, employer_amount, employee_amount, rule_effective_date)
VALUES
    -- Employee 1 (basis 7,000,000; JP capped at 10,547,400 -> no cap hit here)
    (1, 1, 'JHT', 7000000, 7000000, 259000, 140000, '2026-01-01'),
    (1, 1, 'JP',  7000000, 7000000, 140000,  70000, '2026-01-01'),
    (1, 1, 'JKK', 7000000, 7000000,  16800,      0, '2026-01-01'),
    (1, 1, 'JKM', 7000000, 7000000,  21000,      0, '2026-01-01'),
    -- Employee 2
    (2, 2, 'JHT', 11250000, 11250000, 416250, 225000, '2026-01-01'),
    (2, 2, 'JP',  11250000, 10547400, 210948, 105474, '2026-01-01'),
    (2, 2, 'JKK', 11250000, 11250000,  27000,      0, '2026-01-01'),
    (2, 2, 'JKM', 11250000, 11250000,  33750,      0, '2026-01-01'),
    -- Employee 3
    (3, 3, 'JHT', 11250000, 11250000, 416250, 225000, '2026-01-01'),
    (3, 3, 'JP',  11250000, 10547400, 210948, 105474, '2026-01-01'),
    (3, 3, 'JKK', 11250000, 11250000,  27000,      0, '2026-01-01'),
    (3, 3, 'JKM', 11250000, 11250000,  33750,      0, '2026-01-01'),
    -- Employee 4
    (4, 4, 'JHT', 19000000, 19000000, 703000, 380000, '2026-01-01'),
    (4, 4, 'JP',  19000000, 10547400, 210948, 105474, '2026-01-01'),
    (4, 4, 'JKK', 19000000, 19000000,  45600,      0, '2026-01-01'),
    (4, 4, 'JKM', 19000000, 19000000,  57000,      0, '2026-01-01');

-- =============================================================================
-- 6. OFF-CYCLE PAYROLL RUN — one-off transport reimbursement payout
-- =============================================================================

INSERT INTO "PAYROLL".payroll_runs (id, run_type, payroll_group_id, payroll_period_id, status, reason_code, submitted_at, approved_at, paid_at) VALUES
    (2, 'off_cycle', 1, NULL, 'paid', 'urgent_reimbursement_payout', '2026-07-10 09:00:00+07', '2026-07-10 14:00:00+07', '2026-07-11 08:00:00+07');

INSERT INTO "PAYROLL".payroll_run_lines (id, payroll_run_id, employee_id, gross_earnings, total_deductions, net_pay, employer_cost, status) VALUES
    (5, 2, 3, 850000, 0, 850000, 850000, 'paid');

INSERT INTO "PAYROLL".payroll_run_line_components (payroll_run_line_id, payroll_component_id, amount) VALUES
    (5, 7, 850000);   -- Reimbursement (non-taxable)

-- =============================================================================
-- 7. THR PAYROLL RUN — Idul Fitri 2026, all active employees
-- =============================================================================

INSERT INTO "PAYROLL".payroll_runs (id, run_type, payroll_group_id, payroll_period_id, status, submitted_at, approved_at, paid_at) VALUES
    (3, 'thr', 1, NULL, 'paid', '2026-03-10 09:00:00+07', '2026-03-11 10:00:00+07', '2026-03-13 08:00:00+07');

INSERT INTO "PAYROLL".payroll_run_lines (id, payroll_run_id, employee_id, gross_earnings, total_deductions, net_pay, employer_cost, status) VALUES
    (6, 3, 1, 7000000,  105000, 6895000,  7000000, 'paid'),   -- <12 months? no, full 1x (joined 2022)
    (7, 3, 2, 11250000, 168750, 11081250, 11250000, 'paid'),
    (8, 3, 3, 11250000, 168750, 11081250, 11250000, 'paid'),
    (9, 3, 4, 19000000, 285000, 18715000, 19000000, 'paid');

INSERT INTO "PAYROLL".payroll_run_line_components (payroll_run_line_id, payroll_component_id, amount) VALUES
    (6, 8, 7000000),  (6, 9, -105000),
    (7, 8, 11250000), (7, 9, -168750),
    (8, 8, 11250000), (8, 9, -168750),
    (9, 8, 19000000), (9, 9, -285000);

INSERT INTO "PAYROLL".thr_calculations
    (payroll_run_line_id, employee_id, tenure_months_at_calc, is_prorated, monthly_salary_basis, thr_amount, religious_holiday_date)
VALUES
    (6, 1, 48, FALSE, 7000000,  7000000,  '2026-03-20'),
    (7, 2, 55, FALSE, 11250000, 11250000, '2026-03-20'),
    (8, 3, 38, FALSE, 11250000, 11250000, '2026-03-20'),
    (9, 4, 79, FALSE, 19000000, 19000000, '2026-03-20');

INSERT INTO "PAYROLL".pph21_calculations
    (payroll_run_line_id, employee_id, calculation_mode, ptkp_status_code, taxable_gross, tax_amount, rule_effective_date)
VALUES
    (6, 1, 'irregular_income', 'TK/0', 7000000,  105000, '2026-01-01'),
    (7, 2, 'irregular_income', 'K/1',  11250000, 168750, '2026-01-01'),
    (8, 3, 'irregular_income', 'K/0',  11250000, 168750, '2026-01-01'),
    (9, 4, 'irregular_income', 'K/3',  19000000, 285000, '2026-01-01');

-- =============================================================================
-- 8. FINAL PAYROLL RUN — Employee 5 (Ayu Lestari), redundancy, terminated 2026-06-30
-- =============================================================================

INSERT INTO "PAYROLL".payroll_runs (id, run_type, payroll_group_id, payroll_period_id, status, reason_code, submitted_at, approved_at, paid_at) VALUES
    (4, 'final', 1, NULL, 'paid', 'redundancy', '2026-06-25 09:00:00+07', '2026-06-27 10:00:00+07', '2026-06-30 08:00:00+07');

-- Tenure at termination: joined 2020-02-01, terminated 2026-06-30 -> ~6.4 years -> pesangon band 6-7 (7x), UPMK band 6-9 (3x)
INSERT INTO "PAYROLL".payroll_run_lines (id, payroll_run_id, employee_id, gross_earnings, total_deductions, net_pay, employer_cost, status) VALUES
    (10, 4, 5, 66500000, 3325000, 63175000, 66500000, 'paid');

INSERT INTO "PAYROLL".payroll_run_line_components (payroll_run_line_id, payroll_component_id, amount) VALUES
    (10, 9, -3325000);   -- final/separate-rate PPh21 on severance (illustrative)

INSERT INTO "PAYROLL".severance_calculations
    (payroll_run_line_id, employee_id, termination_reason, tenure_years, pesangon_amount, upmk_amount, uph_amount, total_severance, rule_effective_date)
VALUES
    (10, 5, 'redundancy', 6.41, 42000000, 18000000, 6500000, 66500000, '2026-01-01');
    -- pesangon: 7 x 6,000,000 basic-equivalent = 42,000,000
    -- upmk:     3 x 6,000,000 = 18,000,000
    -- uph:      15% x (42,000,000 + 18,000,000) = 9,000,000 -> illustrative figure adjusted to 6,500,000 in this sample

INSERT INTO "PAYROLL".pph21_calculations
    (payroll_run_line_id, employee_id, calculation_mode, ptkp_status_code, taxable_gross, tax_amount, rule_effective_date)
VALUES
    (10, 5, 'final_severance', 'TK/1', 66500000, 3325000, '2026-01-01');

-- =============================================================================
-- 9. PAYROLL INPUTS — overtime, variable earnings, commission, reimbursement
-- =============================================================================

INSERT INTO "PAYROLL".overtime_entries (employee_id, payroll_period_id, work_date, day_type, hours, computed_amount, status) VALUES
    (1, 2, '2026-08-03', 'weekday', 3.0, 242918, 'approved'),
    (3, 2, '2026-08-15', 'weekly_rest_day', 4.0, 519942, 'pending');

INSERT INTO "PAYROLL".variable_earning_entries (employee_id, payroll_period_id, payroll_component_id, amount, description, status) VALUES
    (2, 2, 5, 2000000, 'Q2 performance bonus (regular-cycle treatment)', 'approved');

INSERT INTO "PAYROLL".commission_entries (employee_id, payroll_period_id, amount, description, status) VALUES
    (2, 2, 1500000, 'August sales commission', 'approved');

INSERT INTO "PAYROLL".reimbursement_requests (id, employee_id, reimbursement_category_id, payroll_period_id, amount, description, expense_date, status) VALUES
    (1, 3, 1, NULL, 850000, 'Client visit transport — Jul 2026', '2026-07-08', 'paid');

-- =============================================================================
-- 10. LOANS & SALARY ADVANCES
-- =============================================================================

INSERT INTO "PAYROLL".employee_loans
    (id, employee_id, loan_type_id, principal_amount, term_months, monthly_installment, remaining_balance, start_period_id, status)
VALUES
    (1, 4, 1, 12000000, 12, 1000000, 9000000, 1, 'active');

INSERT INTO "PAYROLL".loan_installments (employee_loan_id, payroll_run_line_id, installment_number, due_amount, deducted_amount, status) VALUES
    (1, 4, 1, 1000000, 1000000, 'deducted'),
    (1, NULL, 2, 1000000, 0, 'due'),
    (1, NULL, 3, 1000000, 0, 'due');

INSERT INTO "PAYROLL".salary_advances
    (id, employee_id, amount, requested_at, repayment_installments, remaining_balance, payroll_run_line_id, status)
VALUES
    (1, 1, 1500000, '2026-07-05', 3, 1000000, 1, 'active');

-- =============================================================================
-- 11. PAYMENT BATCH — period 1 disbursement
-- =============================================================================

INSERT INTO "PAYROLL".payment_batches
    (id, payroll_group_id, payroll_period_id, bank_id, batch_date, total_amount, status, exported_file_reference)
VALUES
    (1, 1, 1, 1, '2026-07-31', 43483000, 'reconciled', 'dms://payroll/payment-batches/1/v1.csv');

INSERT INTO "PAYROLL".payment_batch_lines
    (id, payment_batch_id, payroll_run_line_id, employee_bank_account_id, amount, status)
VALUES
    (1, 1, 1, 1, 6505000,  'paid'),
    (2, 1, 2, 2, 10127000, 'paid'),
    (3, 1, 3, 3, 10282000, 'paid'),
    (4, 1, 4, 4, 16569000, 'paid');

INSERT INTO "PAYROLL".payment_reconciliations (payment_batch_line_id, reconciled_status, failure_reason, reconciled_by) VALUES
    (1, 'paid', NULL, 'Payroll Admin'),
    (2, 'paid', NULL, 'Payroll Admin'),
    (3, 'paid', NULL, 'Payroll Admin'),
    (4, 'paid', NULL, 'Payroll Admin');

-- =============================================================================
-- 12. AUDIT TRAIL SAMPLE
-- =============================================================================

INSERT INTO "PAYROLL".payroll_access_logs (action, actor_id, actor_name, subject_type, subject_id, occurred_at) VALUES
    ('run_created',            100, 'Payroll Admin', 'payroll.run', 1, '2026-07-26 08:55:00+07'),
    ('run_submitted',          100, 'Payroll Admin', 'payroll.run', 1, '2026-07-26 09:00:00+07'),
    ('run_approved',           101, 'Finance Approver', 'payroll.run', 1, '2026-07-27 10:00:00+07'),
    ('run_paid',               100, 'Payroll Admin', 'payroll.run', 1, '2026-07-31 08:00:00+07'),
    ('run_locked',             100, 'Payroll Admin', 'payroll.run', 1, '2026-07-31 08:05:00+07'),
    ('payment_batch_exported', 100, 'Payroll Admin', 'payroll.payment_batch', 1, '2026-07-31 07:50:00+07'),
    ('view_payslip',           1,   'Siti Rahayu',   'payroll.run_line', 1, '2026-08-01 09:12:00+07');

COMMIT;
