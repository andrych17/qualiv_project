-- ============================================================================
-- HCM Module — Sample / Seed Data
-- Demo tenant: a small Legal-vertical firm ("tenant_001"), current date
-- assumed 2026-07-22 for status/relative fields (contract-expiry, dashboard
-- demo rows, etc.). Explicit IDs used throughout for cross-reference
-- predictability, matching the convention used in WNE/DMS/CRM/Schedule seeds.
--
-- IMPORTANT — statutory rate figures (ter_rates, bpjs_rates,
-- regional_minimum_wages) below are SIMPLIFIED / ILLUSTRATIVE sample values
-- for development and demo purposes only. They approximate the shape of the
-- real PMK 168/2023 TER tables and current BPJS regulations but are NOT the
-- authoritative government figures. Load the official PMK/Permenaker tables
-- into these same versioned tables before any production payroll run.
--
-- Run with:
--   psql "<tenant_db_conn>" -v ON_ERROR_STOP=1 -f HCM_seed_data.sql
-- (Run HCM_schema.sql first.)
-- ============================================================================

BEGIN;
SET search_path TO "HCM";

-- ----------------------------------------------------------------------------
-- 1. ORG STRUCTURE
-- ----------------------------------------------------------------------------

INSERT INTO "HCM".org_units (id, parent_org_unit_id, name, code) VALUES
 (1, NULL, 'Executive Office',              'EXEC'),
 (2, 1,    'Legal Practice Group',          'LEGAL'),
 (3, 1,    'Finance & Administration',      'FIN'),
 (4, 1,    'Human Resources',               'HR');

INSERT INTO "HCM".jobs (id, title, code, description) VALUES
 (1, 'Managing Partner',        'MNG-PTR', 'Firm leadership and final approval authority.'),
 (2, 'Senior Associate',        'SR-ASSOC', 'Leads case teams, client-facing counsel.'),
 (3, 'Associate',               'ASSOC',    'Case work under senior supervision.'),
 (4, 'Paralegal',               'PARALEGAL','Case support, filings, research.'),
 (5, 'Finance & Admin Staff',   'FIN-STAFF','Bookkeeping, invoicing, office admin.'),
 (6, 'HR Manager',              'HR-MGR',   'Workforce administration and compliance.'),
 (7, 'Office Manager',          'OFF-MGR',  'Facilities, vendors, general office operations.');

INSERT INTO "HCM".positions (id, job_id, org_unit_id, reports_to_position_id, headcount_cap) VALUES
 (1, 1, 1, NULL, 1),
 (2, 2, 2, 1,    2),
 (3, 3, 2, 2,    5),
 (4, 4, 2, 2,    4),
 (5, 5, 3, 1,    2),
 (6, 6, 4, 1,    1),
 (7, 7, 3, 1,    1);

-- ----------------------------------------------------------------------------
-- 2. STATUTORY RATE TABLES (Indonesian compliance — illustrative, see header)
-- ----------------------------------------------------------------------------

INSERT INTO "HCM".ptkp_statuses (id, code, description, annual_non_taxable_amount, ter_category) VALUES
 (1, 'TK/0', 'Single, no dependents',            54000000, 'A'),
 (2, 'TK/1', 'Single, 1 dependent',               58500000, 'A'),
 (3, 'TK/2', 'Single, 2 dependents',              63000000, 'B'),
 (4, 'TK/3', 'Single, 3 dependents',              67500000, 'B'),
 (5, 'K/0',  'Married, no dependents',            58500000, 'A'),
 (6, 'K/1',  'Married, 1 dependent',              63000000, 'B'),
 (7, 'K/2',  'Married, 2 dependents',             67500000, 'B'),
 (8, 'K/3',  'Married, 3 dependents',             72000000, 'C');

