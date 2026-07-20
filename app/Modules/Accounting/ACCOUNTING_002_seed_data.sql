-- ============================================================================
-- ACCOUNTING Module — Sample / Dummy Seed Data
-- Scenario: "Kusuma & Rekan" law firm tenant, two companies:
--   Company 1 (id=1): PT Kusuma Hukum Indonesia — the operating firm (Legal vertical)
--   Company 2 (id=2): Kusuma Client Trust — a separate client-trust entity
-- Cross-references CRM.partners (explicit IDs, matching CRM_SPECS.md convention:
--   partner_id 501 = PT Sinergi Maju Bersama (a Legal-vertical client, org)
--   partner_id 502 = Budi Santoso (an individual client / referral contact)
--   partner_id 601 = CV Alat Kantor Sejahtera (an office-supplies vendor)
--   partner_id 602 = Notaris Dewi & Rekan (a professional-services vendor, PPh23 subject)
-- Explicit primary key IDs are used throughout for referential-integrity
-- predictability and cross-module composition, matching the convention already
-- used in WNE/DMS/CRM/Schedule seed data.
-- ============================================================================

BEGIN;
SET search_path TO "ACCOUNTING", public;

-- ----------------------------------------------------------------------------
-- 0. Stub CRM partners this seed depends on (NOT part of the ACCOUNTING
--    deliverable — Simon's real CRM.partners rows already exist in his tenant
--    DB; included here only so this script is runnable standalone for review/
--    testing). Remove this block when loading against a real tenant DB that
--    already has CRM seeded.
-- ----------------------------------------------------------------------------
INSERT INTO "CRM".partners (id, uuid, type, name, is_active) VALUES
    (501, gen_random_uuid(), 'organization', 'PT Sinergi Maju Bersama', true),
    (502, gen_random_uuid(), 'individual',   'Budi Santoso', true),
    (601, gen_random_uuid(), 'organization', 'CV Alat Kantor Sejahtera', true),
    (602, gen_random_uuid(), 'organization', 'Notaris Dewi & Rekan', true)
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('"CRM".partners','id'), 1000, false);

-- ----------------------------------------------------------------------------
-- 1. COMPANIES
-- ----------------------------------------------------------------------------
INSERT INTO companies (id, uuid, legal_name, trade_name, npwp, nitku, address,
                        base_currency_code, fiscal_year_start_month, is_active) VALUES
    (1, gen_random_uuid(), 'PT Kusuma Hukum Indonesia', 'Kusuma & Rekan',
        '01.234.567.8-901.000', '0123456789012000', 'Jl. Sudirman Kav. 45, Jakarta Selatan',
        'IDR', 1, true),
    (2, gen_random_uuid(), 'Kusuma Client Trust Account', 'Kusuma Trust',
        '01.234.567.8-901.001', '0123456789012001', 'Jl. Sudirman Kav. 45, Jakarta Selatan',
        'IDR', 1, true);

-- ----------------------------------------------------------------------------
-- 2. CURRENCIES & EXCHANGE RATES
-- ----------------------------------------------------------------------------
INSERT INTO currencies (code, name, symbol, is_active) VALUES
    ('IDR', 'Indonesian Rupiah', 'Rp', true),
    ('USD', 'US Dollar', '$', true);

INSERT INTO exchange_rates (currency_code, rate_date, rate_to_base, source) VALUES
    ('USD', '2026-07-01', 16250.000000, 'manual'),
    ('USD', '2026-07-31', 16310.000000, 'manual'),
    ('IDR', '2026-07-01', 1.000000, 'manual');

