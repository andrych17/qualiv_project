-- ============================================================================
-- ACCOUNTING Module — Schema DDL
-- Tenant DB (DB-per-tenant), schema "ACCOUNTING"
-- PostgreSQL 16
--
-- Prerequisite: this tenant DB already has a "CRM" schema with a
-- CRM.partners(id BIGINT PK) table (per CRM_SPECS.md) — AR/AP/Assets/
-- Recurring tables FK directly into it (Core-to-Core FK is allowed per
-- CLAUDE.md / CRM_SPECS.md §5).
--
-- Conventions (per CLAUDE.md §7 / ACCOUNTING_SPECS.md §4-5):
--   - BIGSERIAL/BIGINT for PK/FK/JOIN, UUID for external-facing reference.
--   - Schema named in uppercase, matching module name: "ACCOUNTING".
--   - Master tables: single word. Transaction tables: domain-prefixed
--     (gl_, ar_, ap_, fa_, inv_, tax_, recurring_).
--   - company_id on every company-scoped table — this is an intra-tenant
--     multi-entity axis, NOT a tenant-isolation column (see spec §5 for why
--     this is not the same issue as the WNE tenant_id inconsistency).
--   - No PostgreSQL RLS (per CLAUDE.md §7A) — scoping enforced at app layer.
--   - Status/type fields use VARCHAR + CHECK rather than native ENUM, so
--     adding a new status value is a constraint migration, not a type
--     rewrite (native ENUM ALTER is more disruptive in Postgres).
-- ============================================================================

BEGIN;

CREATE SCHEMA IF NOT EXISTS "ACCOUNTING";
SET search_path TO "ACCOUNTING", public;

-- ============================================================================
-- 1. SETUP / MASTER TABLES
-- ============================================================================

CREATE TABLE companies (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    legal_name                  VARCHAR(255) NOT NULL,
    trade_name                  VARCHAR(255),
    npwp                        VARCHAR(30),                 -- Indonesian tax ID
    nitku                       VARCHAR(30),                 -- Coretax business-unit tax ID
    address                     TEXT,
    base_currency_code          VARCHAR(3) NOT NULL DEFAULT 'IDR',
    fiscal_year_start_month     SMALLINT NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX ux_companies_uuid ON companies(uuid);

CREATE TABLE accounts (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    account_code                VARCHAR(20) NOT NULL,
    account_name                VARCHAR(150) NOT NULL,
    account_type                VARCHAR(20) NOT NULL CHECK (account_type IN
                                    ('asset','liability','equity','revenue','cogs','expense')),
    normal_balance              VARCHAR(6) NOT NULL CHECK (normal_balance IN ('debit','credit')),
    parent_account_id           BIGINT REFERENCES accounts(id),
    is_control_account          BOOLEAN NOT NULL DEFAULT FALSE,
    control_account_for         VARCHAR(20) CHECK (control_account_for IN ('ar','ap','inventory','bank_cash')),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, account_code)
);
CREATE INDEX ix_accounts_company ON accounts(company_id);
CREATE INDEX ix_accounts_parent ON accounts(parent_account_id);

CREATE TABLE fiscal_years (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    year_code                   VARCHAR(10) NOT NULL,        -- e.g. '2026'
    start_date                  DATE NOT NULL,
    end_date                    DATE NOT NULL,
    status                      VARCHAR(15) NOT NULL DEFAULT 'open'
                                    CHECK (status IN ('open','soft_closed','hard_closed')),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, year_code)
);

CREATE TABLE fiscal_periods (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    fiscal_year_id              BIGINT NOT NULL REFERENCES fiscal_years(id),
    period_no                   SMALLINT NOT NULL CHECK (period_no BETWEEN 1 AND 12),
    start_date                  DATE NOT NULL,
    end_date                    DATE NOT NULL,
    status                      VARCHAR(15) NOT NULL DEFAULT 'open'
                                    CHECK (status IN ('open','soft_closed','hard_closed')),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, fiscal_year_id, period_no)
);
CREATE INDEX ix_fiscal_periods_year ON fiscal_periods(fiscal_year_id);

CREATE TABLE cost_centers (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    code                        VARCHAR(20) NOT NULL,
    name                        VARCHAR(150) NOT NULL,
    parent_cost_center_id       BIGINT REFERENCES cost_centers(id),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (company_id, code)
);