-- Simplified illustrative TER brackets per category (monthly gross income, IDR).
INSERT INTO "HCM".ter_rates (ter_category, income_from, income_to, rate_percent, effective_from) VALUES
 ('A',          0,   5400000, 0.00, '2024-01-01'),
 ('A',    5400000,   8000000, 2.00, '2024-01-01'),
 ('A',    8000000,  12000000, 5.00, '2024-01-01'),
 ('A',   12000000,  20000000, 8.00, '2024-01-01'),
 ('A',   20000000,  30000000, 12.00,'2024-01-01'),
 ('A',   30000000,       NULL,17.00,'2024-01-01'),
 ('B',          0,   6200000, 0.00, '2024-01-01'),
 ('B',    6200000,   9000000, 2.00, '2024-01-01'),
 ('B',    9000000,  13000000, 5.00, '2024-01-01'),
 ('B',   13000000,  21000000, 7.00, '2024-01-01'),
 ('B',   21000000,  30000000, 11.00,'2024-01-01'),
 ('B',   30000000,       NULL,16.00,'2024-01-01'),
 ('C',          0,   6600000, 0.00, '2024-01-01'),
 ('C',    6600000,   9500000, 1.50, '2024-01-01'),
 ('C',    9500000,  14000000, 4.00, '2024-01-01'),
 ('C',   14000000,  22000000, 6.00, '2024-01-01'),
 ('C',   22000000,  32000000, 10.00,'2024-01-01'),
 ('C',   32000000,       NULL,15.00,'2024-01-01');

INSERT INTO "HCM".bpjs_rates (contribution_type, risk_class, employer_percent, employee_percent, wage_floor, wage_ceiling, effective_from) VALUES
 ('kesehatan', NULL,          4.000, 1.000, NULL, 12000000, '2024-01-01'),
 ('jkk',       'risk_class_1',0.240, 0.000, NULL, NULL,     '2024-01-01'),
 ('jkm',       NULL,          0.300, 0.000, NULL, NULL,     '2024-01-01'),
 ('jht',       NULL,          3.700, 2.000, NULL, NULL,     '2024-01-01'),
 ('jp',        NULL,          2.000, 1.000, NULL, 10000000, '2024-01-01');

INSERT INTO "HCM".regional_minimum_wages (province, city, year, amount) VALUES
 ('DKI Jakarta',  NULL,       2026, 5500000),
 ('Jawa Timur',   'Surabaya', 2026, 4800000),
 ('Jawa Barat',   'Bandung',  2026, 4300000);

-- ----------------------------------------------------------------------------
-- 3. EMPLOYEES, POSITION HISTORY, CONTRACTS
-- ----------------------------------------------------------------------------

INSERT INTO "HCM".employees
 (id, employee_number, full_name, date_of_birth, gender, nik, npwp, bpjs_kesehatan_no,
  bpjs_ketenagakerjaan_no, address, marital_status, dependents_count, ptkp_status_id,
  religion, bank_name, bank_account_no, bank_account_holder, position_id,
  employment_status, hire_date)