-- ----------------------------------------------------------------------------
-- 3. CHART OF ACCOUNTS (Company 1 — abbreviated Indonesian-standard COA)
-- ----------------------------------------------------------------------------
INSERT INTO accounts (id, company_id, account_code, account_name, account_type,
                       normal_balance, parent_account_id, is_control_account,
                       control_account_for, is_active) VALUES
    -- Aset
    (1001, 1, '1000', 'ASET', 'asset', 'debit', NULL, false, NULL, true),
    (1010, 1, '1100', 'Kas & Bank', 'asset', 'debit', 1001, false, NULL, true),
    (1011, 1, '1101', 'Kas Kecil', 'asset', 'debit', 1010, false, 'bank_cash', true),
    (1012, 1, '1102', 'Bank BCA - Operasional', 'asset', 'debit', 1010, false, 'bank_cash', true),
    (1020, 1, '1200', 'Piutang Usaha', 'asset', 'debit', 1001, true, 'ar', true),
    (1021, 1, '1210', 'Piutang PPN Masukan', 'asset', 'debit', 1001, false, NULL, true),
    (1030, 1, '1300', 'Persediaan', 'asset', 'debit', 1001, true, 'inventory', true),
    (1040, 1, '1500', 'Aset Tetap', 'asset', 'debit', 1001, false, NULL, true),
    (1041, 1, '1510', 'Peralatan Kantor', 'asset', 'debit', 1040, false, NULL, true),
    (1042, 1, '1519', 'Akumulasi Penyusutan - Peralatan Kantor', 'asset', 'credit', 1040, false, NULL, true),
    -- Liabilitas
    (2001, 1, '2000', 'LIABILITAS', 'liability', 'credit', NULL, false, NULL, true),
    (2010, 1, '2100', 'Utang Usaha', 'liability', 'credit', 2001, true, 'ap', true),
    (2020, 1, '2110', 'Utang PPN Keluaran', 'liability', 'credit', 2001, false, NULL, true),
    (2030, 1, '2200', 'Utang PPh 23 Dipotong', 'liability', 'credit', 2001, false, NULL, true),
    (2031, 1, '2201', 'Utang PPh 4(2) Dipotong', 'liability', 'credit', 2001, false, NULL, true),
    -- Ekuitas
    (3001, 1, '3000', 'EKUITAS', 'equity', 'credit', NULL, false, NULL, true),
    (3010, 1, '3100', 'Modal Disetor', 'equity', 'credit', 3001, false, NULL, true),
    (3020, 1, '3900', 'Laba Ditahan', 'equity', 'credit', 3001, false, NULL, true),
    -- Pendapatan
    (4001, 1, '4000', 'PENDAPATAN', 'revenue', 'credit', NULL, false, NULL, true),
    (4010, 1, '4100', 'Pendapatan Jasa Hukum', 'revenue', 'credit', 4001, false, NULL, true),
    -- HPP
    (5001, 1, '5000', 'HARGA POKOK PENJUALAN', 'cogs', 'debit', NULL, false, NULL, true),
    (5010, 1, '5100', 'HPP - Persediaan', 'cogs', 'debit', 5001, false, NULL, true),
    -- Beban
    (6001, 1, '6000', 'BEBAN', 'expense', 'debit', NULL, false, NULL, true),
    (6010, 1, '6100', 'Beban Sewa Kantor', 'expense', 'debit', 6001, false, NULL, true),
    (6020, 1, '6200', 'Beban Jasa Profesional', 'expense', 'debit', 6001, false, NULL, true),
    (6030, 1, '6300', 'Beban Penyusutan', 'expense', 'debit', 6001, false, NULL, true),
    (6040, 1, '6400', 'Beban Perlengkapan Kantor', 'expense', 'debit', 6001, false, NULL, true);

-- Company 2 (Client Trust) — minimal COA, trust accounting is simpler
INSERT INTO accounts (id, company_id, account_code, account_name, account_type,
                       normal_balance, is_control_account, control_account_for, is_active) VALUES
    (2101, 2, '1100', 'Bank BCA - Trust Account', 'asset', 'debit', false, 'bank_cash', true),
    (2110, 2, '2100', 'Titipan Dana Klien', 'liability', 'credit', false, NULL, true);

-- ----------------------------------------------------------------------------
-- 4. FISCAL YEAR / PERIODS (Company 1, FY2026, Jan–Dec)
-- ----------------------------------------------------------------------------
INSERT INTO fiscal_years (id, company_id, year_code, start_date, end_date, status) VALUES
    (1, 1, '2026', '2026-01-01', '2026-12-31', 'open');