CREATE TABLE currencies (
    code                        CHAR(3) PRIMARY KEY,          -- ISO 4217, e.g. 'IDR','USD'
    name                        VARCHAR(60) NOT NULL,
    symbol                      VARCHAR(10),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE exchange_rates (
    id                          BIGSERIAL PRIMARY KEY,
    currency_code               CHAR(3) NOT NULL REFERENCES currencies(code),
    rate_date                   DATE NOT NULL,
    rate_to_base                NUMERIC(18,6) NOT NULL,       -- 1 unit currency = X base currency
    source                      VARCHAR(20) NOT NULL DEFAULT 'manual',
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (currency_code, rate_date)
);

CREATE TABLE tax_codes (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    code                        VARCHAR(20) NOT NULL,          -- e.g. 'PPN11-OUT'
    name                        VARCHAR(100) NOT NULL,
    tax_type                    VARCHAR(10) NOT NULL CHECK (tax_type IN ('output','input')),
    rate                        NUMERIC(6,3) NOT NULL,         -- percentage, e.g. 11.000
    gl_account_id               BIGINT NOT NULL REFERENCES accounts(id),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (company_id, code)
);

CREATE TABLE withholding_types (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    code                        VARCHAR(20) NOT NULL,          -- e.g. 'PPH23','PPH4A2','PPH21_NONPEG'
    name                        VARCHAR(100) NOT NULL,
    rate                        NUMERIC(6,3) NOT NULL,
    is_final                    BOOLEAN NOT NULL DEFAULT FALSE,
    bupot_form_type              VARCHAR(10) NOT NULL,          -- BP21 / BP23 / BP4(2) / BPU / BP22 / BP15
    gl_payable_account_id       BIGINT NOT NULL REFERENCES accounts(id),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (company_id, code)
);

CREATE TABLE asset_groups (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    code                        VARCHAR(20) NOT NULL,
    name                        VARCHAR(100) NOT NULL,
    group_class                 VARCHAR(30) NOT NULL CHECK (group_class IN
                                    ('kelompok_1','kelompok_2','kelompok_3','kelompok_4',
                                     'bangunan_permanen','bangunan_non_permanen')),
    commercial_method           VARCHAR(20) NOT NULL DEFAULT 'straight_line'
                                    CHECK (commercial_method IN ('straight_line','declining_balance')),
    commercial_useful_life_months INT NOT NULL,
    fiscal_method                VARCHAR(20) NOT NULL DEFAULT 'straight_line'
                                    CHECK (fiscal_method IN ('straight_line','declining_balance')),
    fiscal_rate                  NUMERIC(6,3) NOT NULL,        -- annual %, per prevailing regulation
    fiscal_useful_life_months    INT NOT NULL,
    is_active                    BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (company_id, code)
);

CREATE TABLE bank_accounts (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    account_type                VARCHAR(10) NOT NULL CHECK (account_type IN ('bank','cash')),
    bank_name                   VARCHAR(150),
    account_no_last4            VARCHAR(4),                   -- full number kept in app-layer secret store, not here
    account_holder_name         VARCHAR(150),
    currency_code               CHAR(3) NOT NULL REFERENCES currencies(code),
    gl_account_id               BIGINT NOT NULL REFERENCES accounts(id),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_bank_accounts_company ON bank_accounts(company_id);

-- ============================================================================
-- 2. GENERAL LEDGER
-- ============================================================================

CREATE TABLE gl_journals (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    journal_no                  VARCHAR(30) NOT NULL,
    journal_date                DATE NOT NULL,
    fiscal_period_id            BIGINT NOT NULL REFERENCES fiscal_periods(id),
    currency_code               CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate               NUMERIC(18,6) NOT NULL DEFAULT 1,
    memo                        TEXT,
    source_module                VARCHAR(30) NOT NULL DEFAULT 'manual',   -- manual | ar | ap | fa | inv | recurring | erp:<module>
    subject_type                VARCHAR(60),                              -- polymorphic link back to triggering record
    subject_id                  BIGINT,
    status                      VARCHAR(20) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','pending_approval','posted','reversed')),
    reversed_journal_id         BIGINT REFERENCES gl_journals(id),
    posted_by                   BIGINT,
    posted_at                   TIMESTAMPTZ,
    created_by                  BIGINT,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, journal_no)
);
CREATE INDEX ix_gl_journals_company_period ON gl_journals(company_id, fiscal_period_id);
CREATE INDEX ix_gl_journals_status ON gl_journals(status);
CREATE INDEX ix_gl_journals_subject ON gl_journals(subject_type, subject_id);

CREATE TABLE gl_journal_lines (
    id                          BIGSERIAL PRIMARY KEY,
    journal_id                  BIGINT NOT NULL REFERENCES gl_journals(id) ON DELETE CASCADE,
    line_no                     SMALLINT NOT NULL,
    account_id                  BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id               BIGINT REFERENCES cost_centers(id),
    description                 TEXT,
    debit_amount                NUMERIC(18,2) NOT NULL DEFAULT 0,
    credit_amount                NUMERIC(18,2) NOT NULL DEFAULT 0,
    debit_amount_base            NUMERIC(18,2) NOT NULL DEFAULT 0,        -- base-currency equivalent
    credit_amount_base           NUMERIC(18,2) NOT NULL DEFAULT 0,
    CHECK (debit_amount >= 0 AND credit_amount >= 0),
    CHECK (NOT (debit_amount > 0 AND credit_amount > 0)),
    UNIQUE (journal_id, line_no)
);
CREATE INDEX ix_gl_journal_lines_account ON gl_journal_lines(account_id);
CREATE INDEX ix_gl_journal_lines_cost_center ON gl_journal_lines(cost_center_id);

-- ============================================================================
-- 3. ACCOUNTS RECEIVABLE
-- ============================================================================

CREATE TABLE ar_invoices (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    invoice_no                  VARCHAR(30) NOT NULL,
    partner_id                  BIGINT NOT NULL REFERENCES "CRM".partners(id),
    invoice_date                DATE NOT NULL,
    due_date                    DATE NOT NULL,
    currency_code               CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate               NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount              NUMERIC(18,2) NOT NULL DEFAULT 0,
    tax_code_id                  BIGINT REFERENCES tax_codes(id),
    tax_amount                   NUMERIC(18,2) NOT NULL DEFAULT 0,
    total_amount                 NUMERIC(18,2) NOT NULL DEFAULT 0,
    paid_amount                  NUMERIC(18,2) NOT NULL DEFAULT 0,
    status                       VARCHAR(20) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','posted','partially_paid','paid','void')),
    subject_type                 VARCHAR(60),                             -- e.g. 'legal.case_hdrs'
    subject_id                   BIGINT,
    journal_id                   BIGINT REFERENCES gl_journals(id),
    faktur_pajak_id              BIGINT,                                  -- FK added after tax_faktur_pajak created below
    created_by                   BIGINT,
    created_at                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, invoice_no)
);
CREATE INDEX ix_ar_invoices_company_status ON ar_invoices(company_id, status);
CREATE INDEX ix_ar_invoices_partner ON ar_invoices(partner_id);
CREATE INDEX ix_ar_invoices_subject ON ar_invoices(subject_type, subject_id);