VALUES
 (1,  'EMP-0001', 'Budi Santoso',    '1975-04-12','M','3171012345670001','09.111.222.3-071.000','0001112223','JHT0001112223','Jl. Sudirman No. 1, Jakarta','married',2,7,'Islam','Bank Mandiri','1234567890','Budi Santoso',1,'active','2019-01-15'),
 (2,  'EMP-0002', 'Siti Rahayu',     '1982-08-03','F','3171012345670002','09.222.333.4-071.000','0002223334','JHT0002223334','Jl. Thamrin No. 5, Jakarta','married',1,6,'Islam','Bank BCA','2234567891','Siti Rahayu',2,'active','2020-03-01'),
 (3,  'EMP-0003', 'Andi Wijaya',     '1993-11-20','M','3171012345670003','09.333.444.5-071.000','0003334445','JHT0003334445','Jl. Gatot Subroto No. 8, Jakarta','single',0,1,'Kristen','Bank BCA','3234567892','Andi Wijaya',3,'active','2022-06-01'),
 (4,  'EMP-0004', 'Dewi Lestari',    '1996-02-17','F','3171012345670004','09.444.555.6-071.000','0004445556','JHT0004445556','Jl. Rasuna Said No. 12, Jakarta','single',0,1,'Islam','Bank Mandiri','4234567893','Dewi Lestari',3,'active','2025-01-10'),
 (5,  'EMP-0005', 'Rina Kusuma',     '1990-06-25','F','3171012345670005','09.555.666.7-071.000','0005556667','JHT0005556667','Jl. Kuningan No. 3, Jakarta','divorced',1,2,'Islam','Bank BNI','5234567894','Rina Kusuma',4,'active','2021-09-01'),
 (6,  'EMP-0006', 'Agus Setiawan',   '1997-09-09','M','3171012345670006','09.666.777.8-071.000','0006667778','JHT0006667778','Jl. Casablanca No. 7, Jakarta','single',0,1,'Islam','Bank BRI','6234567895','Agus Setiawan',4,'active','2025-02-01'),
 (7,  'EMP-0007', 'Maya Puspita',    '1988-01-30','F','3171012345670007','09.777.888.9-071.000','0007778889','JHT0007778889','Jl. Senopati No. 9, Jakarta','married',0,5,'Katolik','Bank Mandiri','7234567896','Maya Puspita',5,'active','2020-11-01'),
 (8,  'EMP-0008', 'Hendra Gunawan',  '1980-12-05','M','3171012345670008','09.888.999.0-071.000','0008889990','JHT0008889990','Jl. Wahid Hasyim No. 15, Jakarta','married',3,8,'Islam','Bank Mandiri','8234567897','Hendra Gunawan',6,'active','2019-05-01'),
 (9,  'EMP-0009', 'Lia Marlina',     '1994-07-14','F','3171012345670009','09.999.000.1-071.000','0009990001','JHT0009990001','Jl. Kebon Sirih No. 4, Jakarta','single',0,1,'Islam','Bank BCA','9234567898','Lia Marlina',7,'active','2022-02-15'),
 (10, 'EMP-0010', 'Fajar Nugroho',   '1998-03-22','M','3171012345670010','09.000.111.2-071.000','0000001112','JHT0000001112','Jl. HR Rasuna Said No. 20, Jakarta','single',0,1,'Kristen','Bank BNI','1034567899','Fajar Nugroho',3,'active','2026-06-01'),
 (11, 'EMP-0011', 'Yusuf Pratama',   '1999-05-18','M','3171012345670011','09.111.222.3-071.001','0001112224','JHT0001112224','Jl. Tebet Raya No. 6, Jakarta','single',0,1,'Islam','Bank BRI','1134567800','Yusuf Pratama',4,'active','2025-08-01');

INSERT INTO "HCM".employee_position_history (id, employee_id, position_id, effective_from, effective_to, changed_by, note) VALUES
 (1,  1, 1, '2019-01-15', NULL,        NULL, 'Initial hire'),
 (2,  2, 2, '2020-03-01', NULL,        NULL, 'Initial hire'),
 (3,  3, 4, '2022-06-01', '2023-12-31', NULL, 'Initial hire as Paralegal'),
 (4,  3, 3, '2024-01-01', NULL,        1,    'Promoted to Associate'),
 (5,  4, 3, '2025-01-10', NULL,        NULL, 'Initial hire'),
 (6,  5, 4, '2021-09-01', NULL,        NULL, 'Initial hire'),
 (7,  6, 4, '2025-02-01', NULL,        NULL, 'Initial hire'),
 (8,  7, 5, '2020-11-01', NULL,        NULL, 'Initial hire'),
 (9,  8, 6, '2019-05-01', NULL,        NULL, 'Initial hire'),
 (10, 9, 7, '2022-02-15', NULL,        NULL, 'Initial hire'),
 (11, 10,3, '2026-06-01', NULL,        NULL, 'Initial hire'),
 (12, 11,4, '2025-08-01', NULL,        NULL, 'Initial hire');

-- contract_type, dates, base_salary, probation. Agus's original PKWT (id 6) is
-- superseded by a renewal (id 12) — demonstrates the PKWT renewal chain (3D).
INSERT INTO "HCM".employment_contracts
 (id, employee_id, contract_type, start_date, end_date, base_salary, work_location,
  probation_end_date, status, renewed_from_contract_id, document_reference)