INSERT INTO fiscal_periods (id, company_id, fiscal_year_id, period_no, start_date, end_date, status) VALUES
    (101, 1, 1, 1, '2026-01-01', '2026-01-31', 'hard_closed'),
    (102, 1, 1, 2, '2026-02-01', '2026-02-28', 'hard_closed'),
    (103, 1, 1, 3, '2026-03-01', '2026-03-31', 'hard_closed'),
    (104, 1, 1, 4, '2026-04-01', '2026-04-30', 'hard_closed'),
    (105, 1, 1, 5, '2026-05-01', '2026-05-31', 'hard_closed'),
    (106, 1, 1, 6, '2026-06-01', '2026-06-30', 'soft_closed'),
    (107, 1, 1, 7, '2026-07-01', '2026-07-31', 'open'),
    (108, 1, 1, 8, '2026-08-01', '2026-08-31', 'open'),
    (109, 1, 1, 9, '2026-09-01', '2026-09-30', 'open'),
    (110, 1, 1, 10, '2026-10-01', '2026-10-31', 'open'),
    (111, 1, 1, 11, '2026-11-01', '2026-11-30', 'open'),
    (112, 1, 1, 12, '2026-12-01', '2026-12-31', 'open');

-- ----------------------------------------------------------------------------
-- 5. COST CENTERS
-- ----------------------------------------------------------------------------
INSERT INTO cost_centers (id, company_id, code, name, is_active) VALUES
    (1, 1, 'LIT', 'Litigation Team', true),
    (2, 1, 'CORP', 'Corporate/Advisory Team', true),
    (3, 1, 'ADMIN', 'Firm Administration', true);

-- ----------------------------------------------------------------------------
-- 6. TAX CODES (PPN) & WITHHOLDING TYPES (PPh) — Company 1
-- ----------------------------------------------------------------------------
INSERT INTO tax_codes (id, company_id, code, name, tax_type, rate, gl_account_id, is_active) VALUES
    (1, 1, 'PPN11-OUT', 'PPN Keluaran 11%', 'output', 11.000, 2020, true),
    (2, 1, 'PPN11-IN',  'PPN Masukan 11%',  'input',  11.000, 1021, true);

INSERT INTO withholding_types (id, company_id, code, name, rate, is_final, bupot_form_type,
                                gl_payable_account_id, is_active) VALUES
    (1, 1, 'PPH23', 'PPh Pasal 23 - Jasa Profesional', 2.000, false, 'BP23', 2030, true),
    (2, 1, 'PPH4A2', 'PPh Pasal 4(2) - Sewa Bangunan', 10.000, true, 'BP4(2)', 2031, true);

-- ----------------------------------------------------------------------------
-- 7. ASSET GROUPS (Indonesian fiscal depreciation classification defaults)
-- ----------------------------------------------------------------------------
INSERT INTO asset_groups (id, company_id, code, name, group_class,
                           commercial_method, commercial_useful_life_months,
                           fiscal_method, fiscal_rate, fiscal_useful_life_months, is_active) VALUES
    (1, 1, 'KEL1', 'Kelompok 1 - Peralatan Kantor/Elektronik', 'kelompok_1',
        'straight_line', 48, 'declining_balance', 50.000, 48, true),
    (2, 1, 'KEL2', 'Kelompok 2 - Kendaraan/Furnitur', 'kelompok_2',
        'straight_line', 96, 'declining_balance', 25.000, 96, true);

-- ----------------------------------------------------------------------------
-- 8. BANK ACCOUNTS
-- ----------------------------------------------------------------------------
INSERT INTO bank_accounts (id, company_id, account_type, bank_name, account_no_last4,
                            account_holder_name, currency_code, gl_account_id, is_active) VALUES
    (1, 1, 'bank', 'Bank BCA', '4521', 'PT Kusuma Hukum Indonesia', 'IDR', 1012, true),
    (2, 1, 'cash', NULL, NULL, 'Kas Kecil Kantor', 'IDR', 1011, true),
    (3, 2, 'bank', 'Bank BCA', '7788', 'Kusuma Client Trust Account', 'IDR', 2101, true);

