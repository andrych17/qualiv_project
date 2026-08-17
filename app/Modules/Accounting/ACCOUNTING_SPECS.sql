-- =============================================================================
-- Accounting module — schema + example seed data
-- Source spec: app/Modules/Accounting/ACCOUNTING_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "ACCOUNTING" — every
--              table lives inside one tenant's own database (CLAUDE.md §4/§7A).
--
-- Two axes, both correct at once (§5 "On company_id vs. CLAUDE.md's no-tenant_id
-- rule"): DB-per-tenant is the CROSS-TENANT boundary (no tenant_id column, ever).
-- `company_id` is an INTRA-tenant axis — one tenant DB can legitimately hold two
-- legal entities (e.g. a law firm's operating company + a client-trust entity)
-- whose ledgers must never mix. Every transactional/setup table below therefore
-- carries `company_id`; the two exceptions (`currencies`, and the polymorphic
-- `recurring_generation_log`) are noted inline with why.
--
-- Non-negotiable MVP rule (§2): the Indonesian tax engine (§3M — tax_codes,
-- withholding_types, Faktur Pajak/Bukti Potong, Coretax export) is built in the
-- SAME pass as COA/GL and AR/AP, not bolted on later — AR/AP tables below already
-- carry tax fields from the start, matching that constraint.
--
-- Cross-schema FK (§5): `ar_invoices`/`ap_bills`/etc. FK directly into
-- "CRM".partners(id) — Core-to-Core FK is allowed (CLAUDE.md §2), unlike Core-to-
-- Vertical. This assumes CRM's own migrations have already run (CLAUDE.md §5
-- build order puts CRM before Accounting). `INVENTORY.products` /
-- `PAYROLL.payroll_components` references (§3H/§3S) are deliberately NOT hard FKs
-- — both are optional per-tenant modules and these posting engines must stay
-- inert, never fail to migrate, if one is absent (§3H/§3S "must not throw if X is
-- absent").
--
-- This file is a reference/planning script, not a Laravel migration — port each
-- section below into `database/migrations/tenant/*.php` per the suggested build
-- order in §5 (COA/GL -> tax engine -> AR/AP -> multi-company/currency -> Cash &
-- Banks -> Fixed Assets -> Reporting -> Audit -> Recurring -> Cost/Budget ->
-- Inventory GL posting -> ERP automation -> Payroll GL posting).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"CUSTOMFIELDS"/"AIINSIGHT" — unquoted
-- CREATE SCHEMA folds to lowercase, but every table below is referenced quoted.
CREATE SCHEMA IF NOT EXISTS "ACCOUNTING";

-- -----------------------------------------------------------------------------
-- §3K / §4. Setup — Companies (multi-company master)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "ACCOUNTING".companies (
    id                          BIGSERIAL PRIMARY KEY,
    legal_name                  VARCHAR(255) NOT NULL,
    npwp                        VARCHAR(30),                -- Indonesian tax ID
    address                     TEXT,
    base_currency               CHAR(3) NOT NULL DEFAULT 'IDR', -- fixed at setup (§3L)
    fiscal_year_start_month     SMALLINT NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
    coa_template_code           VARCHAR(50),                 -- which starter COA template it diverged from
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3B / §4. Chart of Accounts
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "ACCOUNTING".accounts (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    account_code        VARCHAR(20) NOT NULL,                -- 1xxxx Aset, 2xxxx Liabilitas, 3xxxx Ekuitas, 4xxxx Pendapatan, 5xxxx HPP, 6xxxx Beban
    account_name        VARCHAR(150) NOT NULL,
    account_type        VARCHAR(10) NOT NULL CHECK (account_type IN ('asset', 'liability', 'equity', 'revenue', 'cogs', 'expense')),
    normal_balance       VARCHAR(6) NOT NULL CHECK (normal_balance IN ('debit', 'credit')),
    parent_account_id   BIGINT REFERENCES "ACCOUNTING".accounts (id),
    is_control_account  BOOLEAN NOT NULL DEFAULT FALSE,      -- AR/AP/Inventory control accounts: no direct manual journal lines (§3B rule)
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, account_code)
);

CREATE INDEX IF NOT EXISTS idx_acct_accounts_company ON "ACCOUNTING".accounts (company_id, is_active);

-- -----------------------------------------------------------------------------
-- §3B / §4. Fiscal calendar
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "ACCOUNTING".fiscal_years (
    id              BIGSERIAL PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    year            SMALLINT NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    status          VARCHAR(15) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'soft_closed', 'hard_closed')),
    UNIQUE (company_id, year)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".fiscal_periods (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    fiscal_year_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".fiscal_years (id),
    period_no           SMALLINT NOT NULL CHECK (period_no BETWEEN 1 AND 12),
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    status              VARCHAR(15) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'soft_closed', 'hard_closed')),
    UNIQUE (company_id, fiscal_year_id, period_no)
);

CREATE INDEX IF NOT EXISTS idx_acct_fiscal_periods_company ON "ACCOUNTING".fiscal_periods (company_id, status);

-- -----------------------------------------------------------------------------
-- §3B / §3I / §4. Cost centers — the platform's canonical financial dimension
-- (Purchase's cost_centers and HCM's org_units optionally map into these; see
-- PURCHASE_SPECS.md §5 / HCM_SPECS.md §5)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "ACCOUNTING".cost_centers (
    id                      BIGSERIAL PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    code                    VARCHAR(30) NOT NULL,
    name                    VARCHAR(150) NOT NULL,
    parent_cost_center_id   BIGINT REFERENCES "ACCOUNTING".cost_centers (id), -- one-level hierarchy in v1
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (company_id, code)
);

-- -----------------------------------------------------------------------------
-- §3L / §4. Currencies & exchange rates
-- Note: `currencies` is deliberately tenant-wide, NOT company_id-scoped — it is a
-- reference/lookup list (which ISO currencies this tenant has turned on), not a
-- ledger; the company-mixing risk §3K's company_id rule guards against doesn't
-- apply to it. `exchange_rates` IS company-scoped because "rate to base currency"
-- (§3L) is meaningless without knowing which company's base currency.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "ACCOUNTING".currencies (
    code            CHAR(3) PRIMARY KEY,                     -- ISO 4217, e.g. IDR, USD
    name            VARCHAR(50) NOT NULL,
    is_enabled      BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".exchange_rates (
    id              BIGSERIAL PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    currency_code   CHAR(3) NOT NULL REFERENCES "ACCOUNTING".currencies (code),
    rate_date       DATE NOT NULL,
    rate_to_base    NUMERIC(18, 6) NOT NULL,
    source          VARCHAR(15) NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'rate_feed')),
    UNIQUE (company_id, currency_code, rate_date)
);