VALUES
 (1,  1, 'PKWTT', '2019-01-15', NULL,        45000000, 'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0001'),
 (2,  2, 'PKWTT', '2020-03-01', NULL,        25000000, 'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0002'),
 (3,  3, 'PKWTT', '2024-01-01', NULL,        15000000, 'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0003'),
 (4,  4, 'PKWT',  '2025-01-10', '2027-01-09',14000000, 'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0004'),
 (5,  5, 'PKWTT', '2021-09-01', NULL,        9000000,  'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0005'),
 (6,  6, 'PKWT',  '2025-02-01', '2026-01-31',8500000,  'Jakarta Office', NULL,        'renewed',NULL, 'dms-doc-uuid-0006'),
 (7,  7, 'PKWTT', '2020-11-01', NULL,        10000000, 'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0007'),
 (8,  8, 'PKWTT', '2019-05-01', NULL,        18000000, 'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0008'),
 (9,  9, 'PKWTT', '2022-02-15', NULL,        12000000, 'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0009'),
 (10, 10,'PKWTT', '2026-06-01', NULL,        14500000, 'Jakarta Office', '2026-09-01','active', NULL, 'dms-doc-uuid-0010'),
 (11, 11,'PKWT',  '2025-08-01', '2026-08-01',8500000,  'Jakarta Office', NULL,        'active', NULL, 'dms-doc-uuid-0011'),
 (12, 6, 'PKWT',  '2026-02-01', '2027-01-31',8500000,  'Jakarta Office', NULL,        'active', 6,    'dms-doc-uuid-0012');

-- ----------------------------------------------------------------------------
-- 4. TIME & ATTENDANCE
-- ----------------------------------------------------------------------------

INSERT INTO "HCM".shifts (id, name, start_time, end_time, break_minutes) VALUES
 (1, 'Regular Office Hours', '08:00', '17:00', 60),
 (2, 'Half Day Morning',     '08:00', '12:00', 0);

INSERT INTO "HCM".shift_assignments (id, employee_id, shift_id, work_date) VALUES
 (1, 3,  1, '2026-07-20'), (2, 3,  1, '2026-07-21'), (3, 3,  1, '2026-07-22'),
 (4, 4,  1, '2026-07-20'), (5, 4,  1, '2026-07-21'), (6, 4,  1, '2026-07-22'),
 (7, 5,  1, '2026-07-20'), (8, 5,  1, '2026-07-21'), (9, 5,  1, '2026-07-22'),
 (10,11, 1, '2026-07-20'), (11,11, 1, '2026-07-21'), (12,11, 1, '2026-07-22');

INSERT INTO "HCM".attendance_logs (id, employee_id, shift_assignment_id, clock_in_at, clock_out_at, source, status) VALUES
 (1, 3, 1,  '2026-07-20 07:55:00+07', '2026-07-20 17:05:00+07', 'web', 'on_time'),
 (2, 3, 2,  '2026-07-21 08:20:00+07', '2026-07-21 17:00:00+07', 'web', 'late'),
 (3, 3, 3,  '2026-07-22 07:58:00+07', NULL,                     'web', NULL),
 (4, 4, 4,  '2026-07-20 07:50:00+07', '2026-07-20 17:02:00+07', 'web', 'on_time'),
 (5, 4, 5,  '2026-07-21 07:57:00+07', '2026-07-21 17:00:00+07', 'web', 'on_time'),
 (6, 4, 6,  '2026-07-22 07:59:00+07', NULL,                     'web', NULL),
 (7, 5, 7,  '2026-07-20 07:50:00+07', '2026-07-20 18:10:00+07', 'web', 'on_time'),
 (8, 5, 8,  '2026-07-21 07:55:00+07', NULL,                     'web', 'absent'),
 (9, 5, 9,  '2026-07-22 07:58:00+07', NULL,                     'web', NULL),
 (10,11,10, '2026-07-20 07:59:00+07', '2026-07-20 17:45:00+07', 'web', 'on_time'),
 (11,11,11, '2026-07-21 08:05:00+07', '2026-07-21 17:00:00+07', 'web', 'on_time'),
 (12,11,12, '2026-07-22 07:57:00+07', NULL,                     'web', NULL);

INSERT INTO "HCM".attendance_corrections (id, attendance_log_id, employee_id, requested_clock_in_at, requested_clock_out_at, reason, status) VALUES
 (1, 8, 5, '2026-07-21 07:55:00+07', '2026-07-21 17:15:00+07', 'Forgot to clock out — was on-site for a client filing until 17:15.', 'pending');

-- ----------------------------------------------------------------------------
-- 5. LEAVE MANAGEMENT (statutory + custom leave types pre-seeded)
-- ----------------------------------------------------------------------------