-- ----------------------------------------------------------------------------
-- 9. GENERAL LEDGER — sample posted journal (opening balance, July 2026)
-- ----------------------------------------------------------------------------
INSERT INTO gl_journals (id, uuid, company_id, journal_no, journal_date, fiscal_period_id,
                          currency_code, exchange_rate, memo, source_module, status,
                          posted_by, posted_at, created_by) VALUES
    (1, gen_random_uuid(), 1, 'GL-2026-07-0001', '2026-07-01', 107, 'IDR', 1,
        'Saldo awal modal disetor', 'manual', 'posted', 1, '2026-07-01 09:00:00+07', 1);

INSERT INTO gl_journal_lines (journal_id, line_no, account_id, description,
                               debit_amount, credit_amount, debit_amount_base, credit_amount_base) VALUES
    (1, 1, 1012, 'Setoran modal awal ke Bank BCA', 500000000.00, 0, 500000000.00, 0),
    (1, 2, 3010, 'Modal disetor pemilik', 0, 500000000.00, 0, 500000000.00);

-- ----------------------------------------------------------------------------
-- 10. AR — invoice to PT Sinergi Maju Bersama (partner 501), with PPN output
-- ----------------------------------------------------------------------------
INSERT INTO gl_journals (id, uuid, company_id, journal_no, journal_date, fiscal_period_id,
                          currency_code, exchange_rate, memo, source_module, subject_type, subject_id,
                          status, posted_by, posted_at, created_by) VALUES
    (2, gen_random_uuid(), 1, 'GL-2026-07-0002', '2026-07-05', 107, 'IDR', 1,
        'Posting AR Invoice INV-2026-0001', 'ar', 'accounting.ar_invoices', 1,
        'posted', 1, '2026-07-05 14:00:00+07', 1);

INSERT INTO gl_journal_lines (journal_id, line_no, account_id, cost_center_id, description,
                               debit_amount, credit_amount, debit_amount_base, credit_amount_base) VALUES
    (2, 1, 1020, NULL, 'Piutang - PT Sinergi Maju Bersama', 55500000.00, 0, 55500000.00, 0),
    (2, 2, 4010, 2, 'Pendapatan jasa hukum - review kontrak', 0, 50000000.00, 0, 50000000.00),
    (2, 3, 2020, NULL, 'PPN Keluaran 11%', 0, 5500000.00, 0, 5500000.00);

INSERT INTO ar_invoices (id, uuid, company_id, invoice_no, partner_id, invoice_date, due_date,
                          currency_code, exchange_rate, subtotal_amount, tax_code_id, tax_amount,
                          total_amount, paid_amount, status, subject_type, subject_id,
                          journal_id, created_by) VALUES
    (1, gen_random_uuid(), 1, 'INV-2026-0001', 501, '2026-07-05', '2026-08-04',
        'IDR', 1, 50000000.00, 1, 5500000.00, 55500000.00, 0, 'posted',
        'legal.case_hdrs', 2, 2, 1);

INSERT INTO ar_invoice_lines (invoice_id, line_no, description, qty, unit_price,
                               discount_amount, line_amount, revenue_account_id, tax_code_id, cost_center_id) VALUES
    (1, 1, 'Legal review — perjanjian kerja sama PT Sinergi Maju Bersama', 1, 50000000.00,
        0, 50000000.00, 4010, 1, 2);

INSERT INTO tax_faktur_pajak (id, uuid, company_id, nomor_faktur, faktur_type, ar_invoice_id,
                               partner_id, partner_npwp, tax_base_amount, ppn_amount, faktur_date,
                               status, created_by) VALUES
    (1, gen_random_uuid(), 1, '010.026-26.00000001', 'output', 1, 501,
        '02.345.678.9-012.000', 50000000.00, 5500000.00, '2026-07-05', 'issued', 1);