-- -----------------------------------------------------------------------------
-- §3M / §4. Indonesian tax engine — lookups (built alongside COA/GL, §2 MVP rule)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "ACCOUNTING".tax_codes (
    id              BIGSERIAL PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    code            VARCHAR(20) NOT NULL,                    -- e.g. PPN11
    rate            NUMERIC(5, 2) NOT NULL,
    tax_type        VARCHAR(10) NOT NULL CHECK (tax_type IN ('output', 'input')),
    gl_account_id   BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (company_id, code)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".withholding_types (
    id                      BIGSERIAL PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    code                    VARCHAR(20) NOT NULL,            -- PPh23, PPh4A2, PPh21, PPh22, PPh15
    name                    VARCHAR(100) NOT NULL,
    rate                    NUMERIC(5, 2) NOT NULL,
    is_final                BOOLEAN NOT NULL DEFAULT FALSE,
    gl_payable_account_id   BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (company_id, code)
);

-- -----------------------------------------------------------------------------
-- §3G / §4. Fixed asset groups (Indonesian fiscal depreciation classification)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "ACCOUNTING".asset_groups (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    code                        VARCHAR(20) NOT NULL,        -- Kelompok1..4, BangunanPermanen, BangunanNonPermanen
    name                        VARCHAR(100) NOT NULL,
    fiscal_method                VARCHAR(20) NOT NULL DEFAULT 'straight_line' CHECK (fiscal_method IN ('straight_line', 'declining_balance')),
    fiscal_rate                  NUMERIC(5, 2) NOT NULL,       -- current PMK-prescribed rate; tenant-editable, never hardcoded (§3G rule)
    fiscal_useful_life_years     SMALLINT NOT NULL,
    UNIQUE (company_id, code)
);

-- -----------------------------------------------------------------------------
-- §3F / §4. Cash & bank master
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "ACCOUNTING".bank_accounts (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    bank_name           VARCHAR(100) NOT NULL,
    account_number      VARCHAR(50) NOT NULL,                -- masked in UI except last 4 digits
    currency_code       CHAR(3) NOT NULL REFERENCES "ACCOUNTING".currencies (code),
    gl_account_id       BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

COMMIT;

-- =============================================================================
-- §3C / §4. General Ledger — the one posting path everything else goes through
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".gl_journals (
    id                      BIGSERIAL PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    fiscal_period_id        BIGINT NOT NULL REFERENCES "ACCOUNTING".fiscal_periods (id),
    journal_date            DATE NOT NULL,
    currency_code           CHAR(3) NOT NULL REFERENCES "ACCOUNTING".currencies (code),
    memo                    VARCHAR(255),
    source                  VARCHAR(20) NOT NULL DEFAULT 'manual'
                                CHECK (source IN ('manual', 'ar', 'ap', 'asset', 'inventory', 'payroll', 'recurring', 'allocation')),
    status                  VARCHAR(20) NOT NULL DEFAULT 'draft'
                                CHECK (status IN ('draft', 'pending_approval', 'posted', 'reversed')),
    subject_type            VARCHAR(100),                     -- polymorphic pointer back to whatever triggered this (§3C)
    subject_id              VARCHAR(100),
    reversed_journal_id     BIGINT REFERENCES "ACCOUNTING".gl_journals (id), -- set on the reversing entry, points to the original
    created_by              BIGINT REFERENCES users (id),
    posted_by               BIGINT REFERENCES users (id),
    posted_at               TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
    -- "Σdebit = Σcredit before leaving draft" (§3C rule) is enforced by
    -- JournalService::post() at the app layer, not a DB constraint — a draft
    -- journal is allowed to be transiently unbalanced while a user is editing it.
);

CREATE INDEX IF NOT EXISTS idx_acct_gl_journals_company ON "ACCOUNTING".gl_journals (company_id, fiscal_period_id, status);
CREATE INDEX IF NOT EXISTS idx_acct_gl_journals_subject ON "ACCOUNTING".gl_journals (subject_type, subject_id);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".gl_journal_lines (
    id                  BIGSERIAL PRIMARY KEY,
    journal_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".gl_journals (id) ON DELETE CASCADE,
    line_no             SMALLINT NOT NULL,
    account_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    cost_center_id      BIGINT REFERENCES "ACCOUNTING".cost_centers (id),
    debit               NUMERIC(18, 2) NOT NULL DEFAULT 0,    -- always base-currency amount
    credit              NUMERIC(18, 2) NOT NULL DEFAULT 0,
    fx_currency_code    CHAR(3) REFERENCES "ACCOUNTING".currencies (code), -- set only for a foreign-currency line (§3L)
    fx_amount           NUMERIC(18, 2),
    fx_rate             NUMERIC(18, 6),
    description         VARCHAR(255),
    CHECK (debit >= 0 AND credit >= 0 AND (debit > 0 OR credit > 0) AND NOT (debit > 0 AND credit > 0))
);

CREATE INDEX IF NOT EXISTS idx_acct_gl_journal_lines_journal ON "ACCOUNTING".gl_journal_lines (journal_id);
CREATE INDEX IF NOT EXISTS idx_acct_gl_journal_lines_account ON "ACCOUNTING".gl_journal_lines (account_id);

COMMIT;

-- =============================================================================
-- §3D / §4. Accounts Receivable
-- Partner FK is cross-schema Core-to-Core (§5) — assumes "CRM".partners exists.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ar_invoices (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id),
    invoice_no          VARCHAR(30) NOT NULL,
    invoice_type        VARCHAR(10) NOT NULL DEFAULT 'standard' CHECK (invoice_type IN ('standard', 'deposit')), -- never 'credit_memo' (§3D rule)
    currency_code       CHAR(3) NOT NULL REFERENCES "ACCOUNTING".currencies (code),
    issue_date          DATE NOT NULL,
    due_date            DATE NOT NULL,
    subject_type        VARCHAR(100),                         -- in practice always 'sales.so_lines' (§3D — Sales is the sole AR-side caller)
    subject_id          VARCHAR(100),
    status               VARCHAR(20) NOT NULL DEFAULT 'draft'
                            CHECK (status IN ('draft', 'posted', 'partially_paid', 'paid', 'void')),
    subtotal            NUMERIC(18, 2) NOT NULL DEFAULT 0,
    tax_amount          NUMERIC(18, 2) NOT NULL DEFAULT 0,
    total_amount        NUMERIC(18, 2) NOT NULL DEFAULT 0,
    paid_amount         NUMERIC(18, 2) NOT NULL DEFAULT 0,
    journal_id          BIGINT REFERENCES "ACCOUNTING".gl_journals (id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, invoice_no)
);