INSERT INTO "HCM".leave_types (id, code, name, is_paid, requires_attachment) VALUES
 (1, 'cuti_tahunan',       'Annual Leave',              TRUE,  FALSE),
 (2, 'cuti_sakit',         'Sick Leave',                TRUE,  TRUE),
 (3, 'cuti_melahirkan',    'Maternity Leave',           TRUE,  TRUE),
 (4, 'cuti_ayah',          'Paternity Leave',           TRUE,  FALSE),
 (5, 'cuti_menikah',       'Marriage Leave',            TRUE,  FALSE),
 (6, 'cuti_duka',          'Bereavement Leave',         TRUE,  FALSE),
 (7, 'cuti_haji',          'Hajj / Pilgrimage Leave',   FALSE, TRUE),
 (8, 'cuti_tidak_dibayar', 'Unpaid Leave',              FALSE, FALSE);

-- entitlement_days_per_year for Sick Leave is an internal tracking cap only —
-- Indonesian law does not statutorily cap medically-certified sick leave.
INSERT INTO "HCM".leave_policies
 (id, leave_type_id, employment_status_scope, entitlement_days_per_year, accrual_method,
  carry_over_max_days, carry_over_expiry_months, is_paid)
VALUES
 (1, 1, 'all',   12,  'annual_grant', 6,   3,    TRUE),
 (2, 2, 'all',   30,  'annual_grant', NULL,NULL, TRUE),
 (3, 3, 'all',   90,  'annual_grant', NULL,NULL, TRUE),
 (4, 4, 'all',   2,   'annual_grant', NULL,NULL, TRUE),
 (5, 5, 'all',   3,   'annual_grant', NULL,NULL, TRUE),
 (6, 6, 'all',   2,   'annual_grant', NULL,NULL, TRUE),
 (7, 7, 'PKWTT', 90,  'annual_grant', NULL,NULL, FALSE),
 (8, 8, 'all',   0,   'annual_grant', NULL,NULL, FALSE);

INSERT INTO "HCM".leave_balances (employee_id, leave_type_id, period_year, entitled_days, used_days, carried_over_days) VALUES
 (1, 1, 2026, 12, 3, 2), (1, 2, 2026, 30, 0, 0),
 (2, 1, 2026, 12, 5, 1), (2, 2, 2026, 30, 2, 0),
 (3, 1, 2026, 12, 3, 0), (3, 2, 2026, 30, 0, 0),
 (4, 1, 2026, 12, 0, 0), (4, 2, 2026, 30, 0, 0),
 (5, 1, 2026, 12, 4, 0), (5, 2, 2026, 30, 2, 0),
 (6, 1, 2026, 12, 1, 0), (6, 2, 2026, 30, 0, 0),
 (7, 1, 2026, 12, 6, 3), (7, 2, 2026, 30, 1, 0),
 (8, 1, 2026, 12, 2, 0), (8, 2, 2026, 30, 0, 0),
 (9, 1, 2026, 12, 0, 0), (9, 2, 2026, 30, 0, 0),
 (10,1, 2026, 12, 0, 0), (10,2, 2026, 30, 0, 0),
 (11,1, 2026, 12, 1, 0), (11,2, 2026, 30, 0, 0);

INSERT INTO "HCM".leave_requests
 (id, employee_id, leave_type_id, start_date, end_date, days_count, reason, status,
  attachment_document_ref, approved_by, approved_at)
VALUES
 (1, 3, 1, '2026-08-03', '2026-08-05', 3, 'Family trip',                 'approved', NULL,               2, '2026-07-18 10:00:00+07'),
 (2, 5, 2, '2026-07-15', '2026-07-16', 2, 'Flu, resting on doctor advice','approved', 'dms-doc-uuid-0101', 4, '2026-07-14 09:00:00+07'),
 (3, 9, 1, '2026-08-10', '2026-08-14', 5, 'Annual family visit',          'pending',  NULL,               NULL, NULL),
 (4, 6, 5, '2026-09-01', '2026-09-03', 3, 'Getting married',              'pending',  NULL,               NULL, NULL);