UPDATE ar_invoices SET faktur_pajak_id = 1 WHERE id = 1;

-- ----------------------------------------------------------------------------
-- 11. AP — bill from Notaris Dewi & Rekan (partner 602), subject to PPh 23
-- ----------------------------------------------------------------------------
INSERT INTO gl_journals (id, uuid, company_id, journal_no, journal_date, fiscal_period_id,
                          currency_code, exchange_rate, memo, source_module, subject_type, subject_id,
                          status, posted_by, posted_at, created_by) VALUES
    (3, gen_random_uuid(), 1, 'GL-2026-07-0003', '2026-07-10', 107, 'IDR', 1,
        'Posting AP Bill BILL-2026-0001', 'ap', 'accounting.ap_bills', 1,
        'posted', 1, '2026-07-10 11:00:00+07', 1);

INSERT INTO gl_journal_lines (journal_id, line_no, account_id, cost_center_id, description,
                               debit_amount, credit_amount, debit_amount_base, credit_amount_base) VALUES
    (3, 1, 6020, 3, 'Jasa notaris - legalisasi dokumen', 10000000.00, 0, 10000000.00, 0),
    (3, 2, 2010, NULL, 'Utang - Notaris Dewi & Rekan', 0, 9800000.00, 0, 9800000.00),
    (3, 3, 2030, NULL, 'PPh 23 dipotong 2%', 0, 200000.00, 0, 200000.00);

INSERT INTO ap_bills (id, uuid, company_id, bill_no, partner_id, bill_date, due_date,
                       currency_code, exchange_rate, subtotal_amount, withholding_type_id,
                       withholding_amount, total_amount, net_payable_amount, paid_amount,
                       status, journal_id, created_by) VALUES
    (1, gen_random_uuid(), 1, 'BILL-2026-0001', 602, '2026-07-10', '2026-08-09',
        'IDR', 1, 10000000.00, 1, 200000.00, 10000000.00, 9800000.00, 0,
        'posted', 3, 1);

INSERT INTO ap_bill_lines (bill_id, line_no, description, qty, unit_price, discount_amount,
                            line_amount, expense_account_id, cost_center_id) VALUES
    (1, 1, 'Jasa notaris - legalisasi dokumen perjanjian', 1, 10000000.00, 0, 10000000.00, 6020, 3);

INSERT INTO tax_bukti_potong (id, uuid, company_id, bukti_potong_no, form_type, ap_bill_id,
                               partner_id, partner_npwp, gross_amount, tax_rate, withheld_amount,
                               is_final, bukti_potong_date, status, created_by) VALUES
    (1, gen_random_uuid(), 1, 'BP23-2026-0001', 'BP23', 1, 602, '03.456.789.0-123.000',
        10000000.00, 2.000, 200000.00, false, '2026-07-10', 'issued', 1);

UPDATE ap_bills SET bukti_potong_id = 1 WHERE id = 1;

-- A second, unpaid bill (office supplies vendor, no withholding) for AP aging demo
INSERT INTO ap_bills (id, uuid, company_id, bill_no, partner_id, bill_date, due_date,
                       currency_code, exchange_rate, subtotal_amount, total_amount,
                       net_payable_amount, paid_amount, status, created_by) VALUES
    (2, gen_random_uuid(), 1, 'BILL-2026-0002', 601, '2026-07-15', '2026-08-14',
        'IDR', 1, 3500000.00, 3500000.00, 3500000.00, 0, 'draft', 1);

INSERT INTO ap_bill_lines (bill_id, line_no, description, qty, unit_price, discount_amount,
                            line_amount, expense_account_id, cost_center_id) VALUES
    (2, 1, 'ATK dan perlengkapan kantor bulan Juli', 1, 3500000.00, 0, 3500000.00, 6040, 3);