CREATE TABLE ar_invoice_lines (
    id                          BIGSERIAL PRIMARY KEY,
    invoice_id                   BIGINT NOT NULL REFERENCES ar_invoices(id) ON DELETE CASCADE,
    line_no                      SMALLINT NOT NULL,
    description                  TEXT NOT NULL,
    qty                          NUMERIC(14,3) NOT NULL DEFAULT 1,
    unit_price                   NUMERIC(18,2) NOT NULL,
    discount_amount               NUMERIC(18,2) NOT NULL DEFAULT 0,
    line_amount                   NUMERIC(18,2) NOT NULL,
    revenue_account_id            BIGINT NOT NULL REFERENCES accounts(id),
    tax_code_id                   BIGINT REFERENCES tax_codes(id),
    cost_center_id                 BIGINT REFERENCES cost_centers(id),
    UNIQUE (invoice_id, line_no)
);

CREATE TABLE ar_payments (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    payment_no                  VARCHAR(30) NOT NULL,
    partner_id                  BIGINT NOT NULL REFERENCES "CRM".partners(id),
    payment_date                DATE NOT NULL,
    bank_account_id              BIGINT NOT NULL REFERENCES bank_accounts(id),
    currency_code                CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate                 NUMERIC(18,6) NOT NULL DEFAULT 1,
    amount                        NUMERIC(18,2) NOT NULL,
    status                        VARCHAR(20) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','posted','void')),
    journal_id                    BIGINT REFERENCES gl_journals(id),
    memo                          TEXT,
    created_by                    BIGINT,
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, payment_no)
);
CREATE INDEX ix_ar_payments_partner ON ar_payments(partner_id);

CREATE TABLE ar_payment_applications (
    id                          BIGSERIAL PRIMARY KEY,
    payment_id                   BIGINT NOT NULL REFERENCES ar_payments(id) ON DELETE CASCADE,
    invoice_id                    BIGINT NOT NULL REFERENCES ar_invoices(id),
    applied_amount                 NUMERIC(18,2) NOT NULL
);
CREATE INDEX ix_ar_pay_app_invoice ON ar_payment_applications(invoice_id);

CREATE TABLE ar_credit_notes (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    credit_note_no                VARCHAR(30) NOT NULL,
    partner_id                    BIGINT NOT NULL REFERENCES "CRM".partners(id),
    invoice_id                    BIGINT REFERENCES ar_invoices(id),
    credit_date                    DATE NOT NULL,
    currency_code                  CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate                   NUMERIC(18,6) NOT NULL DEFAULT 1,
    total_amount                    NUMERIC(18,2) NOT NULL,
    status                          VARCHAR(20) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','posted','void')),
    journal_id                      BIGINT REFERENCES gl_journals(id),
    reason                          TEXT,
    created_by                      BIGINT,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, credit_note_no)
);

CREATE TABLE ar_credit_note_lines (
    id                          BIGSERIAL PRIMARY KEY,
    credit_note_id                BIGINT NOT NULL REFERENCES ar_credit_notes(id) ON DELETE CASCADE,
    description                    TEXT NOT NULL,
    amount                          NUMERIC(18,2) NOT NULL,
    revenue_account_id              BIGINT NOT NULL REFERENCES accounts(id),
    tax_code_id                     BIGINT REFERENCES tax_codes(id)
);