CREATE INDEX IF NOT EXISTS idx_acct_ar_invoices_partner ON "ACCOUNTING".ar_invoices (company_id, partner_id, status);
CREATE INDEX IF NOT EXISTS idx_acct_ar_invoices_subject ON "ACCOUNTING".ar_invoices (subject_type, subject_id);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ar_invoice_lines (
    id                      BIGSERIAL PRIMARY KEY,
    invoice_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".ar_invoices (id) ON DELETE CASCADE,
    description              VARCHAR(255) NOT NULL,
    qty                     NUMERIC(14, 4) NOT NULL DEFAULT 1,
    unit_price               NUMERIC(18, 2) NOT NULL,
    discount_amount           NUMERIC(18, 2) NOT NULL DEFAULT 0,
    tax_code_id               BIGINT REFERENCES "ACCOUNTING".tax_codes (id),
    line_amount               NUMERIC(18, 2) NOT NULL,
    revenue_account_id        BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ar_payments (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id),
    bank_account_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".bank_accounts (id),
    payment_date        DATE NOT NULL,
    amount               NUMERIC(18, 2) NOT NULL,
    currency_code        CHAR(3) NOT NULL REFERENCES "ACCOUNTING".currencies (code),
    journal_id           BIGINT REFERENCES "ACCOUNTING".gl_journals (id),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ar_payment_applications (
    id                  BIGSERIAL PRIMARY KEY,
    ar_payment_id       BIGINT NOT NULL REFERENCES "ACCOUNTING".ar_payments (id) ON DELETE CASCADE,
    ar_invoice_id       BIGINT NOT NULL REFERENCES "ACCOUNTING".ar_invoices (id),
    applied_amount      NUMERIC(18, 2) NOT NULL
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ar_credit_notes (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id),
    ar_invoice_id        BIGINT REFERENCES "ACCOUNTING".ar_invoices (id), -- nullable: can stand alone against a partner balance
    credit_note_no        VARCHAR(30) NOT NULL,
    amount                NUMERIC(18, 2) NOT NULL,
    reason                VARCHAR(255),
    status                VARCHAR(15) NOT NULL DEFAULT 'posted' CHECK (status IN ('draft', 'posted', 'void')),
    journal_id            BIGINT REFERENCES "ACCOUNTING".gl_journals (id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, credit_note_no)
);

COMMIT;

-- =============================================================================
-- §3E / §4. Accounts Payable — mirrors AR structurally
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ap_bills (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id), -- the vendor
    bill_no             VARCHAR(30) NOT NULL,
    currency_code       CHAR(3) NOT NULL REFERENCES "ACCOUNTING".currencies (code),
    bill_date           DATE NOT NULL,
    due_date            DATE NOT NULL,
    subject_type        VARCHAR(100),                         -- most commonly 'purchase.pur_invoice_hdrs' (§3E)
    subject_id          VARCHAR(100),
    status               VARCHAR(20) NOT NULL DEFAULT 'draft'
                            CHECK (status IN ('draft', 'posted', 'partially_paid', 'paid', 'void')),
    subtotal              NUMERIC(18, 2) NOT NULL DEFAULT 0,
    withholding_amount    NUMERIC(18, 2) NOT NULL DEFAULT 0,   -- reduces amount paid, not the recognized expense (§3E rule)
    total_amount           NUMERIC(18, 2) NOT NULL DEFAULT 0,
    paid_amount            NUMERIC(18, 2) NOT NULL DEFAULT 0,
    journal_id              BIGINT REFERENCES "ACCOUNTING".gl_journals (id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, bill_no)
);

CREATE INDEX IF NOT EXISTS idx_acct_ap_bills_partner ON "ACCOUNTING".ap_bills (company_id, partner_id, status);
CREATE INDEX IF NOT EXISTS idx_acct_ap_bills_subject ON "ACCOUNTING".ap_bills (subject_type, subject_id);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ap_bill_lines (
    id                      BIGSERIAL PRIMARY KEY,
    bill_id                 BIGINT NOT NULL REFERENCES "ACCOUNTING".ap_bills (id) ON DELETE CASCADE,
    description               VARCHAR(255) NOT NULL,
    qty                       NUMERIC(14, 4) NOT NULL DEFAULT 1,
    unit_price                 NUMERIC(18, 2) NOT NULL,
    line_amount                 NUMERIC(18, 2) NOT NULL,
    withholding_type_id          BIGINT REFERENCES "ACCOUNTING".withholding_types (id),
    expense_account_id           BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ap_payments (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id),
    bank_account_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".bank_accounts (id),
    payment_run_uuid      UUID,                                -- groups a batch payment run (§3E); NULL for a single ad hoc payment
    payment_date          DATE NOT NULL,
    amount                 NUMERIC(18, 2) NOT NULL,
    currency_code           CHAR(3) NOT NULL REFERENCES "ACCOUNTING".currencies (code),
    status                   VARCHAR(20) NOT NULL DEFAULT 'pending_approval'
                                CHECK (status IN ('pending_approval', 'approved', 'paid', 'rejected')), -- §3E WNE approval gate
    journal_id                BIGINT REFERENCES "ACCOUNTING".gl_journals (id),
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ap_payment_applications (
    id                  BIGSERIAL PRIMARY KEY,
    ap_payment_id       BIGINT NOT NULL REFERENCES "ACCOUNTING".ap_payments (id) ON DELETE CASCADE,
    ap_bill_id           BIGINT NOT NULL REFERENCES "ACCOUNTING".ap_bills (id),
    applied_amount        NUMERIC(18, 2) NOT NULL
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".ap_debit_notes (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id),
    ap_bill_id            BIGINT REFERENCES "ACCOUNTING".ap_bills (id),
    debit_note_no          VARCHAR(30) NOT NULL,
    amount                  NUMERIC(18, 2) NOT NULL,
    reason                  VARCHAR(255),
    status                   VARCHAR(15) NOT NULL DEFAULT 'posted' CHECK (status IN ('draft', 'posted', 'void')),
    journal_id                BIGINT REFERENCES "ACCOUNTING".gl_journals (id),
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, debit_note_no)
);

COMMIT;