-- ----------------------------------------------------------------------------
-- 12. AR PAYMENT — partial payment against INV-2026-0001
-- ----------------------------------------------------------------------------
INSERT INTO gl_journals (id, uuid, company_id, journal_no, journal_date, fiscal_period_id,
                          currency_code, exchange_rate, memo, source_module, status,
                          posted_by, posted_at, created_by) VALUES
    (4, gen_random_uuid(), 1, 'GL-2026-07-0004', '2026-07-20', 107, 'IDR', 1,
        'Penerimaan pembayaran INV-2026-0001 (sebagian)', 'ar', 'posted', 1, '2026-07-20 10:00:00+07', 1);

INSERT INTO gl_journal_lines (journal_id, line_no, account_id, description,
                               debit_amount, credit_amount, debit_amount_base, credit_amount_base) VALUES
    (4, 1, 1012, 'Penerimaan dari PT Sinergi Maju Bersama', 30000000.00, 0, 30000000.00, 0),
    (4, 2, 1020, 'Pelunasan sebagian piutang', 0, 30000000.00, 0, 30000000.00);

INSERT INTO ar_payments (id, uuid, company_id, payment_no, partner_id, payment_date,
                          bank_account_id, currency_code, exchange_rate, amount, status,
                          journal_id, memo, created_by) VALUES
    (1, gen_random_uuid(), 1, 'RCPT-2026-0001', 501, '2026-07-20', 1, 'IDR', 1,
        30000000.00, 'posted', 4, 'Pembayaran sebagian INV-2026-0001', 1);

INSERT INTO ar_payment_applications (payment_id, invoice_id, applied_amount) VALUES
    (1, 1, 30000000.00);

UPDATE ar_invoices SET paid_amount = 30000000.00, status = 'partially_paid' WHERE id = 1;

-- ----------------------------------------------------------------------------
-- 13. FIXED ASSET — office laptop, Kelompok 1
-- ----------------------------------------------------------------------------
INSERT INTO fa_assets (id, uuid, company_id, asset_no, name, asset_group_id, acquisition_date,
                        acquisition_cost, vendor_partner_id, commercial_nbv, fiscal_nbv, location,
                        status, gl_asset_account_id, gl_depreciation_expense_account_id,
                        gl_accum_depreciation_account_id, created_by) VALUES
    (1, gen_random_uuid(), 1, 'FA-2026-0001', 'Laptop MacBook Pro 14" (Tim Litigasi)', 1,
        '2026-02-01', 32000000.00, 601, 30666666.67, 16000000.00, 'Kantor Jakarta Selatan',
        'active', 1041, 6030, 1042, 1);

INSERT INTO fa_depreciation_schedule_commercial (asset_id, fiscal_period_id, depreciation_amount,
                                                   accumulated_amount, nbv_after, journal_id, status, posted_at) VALUES
    (1, 102, 666666.67, 666666.67, 31333333.33, NULL, 'posted', '2026-02-28 00:00:00+07'),
    (1, 103, 666666.67, 1333333.34, 30666666.66, NULL, 'posted', '2026-03-31 00:00:00+07');

INSERT INTO fa_depreciation_schedule_fiscal (asset_id, fiscal_period_id, depreciation_amount,
                                               accumulated_amount, nbv_after, status) VALUES
    (1, 102, 1333333.33, 1333333.33, 30666666.67, 'recognized'),
    (1, 103, 1333333.33, 2666666.66, 29333333.34, 'recognized');

-- ----------------------------------------------------------------------------
-- 14. BUDGET (FY2026, sample two accounts x two periods)
-- ----------------------------------------------------------------------------
INSERT INTO budgets (id, company_id, fiscal_year_id, name, status, created_by) VALUES
    (1, 1, 1, 'Anggaran FY2026', 'active', 1);

INSERT INTO budget_lines (budget_id, account_id, cost_center_id, fiscal_period_id, budget_amount) VALUES
    (1, 4010, 2, 107, 60000000.00),
    (1, 4010, 2, 108, 60000000.00),
    (1, 6010, 3, 107, 15000000.00),
    (1, 6010, 3, 108, 15000000.00);