-- ============================================================================
-- 4. ACCOUNTS PAYABLE
-- ============================================================================

CREATE TABLE ap_bills (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    bill_no                     VARCHAR(30) NOT NULL,
    partner_id                  BIGINT NOT NULL REFERENCES "CRM".partners(id),
    bill_date                   DATE NOT NULL,
    due_date                    DATE NOT NULL,
    currency_code               CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate               NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount              NUMERIC(18,2) NOT NULL DEFAULT 0,
    withholding_type_id           BIGINT REFERENCES withholding_types(id),
    withholding_amount             NUMERIC(18,2) NOT NULL DEFAULT 0,
    total_amount                   NUMERIC(18,2) NOT NULL DEFAULT 0,
    net_payable_amount              NUMERIC(18,2) NOT NULL DEFAULT 0,      -- total - withholding
    paid_amount                     NUMERIC(18,2) NOT NULL DEFAULT 0,
    status                          VARCHAR(20) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','pending_approval','posted','partially_paid','paid','void')),
    subject_type                     VARCHAR(60),
    subject_id                       BIGINT,
    journal_id                       BIGINT REFERENCES gl_journals(id),
    bukti_potong_id                   BIGINT,                              -- FK added after tax_bukti_potong created below
    created_by                        BIGINT,
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, bill_no)
);
CREATE INDEX ix_ap_bills_company_status ON ap_bills(company_id, status);
CREATE INDEX ix_ap_bills_partner ON ap_bills(partner_id);
CREATE INDEX ix_ap_bills_subject ON ap_bills(subject_type, subject_id);

CREATE TABLE ap_bill_lines (
    id                          BIGSERIAL PRIMARY KEY,
    bill_id                     BIGINT NOT NULL REFERENCES ap_bills(id) ON DELETE CASCADE,
    line_no                     SMALLINT NOT NULL,
    description                 TEXT NOT NULL,
    qty                         NUMERIC(14,3) NOT NULL DEFAULT 1,
    unit_price                  NUMERIC(18,2) NOT NULL,
    discount_amount               NUMERIC(18,2) NOT NULL DEFAULT 0,
    line_amount                    NUMERIC(18,2) NOT NULL,
    expense_account_id              BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id                   BIGINT REFERENCES cost_centers(id),
    UNIQUE (bill_id, line_no)
);

CREATE TABLE ap_payments (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    payment_no                  VARCHAR(30) NOT NULL,
    partner_id                  BIGINT NOT NULL REFERENCES "CRM".partners(id),
    payment_date                DATE NOT NULL,
    bank_account_id               BIGINT NOT NULL REFERENCES bank_accounts(id),
    currency_code                  CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate                   NUMERIC(18,6) NOT NULL DEFAULT 1,
    amount                           NUMERIC(18,2) NOT NULL,
    status                           VARCHAR(20) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','pending_approval','posted','void')),
    journal_id                       BIGINT REFERENCES gl_journals(id),
    memo                              TEXT,
    created_by                        BIGINT,
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, payment_no)
);
CREATE INDEX ix_ap_payments_partner ON ap_payments(partner_id);

CREATE TABLE ap_payment_applications (
    id                          BIGSERIAL PRIMARY KEY,
    payment_id                   BIGINT NOT NULL REFERENCES ap_payments(id) ON DELETE CASCADE,
    bill_id                       BIGINT NOT NULL REFERENCES ap_bills(id),
    applied_amount                  NUMERIC(18,2) NOT NULL
);
CREATE INDEX ix_ap_pay_app_bill ON ap_payment_applications(bill_id);

CREATE TABLE ap_debit_notes (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    debit_note_no                 VARCHAR(30) NOT NULL,
    partner_id                     BIGINT NOT NULL REFERENCES "CRM".partners(id),
    bill_id                         BIGINT REFERENCES ap_bills(id),
    debit_date                       DATE NOT NULL,
    currency_code                     CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate                      NUMERIC(18,6) NOT NULL DEFAULT 1,
    total_amount                        NUMERIC(18,2) NOT NULL,
    status                               VARCHAR(20) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','posted','void')),
    journal_id                           BIGINT REFERENCES gl_journals(id),
    reason                                TEXT,
    created_by                            BIGINT,
    created_at                            TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, debit_note_no)
);

CREATE TABLE ap_debit_note_lines (
    id                          BIGSERIAL PRIMARY KEY,
    debit_note_id                 BIGINT NOT NULL REFERENCES ap_debit_notes(id) ON DELETE CASCADE,
    description                    TEXT NOT NULL,
    amount                          NUMERIC(18,2) NOT NULL,
    expense_account_id               BIGINT NOT NULL REFERENCES accounts(id)
);

-- ============================================================================
-- 5. CASH & BANKS
-- ============================================================================