-- ----------------------------------------------------------------------------
-- 6. PAYROLL — June 2026 (closed) and July 2026 (in progress)
--    Figures computed consistently from base_salary + allowance + statutory
--    rates above (TER method for PPh 21, BPJS Kesehatan/JKK/JKM/JHT/JP).
-- ----------------------------------------------------------------------------

INSERT INTO "HCM".payroll_periods (id, period_month, period_year, status, calculated_at, approved_at, paid_at, closed_at) VALUES
 (1, 6, 2026, 'closed', '2026-06-28 10:00:00+07', '2026-06-29 09:00:00+07', '2026-06-30 08:00:00+07', '2026-07-02 08:00:00+07'),
 (2, 7, 2026, 'draft',  NULL, NULL, NULL, NULL);

INSERT INTO "HCM".payroll_run_items
 (payroll_period_id, employee_id, base_salary, overtime_hours, overtime_amount, allowances,
  gross_pay, bpjs_kesehatan_employee, bpjs_kesehatan_employer, bpjs_jkk_employer,
  bpjs_jkm_employer, bpjs_jht_employee, bpjs_jht_employer, bpjs_jp_employee, bpjs_jp_employer,
  pph21_amount, other_deductions, net_pay, payslip_document_ref)
VALUES
 (1, 1,  45000000, 0, 0,      500000, 45500000, 120000, 480000, 109200, 136500, 910000, 1683500, 100000, 200000, 7280000, 0, 37090000, 'dms-doc-uuid-0201'),
 (1, 2,  25000000, 0, 0,      500000, 25500000, 120000, 480000, 61200,  76500,  510000, 943500,  100000, 200000, 2805000, 0, 21965000, 'dms-doc-uuid-0202'),
 (1, 3,  15000000, 0, 0,      500000, 15500000, 120000, 480000, 37200,  46500,  310000, 573500,  100000, 200000, 1240000, 0, 13730000, 'dms-doc-uuid-0203'),
 (1, 4,  14000000, 0, 0,      500000, 14500000, 120000, 480000, 34800,  43500,  290000, 536500,  100000, 200000, 1160000, 0, 12830000, 'dms-doc-uuid-0204'),
 (1, 5,  9000000,  8, 806358, 500000, 10306358, 103064, 412254, 24735,  30919,  206127, 381335,  100000, 200000, 515318,  0, 9381849,  'dms-doc-uuid-0205'),
 (1, 6,  8500000,  0, 0,      500000, 9000000,  90000,  360000, 21600,  27000,  180000, 333000,  90000,  180000, 450000,  0, 8190000,  'dms-doc-uuid-0206'),
 (1, 7,  10000000, 0, 0,      500000, 10500000, 105000, 420000, 25200,  31500,  210000, 388500,  100000, 200000, 525000,  0, 9560000,  'dms-doc-uuid-0207'),
 (1, 8,  18000000, 0, 0,      500000, 18500000, 120000, 480000, 44400,  55500,  370000, 684500,  100000, 200000, 1110000, 0, 16800000, 'dms-doc-uuid-0208'),
 (1, 9,  12000000, 0, 0,      500000, 12500000, 120000, 480000, 30000,  37500,  250000, 462500,  100000, 200000, 1000000, 0, 11030000, 'dms-doc-uuid-0209'),
 (1, 10, 14500000, 0, 0,      500000, 15000000, 120000, 480000, 36000,  45000,  300000, 555000,  100000, 200000, 1200000, 0, 13280000, 'dms-doc-uuid-0210'),
 (1, 11, 8500000,  6, 565029, 500000, 9565029,  95650,  382601, 22956,  28695,  191301, 353906,  95650,  191301, 478251,  0, 8704177,  'dms-doc-uuid-0211');

-- THR — Idul Fitri 2026 (date used for the H-7 deadline is illustrative/approximate).
-- Fajar excluded (not yet hired at the time). tenure_months computed from hire_date
-- to the holiday date; under-12-month tenure is pro-rated per HCM_SPECS.md §3G.
INSERT INTO "HCM".thr_run_items (employee_id, religious_holiday_year, tenure_months, base_salary, thr_amount, payment_due_date, paid_at, payslip_document_ref) VALUES
 (1, 2026, 86, 45000000, 45000000, '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0301'),
 (2, 2026, 72, 25000000, 25000000, '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0302'),
 (3, 2026, 45, 15000000, 15000000, '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0303'),
 (4, 2026, 14, 14000000, 14000000, '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0304'),
 (5, 2026, 54, 9000000,  9000000,  '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0305'),
 (6, 2026, 13, 8500000,  8500000,  '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0306'),
 (7, 2026, 65, 10000000, 10000000, '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0307'),
 (8, 2026, 83, 18000000, 18000000, '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0308'),
 (9, 2026, 49, 12000000, 12000000, '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0309'),
 (11,2026, 8,  8500000,  5666667,  '2026-03-13', '2026-03-12 10:00:00+07', 'dms-doc-uuid-0311');