-- =============================================================================
-- §3F / §3Q / §4. Cash & Banks + Reconcile
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".cash_transactions (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    bank_account_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".bank_accounts (id),
    transaction_date      DATE NOT NULL,
    direction               VARCHAR(3) NOT NULL CHECK (direction IN ('in', 'out')),
    amount                   NUMERIC(18, 2) NOT NULL,
    currency_code             CHAR(3) NOT NULL REFERENCES "ACCOUNTING".currencies (code),
    description               VARCHAR(255),
    transfer_pair_id            BIGINT REFERENCES "ACCOUNTING".cash_transactions (id), -- links the two legs of an inter-account transfer
    journal_id                  BIGINT REFERENCES "ACCOUNTING".gl_journals (id),
    created_at                   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_acct_cash_transactions_bank_account ON "ACCOUNTING".cash_transactions (bank_account_id, transaction_date);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".bank_statement_imports (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    bank_account_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".bank_accounts (id),
    file_object_key       VARCHAR(500) NOT NULL,               -- R2 reference, see §4 object storage layout
    status                 VARCHAR(15) NOT NULL DEFAULT 'processing' CHECK (status IN ('processing', 'processed', 'failed')),
    line_count              INTEGER NOT NULL DEFAULT 0,
    imported_by               BIGINT REFERENCES users (id),
    imported_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".bank_statement_lines (
    id                          BIGSERIAL PRIMARY KEY,
    bank_statement_import_id    BIGINT NOT NULL REFERENCES "ACCOUNTING".bank_statement_imports (id) ON DELETE CASCADE,
    line_date                    DATE NOT NULL,
    description                    VARCHAR(255),
    amount                          NUMERIC(18, 2) NOT NULL,
    reference                        VARCHAR(100),
    match_status                      VARCHAR(15) NOT NULL DEFAULT 'unmatched' CHECK (match_status IN ('unmatched', 'matched', 'ignored'))
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".bank_reconciliations (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    bank_account_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".bank_accounts (id),
    reconciliation_date    DATE NOT NULL,
    statement_ending_balance NUMERIC(18, 2) NOT NULL,
    book_balance             NUMERIC(18, 2) NOT NULL,
    status                     VARCHAR(15) NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress', 'completed')),
    completed_by                BIGINT REFERENCES users (id),
    completed_at                 TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".bank_reconciliation_matches (
    id                          BIGSERIAL PRIMARY KEY,
    bank_reconciliation_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".bank_reconciliations (id) ON DELETE CASCADE,
    bank_statement_line_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".bank_statement_lines (id),
    cash_transaction_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".cash_transactions (id),
    match_method                   VARCHAR(10) NOT NULL DEFAULT 'manual' CHECK (match_method IN ('auto', 'manual'))
);

COMMIT;

-- =============================================================================
-- §3G / §4. Fixed Assets
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".fa_assets (
    id                              BIGSERIAL PRIMARY KEY,
    uuid                            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id                      BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    asset_code                       VARCHAR(30) NOT NULL,
    name                              VARCHAR(150) NOT NULL,
    acquisition_date                   DATE NOT NULL,
    acquisition_cost                     NUMERIC(18, 2) NOT NULL,
    vendor_partner_id                     BIGINT REFERENCES "CRM".partners (id),
    asset_group_id                         BIGINT NOT NULL REFERENCES "ACCOUNTING".asset_groups (id),
    useful_life_years                        SMALLINT NOT NULL,
    commercial_method                          VARCHAR(20) NOT NULL DEFAULT 'straight_line' CHECK (commercial_method IN ('straight_line', 'declining_balance')),
    subject_type                                 VARCHAR(100),  -- optional link back to the AP bill that created it (§3G, no hard FK)
    subject_id                                    VARCHAR(100),
    status                                          VARCHAR(15) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disposed')),
    gl_asset_account_id                              BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    gl_accum_depreciation_account_id                  BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    gl_depreciation_expense_account_id                 BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    created_at                                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                           TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, asset_code)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".fa_depreciation_schedule_commercial (
    id                          BIGSERIAL PRIMARY KEY,
    asset_id                    BIGINT NOT NULL REFERENCES "ACCOUNTING".fa_assets (id) ON DELETE CASCADE,
    fiscal_period_id             BIGINT NOT NULL REFERENCES "ACCOUNTING".fiscal_periods (id),
    depreciation_amount           NUMERIC(18, 2) NOT NULL,
    accumulated_depreciation       NUMERIC(18, 2) NOT NULL,
    net_book_value                   NUMERIC(18, 2) NOT NULL,
    journal_id                        BIGINT REFERENCES "ACCOUNTING".gl_journals (id), -- posted to the commercial GL (§3G)
    posted_at                          TIMESTAMPTZ,
    UNIQUE (asset_id, fiscal_period_id)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".fa_depreciation_schedule_fiscal (
    id                          BIGSERIAL PRIMARY KEY,
    asset_id                    BIGINT NOT NULL REFERENCES "ACCOUNTING".fa_assets (id) ON DELETE CASCADE,
    fiscal_period_id             BIGINT NOT NULL REFERENCES "ACCOUNTING".fiscal_periods (id),
    depreciation_amount           NUMERIC(18, 2) NOT NULL,
    accumulated_depreciation       NUMERIC(18, 2) NOT NULL,
    net_book_value                   NUMERIC(18, 2) NOT NULL,
    -- No journal_id: tracked for tax/SPT reconciliation only, never separately
    -- posted to the commercial GL (§3G — dual-book, not dual-ledger).
    UNIQUE (asset_id, fiscal_period_id)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".fa_disposals (
    id                          BIGSERIAL PRIMARY KEY,
    asset_id                    BIGINT NOT NULL REFERENCES "ACCOUNTING".fa_assets (id),
    disposal_date                 DATE NOT NULL,
    disposal_type                   VARCHAR(10) NOT NULL CHECK (disposal_type IN ('sale', 'write_off')),
    proceeds_amount                   NUMERIC(18, 2) NOT NULL DEFAULT 0,
    commercial_nbv_at_disposal          NUMERIC(18, 2) NOT NULL,
    fiscal_nbv_at_disposal                NUMERIC(18, 2) NOT NULL,
    gain_loss_amount                        NUMERIC(18, 2) NOT NULL,
    journal_id                                BIGINT REFERENCES "ACCOUNTING".gl_journals (id)
);

COMMIT;