CREATE TABLE cash_transactions (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    bank_account_id              BIGINT NOT NULL REFERENCES bank_accounts(id),
    transaction_date             DATE NOT NULL,
    transaction_type              VARCHAR(20) NOT NULL CHECK (transaction_type IN
                                    ('cash_in','cash_out','transfer_in','transfer_out')),
    counter_bank_account_id        BIGINT REFERENCES bank_accounts(id),   -- for transfers
    amount                          NUMERIC(18,2) NOT NULL,
    currency_code                    CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate                     NUMERIC(18,6) NOT NULL DEFAULT 1,
    gl_offset_account_id               BIGINT REFERENCES accounts(id),
    description                         TEXT,
    status                               VARCHAR(20) NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','posted','void')),
    journal_id                           BIGINT REFERENCES gl_journals(id),
    created_by                            BIGINT,
    created_at                            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_cash_txn_bank_account ON cash_transactions(bank_account_id);

CREATE TABLE bank_statement_imports (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    bank_account_id              BIGINT NOT NULL REFERENCES bank_accounts(id),
    file_name                     VARCHAR(255),
    imported_by                    BIGINT,
    imported_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    period_start                     DATE,
    period_end                       DATE,
    status                            VARCHAR(20) NOT NULL DEFAULT 'processing'
                                    CHECK (status IN ('processing','completed','failed'))
);

CREATE TABLE bank_statement_lines (
    id                          BIGSERIAL PRIMARY KEY,
    import_id                    BIGINT NOT NULL REFERENCES bank_statement_imports(id) ON DELETE CASCADE,
    bank_account_id                BIGINT NOT NULL REFERENCES bank_accounts(id),
    line_date                       DATE NOT NULL,
    description                      TEXT,
    reference                         VARCHAR(100),
    amount                             NUMERIC(18,2) NOT NULL,             -- +inflow / -outflow
    is_matched                         BOOLEAN NOT NULL DEFAULT FALSE,
    matched_cash_transaction_id         BIGINT REFERENCES cash_transactions(id)
);
CREATE INDEX ix_bank_stmt_lines_account ON bank_statement_lines(bank_account_id, is_matched);

CREATE TABLE bank_reconciliations (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    bank_account_id              BIGINT NOT NULL REFERENCES bank_accounts(id),
    reconciliation_date           DATE NOT NULL,
    statement_ending_balance       NUMERIC(18,2) NOT NULL,
    book_ending_balance             NUMERIC(18,2) NOT NULL,
    status                           VARCHAR(20) NOT NULL DEFAULT 'in_progress'
                                    CHECK (status IN ('in_progress','completed')),
    completed_by                     BIGINT,
    completed_at                      TIMESTAMPTZ,
    created_at                         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE bank_reconciliation_matches (
    id                          BIGSERIAL PRIMARY KEY,
    reconciliation_id            BIGINT NOT NULL REFERENCES bank_reconciliations(id) ON DELETE CASCADE,
    statement_line_id             BIGINT NOT NULL REFERENCES bank_statement_lines(id),
    cash_transaction_id            BIGINT NOT NULL REFERENCES cash_transactions(id),
    match_type                      VARCHAR(10) NOT NULL DEFAULT 'manual' CHECK (match_type IN ('auto','manual')),
    matched_at                       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 6. FIXED ASSETS
-- ============================================================================

CREATE TABLE fa_assets (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    asset_no                    VARCHAR(30) NOT NULL,
    name                        VARCHAR(200) NOT NULL,
    asset_group_id               BIGINT NOT NULL REFERENCES asset_groups(id),
    acquisition_date              DATE NOT NULL,
    acquisition_cost               NUMERIC(18,2) NOT NULL,
    vendor_partner_id               BIGINT REFERENCES "CRM".partners(id),
    source_bill_id                   BIGINT REFERENCES ap_bills(id),
    commercial_nbv                    NUMERIC(18,2) NOT NULL,
    fiscal_nbv                         NUMERIC(18,2) NOT NULL,
    location                            VARCHAR(200),
    status                               VARCHAR(20) NOT NULL DEFAULT 'active'
                                    CHECK (status IN ('active','disposed','fully_depreciated')),
    gl_asset_account_id                  BIGINT NOT NULL REFERENCES accounts(id),
    gl_depreciation_expense_account_id    BIGINT NOT NULL REFERENCES accounts(id),
    gl_accum_depreciation_account_id       BIGINT NOT NULL REFERENCES accounts(id),
    created_by                              BIGINT,
    created_at                               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, asset_no)
);
CREATE INDEX ix_fa_assets_company_status ON fa_assets(company_id, status);

CREATE TABLE fa_depreciation_schedule_commercial (
    id                          BIGSERIAL PRIMARY KEY,
    asset_id                    BIGINT NOT NULL REFERENCES fa_assets(id) ON DELETE CASCADE,
    fiscal_period_id             BIGINT NOT NULL REFERENCES fiscal_periods(id),
    depreciation_amount           NUMERIC(18,2) NOT NULL,
    accumulated_amount             NUMERIC(18,2) NOT NULL,
    nbv_after                        NUMERIC(18,2) NOT NULL,
    journal_id                        BIGINT REFERENCES gl_journals(id),
    status                              VARCHAR(15) NOT NULL DEFAULT 'scheduled'
                                    CHECK (status IN ('scheduled','posted')),
    posted_at                            TIMESTAMPTZ,
    UNIQUE (asset_id, fiscal_period_id)
);

CREATE TABLE fa_depreciation_schedule_fiscal (
    id                          BIGSERIAL PRIMARY KEY,
    asset_id                    BIGINT NOT NULL REFERENCES fa_assets(id) ON DELETE CASCADE,
    fiscal_period_id             BIGINT NOT NULL REFERENCES fiscal_periods(id),
    depreciation_amount           NUMERIC(18,2) NOT NULL,
    accumulated_amount             NUMERIC(18,2) NOT NULL,
    nbv_after                        NUMERIC(18,2) NOT NULL,
    status                             VARCHAR(15) NOT NULL DEFAULT 'scheduled'
                                    CHECK (status IN ('scheduled','recognized')),
    UNIQUE (asset_id, fiscal_period_id)
);

CREATE TABLE fa_disposals (
    id                          BIGSERIAL PRIMARY KEY,
    asset_id                    BIGINT NOT NULL REFERENCES fa_assets(id),
    disposal_date                DATE NOT NULL,
    disposal_proceeds             NUMERIC(18,2) NOT NULL DEFAULT 0,
    commercial_nbv_at_disposal     NUMERIC(18,2) NOT NULL,
    commercial_gain_loss            NUMERIC(18,2) NOT NULL,
    fiscal_nbv_at_disposal            NUMERIC(18,2) NOT NULL,
    fiscal_gain_loss                   NUMERIC(18,2) NOT NULL,
    journal_id                          BIGINT REFERENCES gl_journals(id),
    disposal_method                      VARCHAR(20) NOT NULL CHECK (disposal_method IN ('sale','write_off','trade_in')),
    created_by                            BIGINT,
    created_at                             TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 7. INVENTORY COSTING (financial layer only — no physical stock)
-- ============================================================================

CREATE TABLE inv_items (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    item_code                   VARCHAR(30) NOT NULL,
    item_name                   VARCHAR(200) NOT NULL,
    costing_method               VARCHAR(20) NOT NULL DEFAULT 'weighted_average',
    current_avg_cost              NUMERIC(18,4) NOT NULL DEFAULT 0,
    on_hand_qty                    NUMERIC(18,3) NOT NULL DEFAULT 0,
    inventory_account_id            BIGINT NOT NULL REFERENCES accounts(id),
    cogs_account_id                  BIGINT NOT NULL REFERENCES accounts(id),
    is_active                         BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (company_id, item_code)
);

CREATE TABLE inv_movements (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    item_id                     BIGINT NOT NULL REFERENCES inv_items(id),
    movement_date                DATE NOT NULL,
    movement_type                 VARCHAR(20) NOT NULL CHECK (movement_type IN
                                    ('purchase_receipt','sale_issue','adjustment_in','adjustment_out')),
    qty                             NUMERIC(18,3) NOT NULL,
    unit_cost                        NUMERIC(18,4) NOT NULL,
    total_cost                        NUMERIC(18,2) NOT NULL,
    avg_cost_after                     NUMERIC(18,4) NOT NULL,
    subject_type                        VARCHAR(60),
    subject_id                           BIGINT,
    journal_id                            BIGINT REFERENCES gl_journals(id),
    created_by                             BIGINT,
    created_at                              TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_inv_movements_item ON inv_movements(item_id);

CREATE TABLE inv_valuation_snapshots (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    item_id                     BIGINT NOT NULL REFERENCES inv_items(id),
    snapshot_date                DATE NOT NULL,
    qty_on_hand                   NUMERIC(18,3) NOT NULL,
    avg_unit_cost                  NUMERIC(18,4) NOT NULL,
    total_value                     NUMERIC(18,2) NOT NULL,
    created_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, item_id, snapshot_date)
);

-- ============================================================================
-- 8. COST ACCOUNTING / BUDGETING
-- ============================================================================

CREATE TABLE cost_allocation_rules (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    name                        VARCHAR(150) NOT NULL,
    source_account_id            BIGINT NOT NULL REFERENCES accounts(id),
    source_cost_center_id         BIGINT REFERENCES cost_centers(id),
    is_active                      BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE cost_allocation_rule_targets (
    id                          BIGSERIAL PRIMARY KEY,
    rule_id                     BIGINT NOT NULL REFERENCES cost_allocation_rules(id) ON DELETE CASCADE,
    target_cost_center_id        BIGINT NOT NULL REFERENCES cost_centers(id),
    percentage                    NUMERIC(6,3) NOT NULL CHECK (percentage > 0 AND percentage <= 100)
);

CREATE TABLE cost_allocation_runs (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    rule_id                     BIGINT NOT NULL REFERENCES cost_allocation_rules(id),
    run_period_id                BIGINT NOT NULL REFERENCES fiscal_periods(id),
    total_amount                  NUMERIC(18,2) NOT NULL,
    journal_id                     BIGINT REFERENCES gl_journals(id),
    status                          VARCHAR(15) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted')),
    run_by                            BIGINT,
    run_at                              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE budgets (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    fiscal_year_id               BIGINT NOT NULL REFERENCES fiscal_years(id),
    name                        VARCHAR(150) NOT NULL,
    status                      VARCHAR(15) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','closed')),
    created_by                   BIGINT,
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, fiscal_year_id, name)
);

CREATE TABLE budget_lines (
    id                          BIGSERIAL PRIMARY KEY,
    budget_id                   BIGINT NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
    account_id                  BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id                BIGINT REFERENCES cost_centers(id),
    fiscal_period_id               BIGINT NOT NULL REFERENCES fiscal_periods(id),
    budget_amount                   NUMERIC(18,2) NOT NULL
);
CREATE INDEX ix_budget_lines_budget ON budget_lines(budget_id);

-- ============================================================================
-- 9. INDONESIAN TAX ENGINE
-- ============================================================================

CREATE TABLE tax_coretax_export_batches (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    batch_type                  VARCHAR(20) NOT NULL CHECK (batch_type IN
                                    ('faktur_keluaran','faktur_masukan','bukti_potong')),
    period_year                 SMALLINT NOT NULL,
    period_month                 SMALLINT NOT NULL CHECK (period_month BETWEEN 1 AND 12),
    file_path                     TEXT,
    record_count                   INT NOT NULL DEFAULT 0,
    generated_by                    BIGINT,
    generated_at                     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE tax_faktur_pajak (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    nomor_faktur                 VARCHAR(30) NOT NULL,        -- Nomor Seri Faktur Pajak
    faktur_type                  VARCHAR(10) NOT NULL CHECK (faktur_type IN ('output','input')),
    ar_invoice_id                 BIGINT REFERENCES ar_invoices(id),
    ap_bill_id                     BIGINT REFERENCES ap_bills(id),
    partner_id                      BIGINT NOT NULL REFERENCES "CRM".partners(id),
    partner_npwp                     VARCHAR(30),
    tax_base_amount                   NUMERIC(18,2) NOT NULL,
    ppn_amount                         NUMERIC(18,2) NOT NULL,
    faktur_date                         DATE NOT NULL,
    status                               VARCHAR(20) NOT NULL DEFAULT 'issued'
                                    CHECK (status IN ('issued','replaced','cancelled')),
    replaces_faktur_id                   BIGINT REFERENCES tax_faktur_pajak(id),
    coretax_export_batch_id               BIGINT REFERENCES tax_coretax_export_batches(id),
    created_by                             BIGINT,
    created_at                              TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, nomor_faktur)
);
CREATE INDEX ix_tax_faktur_partner ON tax_faktur_pajak(partner_id);

CREATE TABLE tax_bukti_potong (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    bukti_potong_no               VARCHAR(30) NOT NULL,
    form_type                      VARCHAR(10) NOT NULL,       -- BP21 / BP23 / BP4(2) / BPU / BP22 / BP15
    ap_bill_id                       BIGINT REFERENCES ap_bills(id),
    ap_payment_id                     BIGINT REFERENCES ap_payments(id),
    partner_id                         BIGINT NOT NULL REFERENCES "CRM".partners(id),
    partner_npwp                        VARCHAR(30),
    gross_amount                         NUMERIC(18,2) NOT NULL,
    tax_rate                              NUMERIC(6,3) NOT NULL,
    withheld_amount                        NUMERIC(18,2) NOT NULL,
    is_final                                BOOLEAN NOT NULL DEFAULT FALSE,
    bukti_potong_date                        DATE NOT NULL,
    status                                    VARCHAR(20) NOT NULL DEFAULT 'issued'
                                    CHECK (status IN ('issued','replaced','cancelled')),
    replaces_bukti_potong_id                  BIGINT REFERENCES tax_bukti_potong(id),
    coretax_export_batch_id                    BIGINT REFERENCES tax_coretax_export_batches(id),
    created_by                                  BIGINT,
    created_at                                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, bukti_potong_no)
);
CREATE INDEX ix_tax_bupot_partner ON tax_bukti_potong(partner_id);

CREATE TABLE tax_periods (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    obligation_type              VARCHAR(20) NOT NULL CHECK (obligation_type IN
                                    ('ppn_masa','pph_unifikasi','pph_badan_tahunan')),
    period_year                   SMALLINT NOT NULL,
    period_month                    SMALLINT CHECK (period_month BETWEEN 1 AND 12),  -- NULL for annual obligation
    due_date                          DATE NOT NULL,
    filing_status                       VARCHAR(15) NOT NULL DEFAULT 'open'
                                    CHECK (filing_status IN ('open','filed','late')),
    filed_at                             TIMESTAMPTZ,
    UNIQUE (company_id, obligation_type, period_year, period_month)
);

-- Deferred FKs from AR/AP into tax documents (tables created after their referents above)
ALTER TABLE ar_invoices
    ADD CONSTRAINT fk_ar_invoices_faktur FOREIGN KEY (faktur_pajak_id) REFERENCES tax_faktur_pajak(id);
ALTER TABLE ap_bills
    ADD CONSTRAINT fk_ap_bills_bupot FOREIGN KEY (bukti_potong_id) REFERENCES tax_bukti_potong(id);

-- ============================================================================
-- 10. RECURRING TRANSACTIONS
-- ============================================================================

CREATE TABLE recurring_journal_templates (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    name                        VARCHAR(150) NOT NULL,
    recurrence_rule              VARCHAR(200) NOT NULL,        -- RRULE string, simshaun/recurr
    next_run_date                 DATE NOT NULL,
    currency_code                  CHAR(3) NOT NULL REFERENCES currencies(code),
    is_active                       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by                       BIGINT,
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE recurring_journal_template_lines (
    id                          BIGSERIAL PRIMARY KEY,
    template_id                  BIGINT NOT NULL REFERENCES recurring_journal_templates(id) ON DELETE CASCADE,
    account_id                    BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id                 BIGINT REFERENCES cost_centers(id),
    description                     TEXT,
    debit_amount                     NUMERIC(18,2) NOT NULL DEFAULT 0,
    credit_amount                     NUMERIC(18,2) NOT NULL DEFAULT 0
);

CREATE TABLE recurring_ar_templates (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    name                        VARCHAR(150) NOT NULL,
    partner_id                  BIGINT NOT NULL REFERENCES "CRM".partners(id),
    recurrence_rule               VARCHAR(200) NOT NULL,
    next_run_date                  DATE NOT NULL,
    currency_code                   CHAR(3) NOT NULL REFERENCES currencies(code),
    description                      TEXT,
    amount                             NUMERIC(18,2) NOT NULL,
    revenue_account_id                  BIGINT NOT NULL REFERENCES accounts(id),
    tax_code_id                          BIGINT REFERENCES tax_codes(id),
    is_active                             BOOLEAN NOT NULL DEFAULT TRUE,
    created_by                             BIGINT,
    created_at                              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE recurring_ap_templates (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    name                        VARCHAR(150) NOT NULL,
    partner_id                  BIGINT NOT NULL REFERENCES "CRM".partners(id),
    recurrence_rule               VARCHAR(200) NOT NULL,
    next_run_date                  DATE NOT NULL,
    currency_code                   CHAR(3) NOT NULL REFERENCES currencies(code),
    description                      TEXT,
    amount                             NUMERIC(18,2) NOT NULL,
    expense_account_id                  BIGINT NOT NULL REFERENCES accounts(id),
    withholding_type_id                  BIGINT REFERENCES withholding_types(id),
    is_active                             BOOLEAN NOT NULL DEFAULT TRUE,
    created_by                             BIGINT,
    created_at                              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE recurring_generation_log (
    id                          BIGSERIAL PRIMARY KEY,
    template_type               VARCHAR(20) NOT NULL CHECK (template_type IN ('journal','ar','ap')),
    template_id                 BIGINT NOT NULL,
    generated_document_type      VARCHAR(30) NOT NULL,
    generated_document_id         BIGINT NOT NULL,
    generated_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    status                            VARCHAR(15) NOT NULL DEFAULT 'draft_created'
                                    CHECK (status IN ('draft_created','review_pending','skipped'))
);

-- ============================================================================
-- 11. AUDIT & COMPLIANCE
-- ============================================================================

CREATE TABLE audit_logs (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT REFERENCES companies(id),
    action_type                 VARCHAR(40) NOT NULL CHECK (action_type IN
                                    ('journal_created','journal_posted','journal_reversed',
                                     'period_closed','period_reopened',
                                     'invoice_posted','bill_posted','payment_posted',
                                     'tax_document_issued','tax_document_cancelled',
                                     'master_data_changed')),
    actor_id                     BIGINT,
    actor_name                    VARCHAR(150),
    record_type                    VARCHAR(60) NOT NULL,
    record_id                       BIGINT NOT NULL,
    before_snapshot                  JSONB,
    after_snapshot                    JSONB,
    ip_address                         INET,
    created_at                          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_audit_logs_record ON audit_logs(record_type, record_id);
CREATE INDEX ix_audit_logs_company ON audit_logs(company_id);

-- Audit integrity: no UPDATE/DELETE on posted journals or audit_logs at the app layer.
-- Enforce via the app's DB role grants in deployment (REVOKE UPDATE, DELETE ON
-- "ACCOUNTING".audit_logs FROM app_role; similarly restrict gl_journals/tax documents
-- to INSERT + status-transition UPDATE only) — left as a deployment-time grant, not
-- baked into this DDL, so local dev roles aren't blocked from fixture resets.

COMMIT;