-- ----------------------------------------------------------------------------
-- 7. FUTURE-VERSION STUB TABLES — a few representative rows
-- ----------------------------------------------------------------------------

INSERT INTO "HCM".candidates (id, full_name, email, phone, source, status) VALUES
 (1, 'Ratna Sari', 'ratna.sari@example.com', '+62812345001', 'referral', 'interviewing'),
 (2, 'Denny Pranata', 'denny.pranata@example.com', '+62812345002', 'web', 'new');

INSERT INTO "HCM".job_requisitions (id, job_id, org_unit_id, status, opened_at) VALUES
 (1, 3, 2, 'open', '2026-07-01');

INSERT INTO "HCM".performance_cycles (id, name, start_date, end_date, status) VALUES
 (1, 'H1 2026 Review Cycle', '2026-01-01', '2026-06-30', 'closed'),
 (2, 'H2 2026 Review Cycle', '2026-07-01', '2026-12-31', 'draft');

INSERT INTO "HCM".goals (id, employee_id, performance_cycle_id, title, status) VALUES
 (1, 3, 1, 'Close 12 case files with zero compliance findings', 'completed');

INSERT INTO "HCM".reviews (id, employee_id, performance_cycle_id, reviewer_id, rating, comments) VALUES
 (1, 3, 1, 2, 4.20, 'Strong case turnaround, room to grow on client communication.');

INSERT INTO "HCM".courses (id, title, description) VALUES
 (1, 'Legal Research Fundamentals', 'Refresher on statutory research tools and citation practice.'),
 (2, 'Workplace Data Confidentiality', 'Annual confidentiality/compliance refresher, all staff.');

INSERT INTO "HCM".enrollments (id, employee_id, course_id, status, completed_at) VALUES
 (1, 4, 1, 'completed', '2026-05-10 00:00:00+07'),
 (2, 5, 2, 'enrolled', NULL);

INSERT INTO "HCM".certifications (id, employee_id, name, issued_at, expires_at, document_reference) VALUES
 (1, 2, 'Advocate License (PERADI)', '2015-06-01', NULL, 'dms-doc-uuid-0401');

INSERT INTO "HCM".salary_bands (id, job_id, band_name, min_salary, max_salary) VALUES
 (1, 3, 'Associate — Band 1', 13000000, 17000000),
 (2, 4, 'Paralegal — Band 1', 7500000,  10000000);

INSERT INTO "HCM".benefit_plans (id, name, description, provider) VALUES
 (1, 'Private Health Top-Up', 'Supplemental coverage above statutory BPJS Kesehatan.', 'Allianz'),
 (2, 'Life Insurance', 'Group term life coverage.', 'Prudential');

INSERT INTO "HCM".benefit_enrollments (id, employee_id, benefit_plan_id, enrolled_at) VALUES
 (1, 1, 1, '2024-01-01'),
 (2, 1, 2, '2024-01-01'),
 (3, 2, 1, '2024-01-01');

-- ----------------------------------------------------------------------------
-- 8. RESET SEQUENCES (explicit IDs were used above)
-- ----------------------------------------------------------------------------