-- =============================================================================
-- §3H / §3S / §4. Inventory GL Posting & Payroll GL Posting — thin, inert-if-
-- absent interface engines. product/category/component ids are deliberately
-- plain BIGINT, not FKs, per the file header note.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".inv_gl_account_map (
    id                          BIGSERIAL PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    product_id                   BIGINT,                       -- soft reference to INVENTORY.products
    product_category_id           BIGINT,                      -- soft reference to INVENTORY.product_categories
    inventory_asset_account_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    cogs_account_id                   BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    grni_account_id                     BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    adjustment_account_id                 BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    CHECK (product_id IS NOT NULL OR product_category_id IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".inv_posting_log (
    id              BIGSERIAL PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    event_type      VARCHAR(20) NOT NULL CHECK (event_type IN ('goods_received', 'goods_issued', 'stock_adjusted')),
    subject_type    VARCHAR(100) NOT NULL DEFAULT 'inventory.stock_ledger',
    subject_id      VARCHAR(100) NOT NULL,
    journal_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".gl_journals (id),
    processed_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (subject_type, subject_id)                          -- idempotency: one journal per Inventory event
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".payroll_gl_account_map (
    id                      BIGSERIAL PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    payroll_component_id     BIGINT,                           -- soft reference to PAYROLL.payroll_components
    statutory_category         VARCHAR(30),                    -- 'net_pay_payable' | 'pph21_payable' | 'bpjs_payable', when not a component
    gl_account_id                BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    account_role                   VARCHAR(10) NOT NULL CHECK (account_role IN ('expense', 'payable')),
    CHECK (payroll_component_id IS NOT NULL OR statutory_category IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".payroll_posting_log (
    id              BIGSERIAL PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    subject_type    VARCHAR(100) NOT NULL DEFAULT 'payroll.payroll_runs',
    subject_id      VARCHAR(100) NOT NULL,
    journal_id      BIGINT NOT NULL REFERENCES "ACCOUNTING".gl_journals (id),
    processed_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (subject_type, subject_id)
);

COMMIT;

-- =============================================================================
-- §3I / §3J / §4. Cost Accounting & Budgeting
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".cost_allocation_rules (
    id                      BIGSERIAL PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    name                    VARCHAR(150) NOT NULL,
    source_account_id        BIGINT REFERENCES "ACCOUNTING".accounts (id),
    source_cost_center_id      BIGINT REFERENCES "ACCOUNTING".cost_centers (id),
    -- [{"cost_center_id": n, "percentage": 100.0}, ...]; percentages summing to
    -- 100 is app-enforced, not a DB constraint (JSONB array, not easily CHECKed).
    target_splits                JSONB NOT NULL,
    is_active                      BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".cost_allocation_runs (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    rule_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".cost_allocation_rules (id),
    fiscal_period_id       BIGINT NOT NULL REFERENCES "ACCOUNTING".fiscal_periods (id),
    journal_id                BIGINT REFERENCES "ACCOUNTING".gl_journals (id), -- posts a journal, never mutates the original entry (§3I)
    run_date                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (rule_id, fiscal_period_id)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".budgets (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    fiscal_year_id        BIGINT NOT NULL REFERENCES "ACCOUNTING".fiscal_years (id),
    name                    VARCHAR(100) NOT NULL DEFAULT 'Annual Budget', -- one flat version per fiscal year in v1 (§3J rule)
    status                    VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active')),
    UNIQUE (company_id, fiscal_year_id)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".budget_lines (
    id                  BIGSERIAL PRIMARY KEY,
    budget_id           BIGINT NOT NULL REFERENCES "ACCOUNTING".budgets (id) ON DELETE CASCADE,
    account_id            BIGINT NOT NULL REFERENCES "ACCOUNTING".accounts (id),
    cost_center_id          BIGINT REFERENCES "ACCOUNTING".cost_centers (id), -- NULL = whole-company line
    fiscal_period_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".fiscal_periods (id),
    amount                      NUMERIC(18, 2) NOT NULL DEFAULT 0,
    -- Same NULLS-NOT-DISTINCT caveat as CENTRAL_SPECS.sql/AIINSIGHT_SPECS.sql:
    -- Postgres treats NULL cost_center_id as distinct, so this only fully guards
    -- rows that DO specify a cost center; enforce "one whole-company line per
    -- account/period" in the service layer.
    UNIQUE (budget_id, account_id, cost_center_id, fiscal_period_id)
);

COMMIT;

-- =============================================================================
-- §3M / §4. Indonesian Tax — Faktur Pajak, Bukti Potong, tax periods, Coretax
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".tax_faktur_pajak (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    direction                 VARCHAR(10) NOT NULL CHECK (direction IN ('output', 'input')), -- Keluaran / Masukan
    nomor_seri_faktur           VARCHAR(30) NOT NULL,          -- from the tenant's own DJP allocation block
    ar_invoice_id                 BIGINT REFERENCES "ACCOUNTING".ar_invoices (id), -- set when direction = output
    ap_bill_id                      BIGINT REFERENCES "ACCOUNTING".ap_bills (id),  -- set when direction = input
    partner_id                        BIGINT NOT NULL REFERENCES "CRM".partners (id),
    buyer_npwp_nik                      VARCHAR(30),
    tax_base                              NUMERIC(18, 2) NOT NULL,
    ppn_amount                              NUMERIC(18, 2) NOT NULL,
    status                                    VARCHAR(15) NOT NULL DEFAULT 'issued' CHECK (status IN ('issued', 'replaced', 'cancelled')),
    replaces_faktur_id                          BIGINT REFERENCES "ACCOUNTING".tax_faktur_pajak (id), -- Coretax replace-not-edit model (§3M rule)
    issued_at                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, nomor_seri_faktur),
    CHECK ((ar_invoice_id IS NOT NULL) OR (ap_bill_id IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".tax_bukti_potong (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id              BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    bp_type                   VARCHAR(10) NOT NULL CHECK (bp_type IN ('BP21', 'BP26', 'BP23', 'BP4A2', 'BPU')), -- BP4A2 = PPh 4(2)
    ap_bill_id                  BIGINT NOT NULL REFERENCES "ACCOUNTING".ap_bills (id),
    withholding_type_id           BIGINT NOT NULL REFERENCES "ACCOUNTING".withholding_types (id),
    partner_id                      BIGINT NOT NULL REFERENCES "CRM".partners (id), -- the party withheld from
    bp_number                         VARCHAR(30) NOT NULL,
    gross_amount                        NUMERIC(18, 2) NOT NULL,
    withheld_amount                       NUMERIC(18, 2) NOT NULL,
    status                                   VARCHAR(15) NOT NULL DEFAULT 'issued' CHECK (status IN ('issued', 'replaced', 'cancelled')),
    replaces_bp_id                             BIGINT REFERENCES "ACCOUNTING".tax_bukti_potong (id),
    issued_at                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, bp_number)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".tax_periods (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    obligation_type       VARCHAR(5) NOT NULL CHECK (obligation_type IN ('ppn', 'pph')),
    masa_pajak               CHAR(7) NOT NULL,                 -- 'YYYY-MM'
    due_date                   DATE NOT NULL,
    filing_status                 VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (filing_status IN ('open', 'filed', 'late')),
    filed_at                        TIMESTAMPTZ,
    UNIQUE (company_id, obligation_type, masa_pajak)
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".tax_coretax_export_batches (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    batch_type            VARCHAR(20) NOT NULL CHECK (batch_type IN ('faktur_keluaran', 'faktur_masukan', 'bukti_potong')),
    tax_period_id            BIGINT NOT NULL REFERENCES "ACCOUNTING".tax_periods (id),
    object_key                  VARCHAR(500) NOT NULL,          -- R2 XML batch file, see §4 object storage layout
    record_count                  INTEGER NOT NULL DEFAULT 0,
    generated_by                    BIGINT REFERENCES users (id),
    generated_at                      TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMIT;

-- =============================================================================
-- §3P / §4. Recurring Transactions
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".recurring_journal_templates (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    name                VARCHAR(150) NOT NULL,
    recurrence_rule       VARCHAR(255) NOT NULL,               -- RRULE string, simshaun/recurr (§3P)
    next_run_date            DATE,
    line_pattern                JSONB NOT NULL,                -- [{account_id, debit, credit, cost_center_id, description}]
    is_active                     BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".recurring_ar_templates (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    partner_id            BIGINT NOT NULL REFERENCES "CRM".partners (id),
    name                    VARCHAR(150) NOT NULL,
    recurrence_rule           VARCHAR(255) NOT NULL,
    next_run_date                DATE,
    line_pattern                    JSONB NOT NULL,
    is_active                         BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".recurring_ap_templates (
    id                  BIGSERIAL PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES "ACCOUNTING".companies (id),
    partner_id            BIGINT NOT NULL REFERENCES "CRM".partners (id),
    name                    VARCHAR(150) NOT NULL,
    recurrence_rule           VARCHAR(255) NOT NULL,
    next_run_date                DATE,
    line_pattern                    JSONB NOT NULL,
    is_active                         BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "ACCOUNTING".recurring_generation_log (
    id                          BIGSERIAL PRIMARY KEY,
    -- Polymorphic by design (points at one of the three template tables above,
    -- depending on template_type) — no single FK is possible, same trade-off
    -- accepted platform-wide for subject_type/subject_id pointers.
    template_type                VARCHAR(10) NOT NULL CHECK (template_type IN ('journal', 'ar', 'ap')),
    template_id                     BIGINT NOT NULL,
    generated_document_type            VARCHAR(20) NOT NULL CHECK (generated_document_type IN ('gl_journals', 'ar_invoices', 'ap_bills')),
    generated_document_id                 BIGINT NOT NULL,
    status                                  VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'reviewed')), -- never auto-posted (§3P rule)
    run_date                                  DATE NOT NULL,
    generated_at                                TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMIT;