-- ----------------------------------------------------------------------------
-- 15. RECURRING TEMPLATE — monthly office rent journal
-- ----------------------------------------------------------------------------
INSERT INTO recurring_journal_templates (id, company_id, name, recurrence_rule, next_run_date,
                                          currency_code, is_active, created_by) VALUES
    (1, 1, 'Beban Sewa Kantor Bulanan', 'FREQ=MONTHLY;BYMONTHDAY=1', '2026-08-01', 'IDR', true, 1);

INSERT INTO recurring_journal_template_lines (template_id, account_id, cost_center_id, description,
                                                debit_amount, credit_amount) VALUES
    (1, 6010, 3, 'Beban sewa kantor bulan berjalan', 15000000.00, 0),
    (1, 1012, NULL, 'Pembayaran sewa via Bank BCA', 0, 15000000.00);

-- ----------------------------------------------------------------------------
-- 16. TAX PERIODS (SPT Masa PPN & PPh Unifikasi, July 2026)
-- ----------------------------------------------------------------------------
INSERT INTO tax_periods (company_id, obligation_type, period_year, period_month, due_date, filing_status) VALUES
    (1, 'ppn_masa', 2026, 7, '2026-08-31', 'open'),
    (1, 'pph_unifikasi', 2026, 7, '2026-08-10', 'open');

-- ----------------------------------------------------------------------------
-- 17. AUDIT LOG sample entries
-- ----------------------------------------------------------------------------
INSERT INTO audit_logs (company_id, action_type, actor_id, actor_name, record_type, record_id, after_snapshot) VALUES
    (1, 'invoice_posted', 1, 'Simon (Admin)', 'ar_invoices', 1, '{"status":"posted","total_amount":55500000.00}'),
    (1, 'bill_posted', 1, 'Simon (Admin)', 'ap_bills', 1, '{"status":"posted","withholding_amount":200000.00}'),
    (1, 'tax_document_issued', 1, 'Simon (Admin)', 'tax_faktur_pajak', 1, '{"nomor_faktur":"010.026-26.00000001"}'),
    (1, 'tax_document_issued', 1, 'Simon (Admin)', 'tax_bukti_potong', 1, '{"bukti_potong_no":"BP23-2026-0001"}');

-- ----------------------------------------------------------------------------
-- Reset sequences so future inserts continue past explicit seed IDs
-- ----------------------------------------------------------------------------
SELECT setval(pg_get_serial_sequence('companies','id'), 100, false);
SELECT setval(pg_get_serial_sequence('accounts','id'), 10000, false);
SELECT setval(pg_get_serial_sequence('fiscal_years','id'), 100, false);
SELECT setval(pg_get_serial_sequence('fiscal_periods','id'), 1000, false);
SELECT setval(pg_get_serial_sequence('cost_centers','id'), 100, false);
SELECT setval(pg_get_serial_sequence('tax_codes','id'), 100, false);
SELECT setval(pg_get_serial_sequence('withholding_types','id'), 100, false);
SELECT setval(pg_get_serial_sequence('asset_groups','id'), 100, false);
SELECT setval(pg_get_serial_sequence('bank_accounts','id'), 100, false);
SELECT setval(pg_get_serial_sequence('gl_journals','id'), 100, false);
SELECT setval(pg_get_serial_sequence('ar_invoices','id'), 100, false);
SELECT setval(pg_get_serial_sequence('ap_bills','id'), 100, false);
SELECT setval(pg_get_serial_sequence('ar_payments','id'), 100, false);
SELECT setval(pg_get_serial_sequence('tax_faktur_pajak','id'), 100, false);
SELECT setval(pg_get_serial_sequence('tax_bukti_potong','id'), 100, false);
SELECT setval(pg_get_serial_sequence('fa_assets','id'), 100, false);
SELECT setval(pg_get_serial_sequence('budgets','id'), 100, false);
SELECT setval(pg_get_serial_sequence('recurring_journal_templates','id'), 100, false);

COMMIT;