SELECT setval(pg_get_serial_sequence('"HCM".org_units','id'), (SELECT MAX(id) FROM "HCM".org_units));
SELECT setval(pg_get_serial_sequence('"HCM".jobs','id'), (SELECT MAX(id) FROM "HCM".jobs));
SELECT setval(pg_get_serial_sequence('"HCM".positions','id'), (SELECT MAX(id) FROM "HCM".positions));
SELECT setval(pg_get_serial_sequence('"HCM".ptkp_statuses','id'), (SELECT MAX(id) FROM "HCM".ptkp_statuses));
SELECT setval(pg_get_serial_sequence('"HCM".ter_rates','id'), (SELECT MAX(id) FROM "HCM".ter_rates));
SELECT setval(pg_get_serial_sequence('"HCM".bpjs_rates','id'), (SELECT MAX(id) FROM "HCM".bpjs_rates));
SELECT setval(pg_get_serial_sequence('"HCM".regional_minimum_wages','id'), (SELECT MAX(id) FROM "HCM".regional_minimum_wages));
SELECT setval(pg_get_serial_sequence('"HCM".employees','id'), (SELECT MAX(id) FROM "HCM".employees));
SELECT setval(pg_get_serial_sequence('"HCM".employee_position_history','id'), (SELECT MAX(id) FROM "HCM".employee_position_history));
SELECT setval(pg_get_serial_sequence('"HCM".employment_contracts','id'), (SELECT MAX(id) FROM "HCM".employment_contracts));
SELECT setval(pg_get_serial_sequence('"HCM".shifts','id'), (SELECT MAX(id) FROM "HCM".shifts));
SELECT setval(pg_get_serial_sequence('"HCM".shift_assignments','id'), (SELECT MAX(id) FROM "HCM".shift_assignments));
SELECT setval(pg_get_serial_sequence('"HCM".attendance_logs','id'), (SELECT MAX(id) FROM "HCM".attendance_logs));
SELECT setval(pg_get_serial_sequence('"HCM".attendance_corrections','id'), (SELECT MAX(id) FROM "HCM".attendance_corrections));
SELECT setval(pg_get_serial_sequence('"HCM".leave_types','id'), (SELECT MAX(id) FROM "HCM".leave_types));
SELECT setval(pg_get_serial_sequence('"HCM".leave_policies','id'), (SELECT MAX(id) FROM "HCM".leave_policies));
SELECT setval(pg_get_serial_sequence('"HCM".leave_balances','id'), (SELECT MAX(id) FROM "HCM".leave_balances));
SELECT setval(pg_get_serial_sequence('"HCM".leave_requests','id'), (SELECT MAX(id) FROM "HCM".leave_requests));
SELECT setval(pg_get_serial_sequence('"HCM".payroll_periods','id'), (SELECT MAX(id) FROM "HCM".payroll_periods));
SELECT setval(pg_get_serial_sequence('"HCM".payroll_run_items','id'), (SELECT MAX(id) FROM "HCM".payroll_run_items));
SELECT setval(pg_get_serial_sequence('"HCM".thr_run_items','id'), (SELECT MAX(id) FROM "HCM".thr_run_items));
SELECT setval(pg_get_serial_sequence('"HCM".candidates','id'), (SELECT MAX(id) FROM "HCM".candidates));
SELECT setval(pg_get_serial_sequence('"HCM".job_requisitions','id'), (SELECT MAX(id) FROM "HCM".job_requisitions));
SELECT setval(pg_get_serial_sequence('"HCM".performance_cycles','id'), (SELECT MAX(id) FROM "HCM".performance_cycles));
SELECT setval(pg_get_serial_sequence('"HCM".goals','id'), (SELECT MAX(id) FROM "HCM".goals));
SELECT setval(pg_get_serial_sequence('"HCM".reviews','id'), (SELECT MAX(id) FROM "HCM".reviews));
SELECT setval(pg_get_serial_sequence('"HCM".courses','id'), (SELECT MAX(id) FROM "HCM".courses));
SELECT setval(pg_get_serial_sequence('"HCM".enrollments','id'), (SELECT MAX(id) FROM "HCM".enrollments));
SELECT setval(pg_get_serial_sequence('"HCM".certifications','id'), (SELECT MAX(id) FROM "HCM".certifications));
SELECT setval(pg_get_serial_sequence('"HCM".salary_bands','id'), (SELECT MAX(id) FROM "HCM".salary_bands));
SELECT setval(pg_get_serial_sequence('"HCM".benefit_plans','id'), (SELECT MAX(id) FROM "HCM".benefit_plans));
SELECT setval(pg_get_serial_sequence('"HCM".benefit_enrollments','id'), (SELECT MAX(id) FROM "HCM".benefit_enrollments));

COMMIT;