-- =============================================================================
-- §3O / §4. Audit — append-only, no update/delete at the app layer
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "ACCOUNTING".audit_logs (
    id              BIGSERIAL PRIMARY KEY,
    company_id      BIGINT REFERENCES "ACCOUNTING".companies (id), -- nullable: some actions (e.g. tenant-level setup) precede a company existing
    action          VARCHAR(30) NOT NULL CHECK (action IN (
                        'journal_created', 'journal_posted', 'journal_reversed',
                        'period_closed', 'period_reopened', 'invoice_posted', 'bill_posted',
                        'payment_posted', 'tax_document_issued', 'tax_document_cancelled',
                        'master_data_changed'
                    )),
    actor_id        BIGINT REFERENCES users (id),
    entity_type     VARCHAR(50) NOT NULL,
    entity_id       VARCHAR(100) NOT NULL,
    before_snapshot JSONB,
    after_snapshot  JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_acct_audit_logs_entity ON "ACCOUNTING".audit_logs (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_acct_audit_logs_company ON "ACCOUNTING".audit_logs (company_id, created_at);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- One company (an Indonesian law firm's operating entity), a starter COA, one
-- fiscal year with 12 monthly periods, a cost center, IDR + USD currencies, PPN/
-- PPh lookups, a bank account, an opening-balance journal, an AR invoice with
-- PPN + Faktur Pajak, an AP bill with PPh 4(2) withholding + Bukti Potong, a
-- fixed asset, a budget line, and audit-log entries for each posting.
--
-- Assumes "CRM".partners already exists (a client partner + a vendor partner)
-- and Laravel's own `users` table (database/migrations/tenant/...create_users...)
-- — neither is created here; see AIINSIGHT_SPECS.sql for the same convention.
-- =============================================================================

-- Written with plain subqueries and data-modifying CTEs only (no psql \gset or
-- other client-specific meta-commands), so it runs unmodified through any
-- Postgres client — psql -f, pgAdmin, DBeaver, a Laravel raw-SQL seeder, etc.
-- Every generated-id handoff below is resolved by natural key (company legal
-- name, account_code, period_no, invoice/bill number) rather than a captured
-- variable. Since there is only one company, a small `c AS (...)` CTE for its id
-- is reused at the top of each statement that needs it.

BEGIN;

INSERT INTO "ACCOUNTING".companies (legal_name, npwp, address, base_currency, fiscal_year_start_month, coa_template_code)
VALUES ('PT Demo Legal Firm', '01.234.567.8-901.000', 'Jl. Sudirman No. 1, Jakarta', 'IDR', 1, 'ID_STANDARD');

-- Chart of Accounts — a minimal starter set across all six Indonesian groupings
WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".accounts (company_id, account_code, account_name, account_type, normal_balance, is_control_account)
SELECT c.id, v.code, v.name, v.acct_type, v.normal_balance, v.is_control
FROM c, (VALUES
    ('10100', 'Kas dan Bank', 'asset', 'debit', FALSE),
    ('10200', 'Piutang Usaha', 'asset', 'debit', TRUE),      -- AR control account
    ('10500', 'Aset Tetap', 'asset', 'debit', FALSE),
    ('10510', 'Akumulasi Penyusutan', 'asset', 'credit', FALSE),
    ('20100', 'Utang Usaha', 'liability', 'credit', TRUE),   -- AP control account
    ('20200', 'PPN Keluaran', 'liability', 'credit', FALSE),
    ('20210', 'PPN Masukan', 'asset', 'debit', FALSE),
    ('20300', 'Utang PPh Pasal 4(2)', 'liability', 'credit', FALSE),
    ('30100', 'Modal Disetor', 'equity', 'credit', FALSE),
    ('40100', 'Pendapatan Jasa Hukum', 'revenue', 'credit', FALSE),
    ('60100', 'Beban Sewa Kantor', 'expense', 'debit', FALSE),
    ('60200', 'Beban Penyusutan', 'expense', 'debit', FALSE)
) AS v(code, name, acct_type, normal_balance, is_control);

-- Fiscal year 2026 + 12 monthly periods
WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".fiscal_years (company_id, year, start_date, end_date)
SELECT id, 2026, '2026-01-01', '2026-12-31' FROM c;

WITH fy AS (
    SELECT fy.id AS fiscal_year_id, fy.company_id
    FROM "ACCOUNTING".fiscal_years fy
    JOIN "ACCOUNTING".companies c ON c.id = fy.company_id
    WHERE c.legal_name = 'PT Demo Legal Firm' AND fy.year = 2026
)
INSERT INTO "ACCOUNTING".fiscal_periods (company_id, fiscal_year_id, period_no, start_date, end_date)
SELECT fy.company_id, fy.fiscal_year_id, m,
       make_date(2026, m, 1),
       (make_date(2026, m, 1) + INTERVAL '1 month - 1 day')::date
FROM fy, generate_series(1, 12) AS m;

WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".cost_centers (company_id, code, name)
SELECT c.id, v.code, v.name FROM c, (VALUES
    ('LITIGATION', 'Litigation Team'),
    ('CORPORATE', 'Corporate Team')
) AS v(code, name);

INSERT INTO "ACCOUNTING".currencies (code, name) VALUES
    ('IDR', 'Indonesian Rupiah'), ('USD', 'US Dollar')
ON CONFLICT (code) DO NOTHING;

WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".exchange_rates (company_id, currency_code, rate_date, rate_to_base)
SELECT id, 'USD', CURRENT_DATE, 16200.00 FROM c;

WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".tax_codes (company_id, code, rate, tax_type, gl_account_id)
SELECT c.id, 'PPN11_OUT', 11.00, 'output', a.id
FROM c JOIN "ACCOUNTING".accounts a ON a.company_id = c.id AND a.account_code = '20200'
UNION ALL
SELECT c.id, 'PPN11_IN', 11.00, 'input', a.id
FROM c JOIN "ACCOUNTING".accounts a ON a.company_id = c.id AND a.account_code = '20210';

WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".withholding_types (company_id, code, name, rate, is_final, gl_payable_account_id)
SELECT c.id, 'PPh4A2', 'PPh Pasal 4(2) - Sewa', 10.00, TRUE, a.id
FROM c JOIN "ACCOUNTING".accounts a ON a.company_id = c.id AND a.account_code = '20300';

WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".asset_groups (company_id, code, name, fiscal_method, fiscal_rate, fiscal_useful_life_years)
SELECT id, 'KELOMPOK1', 'Kelompok 1 (non-bangunan)', 'declining_balance', 50.00, 4 FROM c;

WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".bank_accounts (company_id, bank_name, account_number, currency_code, gl_account_id)
SELECT c.id, 'Bank BCA', '1234567890', 'IDR', a.id
FROM c JOIN "ACCOUNTING".accounts a ON a.company_id = c.id AND a.account_code = '10100';

-- Opening-balance journal (manual, balanced, posted)
WITH c AS (
    SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm'
), fp AS (
    SELECT fp.id, fp.company_id FROM "ACCOUNTING".fiscal_periods fp JOIN c ON c.id = fp.company_id WHERE fp.period_no = 1
), j AS (
    INSERT INTO "ACCOUNTING".gl_journals (company_id, fiscal_period_id, journal_date, currency_code, memo, source, status, posted_at)
    SELECT fp.company_id, fp.id, '2026-01-01', 'IDR', 'Opening balance - modal disetor', 'manual', 'posted', now()
    FROM fp
    RETURNING id, company_id
)
INSERT INTO "ACCOUNTING".gl_journal_lines (journal_id, line_no, account_id, debit, credit, description)
SELECT j.id, 1, a.id, 50000000, 0, 'Setoran modal awal' FROM j JOIN "ACCOUNTING".accounts a ON a.company_id = j.company_id AND a.account_code = '10100'
UNION ALL
SELECT j.id, 2, a.id, 0, 50000000, 'Setoran modal awal' FROM j JOIN "ACCOUNTING".accounts a ON a.company_id = j.company_id AND a.account_code = '30100';

-- AR invoice to a client partner, with PPN output + Faktur Pajak
WITH c AS (
    SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm'
), fp AS (
    SELECT fp.id, fp.company_id FROM "ACCOUNTING".fiscal_periods fp JOIN c ON c.id = fp.company_id WHERE fp.period_no = 8
), partner AS (
    SELECT id FROM "CRM".partners WHERE email = 'client@example-corp.com'
), j AS (
    INSERT INTO "ACCOUNTING".gl_journals (company_id, fiscal_period_id, journal_date, currency_code, memo, source, status, posted_at)
    SELECT fp.company_id, fp.id, '2026-08-05', 'IDR', 'AR Invoice INV-2026-0001', 'ar', 'posted', now()
    FROM fp
    RETURNING id, company_id
), jl AS (
    INSERT INTO "ACCOUNTING".gl_journal_lines (journal_id, line_no, account_id, debit, credit, description)
    SELECT j.id, 1, a.id, 11100000, 0, 'Piutang - INV-2026-0001'
    FROM j JOIN "ACCOUNTING".accounts a ON a.company_id = j.company_id AND a.account_code = '10200'
    UNION ALL
    SELECT j.id, 2, a.id, 0, 10000000, 'Pendapatan jasa hukum'
    FROM j JOIN "ACCOUNTING".accounts a ON a.company_id = j.company_id AND a.account_code = '40100'
    UNION ALL
    SELECT j.id, 3, a.id, 0, 1100000, 'PPN Keluaran 11%'
    FROM j JOIN "ACCOUNTING".accounts a ON a.company_id = j.company_id AND a.account_code = '20200'
    RETURNING journal_id
), inv AS (
    INSERT INTO "ACCOUNTING".ar_invoices (company_id, partner_id, invoice_no, currency_code, issue_date, due_date, status, subtotal, tax_amount, total_amount, journal_id)
    SELECT j.company_id, partner.id, 'INV-2026-0001', 'IDR', '2026-08-05', '2026-09-04', 'posted', 10000000, 1100000, 11100000, j.id
    FROM j, partner
    RETURNING id, company_id
), il AS (
    INSERT INTO "ACCOUNTING".ar_invoice_lines (invoice_id, description, qty, unit_price, tax_code_id, line_amount, revenue_account_id)
    SELECT inv.id, 'Retainer jasa hukum - Agustus 2026', 1, 10000000, tc.id, 10000000, rev.id
    FROM inv
    JOIN "ACCOUNTING".tax_codes tc ON tc.company_id = inv.company_id AND tc.code = 'PPN11_OUT'
    JOIN "ACCOUNTING".accounts rev ON rev.company_id = inv.company_id AND rev.account_code = '40100'
    RETURNING invoice_id
)
INSERT INTO "ACCOUNTING".tax_faktur_pajak (company_id, direction, nomor_seri_faktur, ar_invoice_id, partner_id, buyer_npwp_nik, tax_base, ppn_amount)
SELECT inv.company_id, 'output', '010.000-26.00000001', inv.id, partner.id, '02.345.678.9-012.000', 10000000, 1100000
FROM inv, partner
WHERE EXISTS (SELECT 1 FROM jl) AND EXISTS (SELECT 1 FROM il);

-- AP bill from a vendor (office rent), with PPh 4(2) final withholding + Bukti Potong
WITH c AS (
    SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm'
), fp AS (
    SELECT fp.id, fp.company_id FROM "ACCOUNTING".fiscal_periods fp JOIN c ON c.id = fp.company_id WHERE fp.period_no = 8
), partner AS (
    SELECT id FROM "CRM".partners WHERE email = 'vendor@office-rentals.example'
), j AS (
    INSERT INTO "ACCOUNTING".gl_journals (company_id, fiscal_period_id, journal_date, currency_code, memo, source, status, posted_at)
    SELECT fp.company_id, fp.id, '2026-08-10', 'IDR', 'AP Bill BILL-2026-0001', 'ap', 'posted', now()
    FROM fp
    RETURNING id, company_id
), jl AS (
    INSERT INTO "ACCOUNTING".gl_journal_lines (journal_id, line_no, account_id, debit, credit, description)
    SELECT j.id, 1, a.id, 5000000, 0, 'Beban sewa kantor Agustus 2026'
    FROM j JOIN "ACCOUNTING".accounts a ON a.company_id = j.company_id AND a.account_code = '60100'
    UNION ALL
    SELECT j.id, 2, a.id, 0, 500000, 'PPh 4(2) dipotong'
    FROM j JOIN "ACCOUNTING".accounts a ON a.company_id = j.company_id AND a.account_code = '20300'
    UNION ALL
    SELECT j.id, 3, a.id, 0, 4500000, 'Utang usaha - net setelah potongan'
    FROM j JOIN "ACCOUNTING".accounts a ON a.company_id = j.company_id AND a.account_code = '20100'
    RETURNING journal_id
), bill AS (
    INSERT INTO "ACCOUNTING".ap_bills (company_id, partner_id, bill_no, currency_code, bill_date, due_date, status, subtotal, withholding_amount, total_amount, journal_id)
    SELECT j.company_id, partner.id, 'BILL-2026-0001', 'IDR', '2026-08-10', '2026-09-09', 'posted', 5000000, 500000, 4500000, j.id
    FROM j, partner
    RETURNING id, company_id
), bl AS (
    INSERT INTO "ACCOUNTING".ap_bill_lines (bill_id, description, qty, unit_price, line_amount, withholding_type_id, expense_account_id)
    SELECT bill.id, 'Sewa kantor - Agustus 2026', 1, 5000000, 5000000, wt.id, exp.id
    FROM bill
    JOIN "ACCOUNTING".withholding_types wt ON wt.company_id = bill.company_id AND wt.code = 'PPh4A2'
    JOIN "ACCOUNTING".accounts exp ON exp.company_id = bill.company_id AND exp.account_code = '60100'
    RETURNING bill_id
)
INSERT INTO "ACCOUNTING".tax_bukti_potong (company_id, bp_type, ap_bill_id, withholding_type_id, partner_id, bp_number, gross_amount, withheld_amount)
SELECT bill.company_id, 'BP4A2', bill.id, wt.id, partner.id, 'BP-2026-08-0001', 5000000, 500000
FROM bill, partner, "ACCOUNTING".withholding_types wt
WHERE wt.company_id = bill.company_id AND wt.code = 'PPh4A2'
  AND EXISTS (SELECT 1 FROM jl) AND EXISTS (SELECT 1 FROM bl);

-- Fixed asset (a laptop, Kelompok 1)
WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".fa_assets (company_id, asset_code, name, acquisition_date, acquisition_cost, asset_group_id, useful_life_years, gl_asset_account_id, gl_accum_depreciation_account_id, gl_depreciation_expense_account_id)
SELECT c.id, 'FA-0001', 'Laptop Kantor - Dell Latitude', '2026-08-01', 15000000, ag.id, 4, asset.id, accum.id, exp.id
FROM c
JOIN "ACCOUNTING".asset_groups ag ON ag.company_id = c.id AND ag.code = 'KELOMPOK1'
JOIN "ACCOUNTING".accounts asset ON asset.company_id = c.id AND asset.account_code = '10500'
JOIN "ACCOUNTING".accounts accum ON accum.company_id = c.id AND accum.account_code = '10510'
JOIN "ACCOUNTING".accounts exp ON exp.company_id = c.id AND exp.account_code = '60200';

-- Budget: office rent expense for August 2026
WITH c AS (
    SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm'
), fy AS (
    SELECT fy.id, fy.company_id FROM "ACCOUNTING".fiscal_years fy JOIN c ON c.id = fy.company_id WHERE fy.year = 2026
), b AS (
    INSERT INTO "ACCOUNTING".budgets (company_id, fiscal_year_id, name, status)
    SELECT fy.company_id, fy.id, 'Annual Budget 2026', 'active' FROM fy
    RETURNING id, company_id
)
INSERT INTO "ACCOUNTING".budget_lines (budget_id, account_id, fiscal_period_id, amount)
SELECT b.id, a.id, fp.id, 5000000
FROM b
JOIN "ACCOUNTING".accounts a ON a.company_id = b.company_id AND a.account_code = '60100'
JOIN "ACCOUNTING".fiscal_periods fp ON fp.company_id = b.company_id AND fp.period_no = 8;

-- Tax period register: PPN masa pajak Agustus 2026
WITH c AS (SELECT id FROM "ACCOUNTING".companies WHERE legal_name = 'PT Demo Legal Firm')
INSERT INTO "ACCOUNTING".tax_periods (company_id, obligation_type, masa_pajak, due_date)
SELECT id, 'ppn', '2026-08', '2026-09-30' FROM c;

-- Audit trail for the two postings above
INSERT INTO "ACCOUNTING".audit_logs (company_id, action, entity_type, entity_id, after_snapshot)
SELECT c.id, 'invoice_posted', 'ar_invoices', inv.id::text, jsonb_build_object('status', 'posted', 'total_amount', inv.total_amount)
FROM "ACCOUNTING".companies c
JOIN "ACCOUNTING".ar_invoices inv ON inv.company_id = c.id AND inv.invoice_no = 'INV-2026-0001'
WHERE c.legal_name = 'PT Demo Legal Firm'
UNION ALL
SELECT c.id, 'bill_posted', 'ap_bills', bill.id::text, jsonb_build_object('status', 'posted', 'total_amount', bill.total_amount)
FROM "ACCOUNTING".companies c
JOIN "ACCOUNTING".ap_bills bill ON bill.company_id = c.id AND bill.bill_no = 'BILL-2026-0001'
WHERE c.legal_name = 'PT Demo Legal Firm';

COMMIT;
