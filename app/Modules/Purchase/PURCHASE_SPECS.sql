-- =============================================================================
-- Purchase module — schema + example seed data
-- Source spec: app/Modules/Purchase/PURCHASE_SPECS.md (see that file for full
-- rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), schema "PURCHASE", no tenant_id
--              column anywhere.
--
-- Prerequisites (run their own *_SPECS.sql first — this script only creates
-- the "PURCHASE" schema): "CRM".partners/partner_role_types/partner_roles
-- (CRM_SPECS.sql) and "DMS".documents (DMS_SPECS.sql). Purchase's own §5
-- states these plainly (not hedged as "optional install" the way Inventory
-- and Accounting are), so both are real, enforced cross-schema FKs here —
-- unlike the informational-only references below.
--
-- §1's "must work standalone" framing and §2's "Vendor = CRM Partner, no
-- separate vendor table" MVP goal read as mildly in tension on a literal
-- pass — resolved in favor of §2/§5, which are unambiguous that CRM is a
-- real dependency for the vendor record; "standalone" is read as "standalone
-- from Inventory/Accounting/the rest", the same qualified sense CRM_SPECS.md
-- and DMS_SPECS.md already use for themselves.
--
-- Cross-module reference posture (per §4/§5, each flagged inline below):
--   - "CRM".partners            — REAL FK (hard dependency, §5)
--   - "DMS".documents            — REAL, nullable FK (documents are optional
--                                   per-row, but the module isn't hedged as
--                                   an optional install the way Inventory is)
--   - Accounting (cost_centers)  — informational BIGINT, no FK (optional install)
--   - Inventory (goods_receipts) — informational BIGINT, no FK (optional install)
--   - WNE (workflow_instances)   — informational BIGINT, no FK (peer Core module)
--   - Schedule (sched_items)     — informational BIGINT, no FK (peer Core module)
--
-- Amendment/versioning note: unlike Inventory's stock_ledger, Legal's
-- protocol_entries, or Payroll's locked payroll_run_lines, pur_order_hdrs/
-- pur_order_lines do NOT get a blocking immutability trigger. §3D's "never
-- silently overwrite" rule is a snapshot-before-update pattern (the prior
-- state is captured into pur_order_revisions before the row legitimately
-- changes), not an append-only ledger — a blocking trigger would be wrong
-- here since the amendment flow's whole point is to update the row after
-- snapshotting it. Enforcing "snapshot must exist first" is an app-layer
-- rule, same departure already made for Legal's deeds table.
--
-- Written with plain subqueries and data-modifying CTEs only — no psql
-- \gset or other client-specific meta-commands — so it runs through any
-- Postgres client. Where a CTE inserts a row that a LATER sibling CTE in
-- the same statement needs to UPDATE, that's split into separate top-level
-- statements (same fix applied in every other *_SPECS.sql since DMS's).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

CREATE SCHEMA IF NOT EXISTS "PURCHASE";

-- -----------------------------------------------------------------------------
-- §4. Master / lookup tables
-- -----------------------------------------------------------------------------

-- 1:1 extension of "CRM".partners (never a duplicate vendor master, §2) — a
-- partner only gets a row here once it holds the Vendor role in CRM.
CREATE TABLE IF NOT EXISTS "PURCHASE".vendor_profiles (
    id                          BIGSERIAL PRIMARY KEY,
    partner_id                  BIGINT NOT NULL UNIQUE REFERENCES "CRM".partners (id),
    payment_terms               VARCHAR(100),
    incoterms                   VARCHAR(20),
    preferred_currency          VARCHAR(3) NOT NULL DEFAULT 'IDR',
    tax_registration_no         VARCHAR(50),
    bank_name                   VARCHAR(150),
    bank_account_name           VARCHAR(150),
    bank_account_no_encrypted   TEXT,               -- app-layer encrypted at rest — security-sensitive field (§3G)
    preferred_status             BOOLEAN NOT NULL DEFAULT FALSE,
    onboarding_status              VARCHAR(20) NOT NULL DEFAULT 'pending'
                                       CHECK (onboarding_status IN ('pending', 'active', 'suspended')),
    created_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                         TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Tenant-editable spend category lookup (direct/indirect, CAPEX/OPEX)
CREATE TABLE IF NOT EXISTS "PURCHASE".categories (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    spend_type  VARCHAR(10) NOT NULL CHECK (spend_type IN ('direct', 'indirect')),
    capex_opex  VARCHAR(10) NOT NULL CHECK (capex_opex IN ('capex', 'opex')),
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

-- Tenant-editable cost center / department lookup — functions fully
-- standalone; accounting_cost_center_id lets a tenant running both modules
-- map onto Accounting's canonical cost-center list (§4/§5), without one.
CREATE TABLE IF NOT EXISTS "PURCHASE".cost_centers (
    id                          BIGSERIAL PRIMARY KEY,
    code                        VARCHAR(30) NOT NULL UNIQUE,
    name                        VARCHAR(100) NOT NULL,
    accounting_cost_center_id   BIGINT,             -- informational only; Accounting is an optional install (§4)
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE
);

-- -----------------------------------------------------------------------------
-- §3G / §4. Vendor documents — certs/licenses/insurance (MVP compliance)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_vendor_documents (
    id                  BIGSERIAL PRIMARY KEY,
    vendor_profile_id   BIGINT NOT NULL REFERENCES "PURCHASE".vendor_profiles (id) ON DELETE CASCADE,
    doc_type            VARCHAR(50) NOT NULL,       -- insurance_coi, business_license, tax_cert, msa_contract, other
    document_id         BIGINT REFERENCES "DMS".documents (id),
    expiry_date         DATE,
    sched_item_id       BIGINT,                     -- informational link to a Schedule expiry-reminder item (§3G)
    status               VARCHAR(20) NOT NULL DEFAULT 'valid'
                             CHECK (status IN ('valid', 'expiring_soon', 'expired')),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_purchase_vendor_documents_vendor  ON "PURCHASE".pur_vendor_documents (vendor_profile_id);
CREATE INDEX IF NOT EXISTS idx_purchase_vendor_documents_expiry ON "PURCHASE".pur_vendor_documents (expiry_date);

-- -----------------------------------------------------------------------------
-- §3I / §4. Catalog
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_catalog_items (
    id                      BIGSERIAL PRIMARY KEY,
    item_code               VARCHAR(50) NOT NULL UNIQUE,
    description              VARCHAR(255) NOT NULL,
    category_id               BIGINT REFERENCES "PURCHASE".categories (id),
    unit_of_measure             VARCHAR(20) NOT NULL DEFAULT 'EA',
    preferred_partner_id          BIGINT REFERENCES "CRM".partners (id),
    negotiated_price                 NUMERIC(14, 2),
    currency                            VARCHAR(3) NOT NULL DEFAULT 'IDR',
    price_valid_from                       DATE,
    price_valid_to                            DATE,
    source                                        VARCHAR(20) NOT NULL DEFAULT 'manual'
                                                       CHECK (source IN ('manual', 'rfx_award')),
    is_active                                        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                             TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_purchase_catalog_items_category ON "PURCHASE".pur_catalog_items (category_id);
CREATE INDEX IF NOT EXISTS idx_purchase_catalog_items_supplier ON "PURCHASE".pur_catalog_items (preferred_partner_id);

-- -----------------------------------------------------------------------------
-- §3B / §4. Purchase Requisition (PR)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_requisition_hdrs (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    requester_id                 BIGINT NOT NULL REFERENCES users (id),
    cost_center_id                 BIGINT REFERENCES "PURCHASE".cost_centers (id),
    needed_by                         DATE,
    status                                VARCHAR(20) NOT NULL DEFAULT 'draft'
                                              CHECK (status IN ('draft', 'pending_approval', 'approved', 'rejected', 'converted', 'cancelled')),
    subject_type                            VARCHAR(100), -- optional polymorphic link (e.g. 'legal.case_hdrs') — NOT a FK (§3B)
    subject_id                                 BIGINT,
    wne_workflow_instance_id                      BIGINT, -- informational only; WNE is a peer Core module, no hard FK
    duplicate_flag                                   BOOLEAN NOT NULL DEFAULT FALSE,
    budget_flag                                         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at                                             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_purchase_requisition_hdrs_status    ON "PURCHASE".pur_requisition_hdrs (status);
CREATE INDEX IF NOT EXISTS idx_purchase_requisition_hdrs_requester ON "PURCHASE".pur_requisition_hdrs (requester_id);

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_requisition_lines (
    id                      BIGSERIAL PRIMARY KEY,
    requisition_id          BIGINT NOT NULL REFERENCES "PURCHASE".pur_requisition_hdrs (id) ON DELETE CASCADE,
    line_no                  INT NOT NULL,
    catalog_item_id            BIGINT REFERENCES "PURCHASE".pur_catalog_items (id),
    description                   VARCHAR(255) NOT NULL,
    category_id                      BIGINT REFERENCES "PURCHASE".categories (id),
    quantity                            NUMERIC(14, 3) NOT NULL,
    estimated_unit_price                   NUMERIC(14, 2),
    estimated_line_total                      NUMERIC(14, 2) GENERATED ALWAYS AS (quantity * COALESCE(estimated_unit_price, 0)) STORED,
    UNIQUE (requisition_id, line_no)
);

-- -----------------------------------------------------------------------------
-- §3C / §4. Sourcing / RFx — MVP scope: RFQ only, flat comparison
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_rfx_hdrs (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    requisition_id  BIGINT REFERENCES "PURCHASE".pur_requisition_hdrs (id), -- nullable: RFx can be standalone (§3C)
    rfx_type        VARCHAR(10) NOT NULL DEFAULT 'rfq' CHECK (rfx_type IN ('rfi', 'rfq', 'rfp')), -- MVP creates 'rfq' only
    title           VARCHAR(200) NOT NULL,
    due_at          TIMESTAMPTZ NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft'
                        CHECK (status IN ('draft', 'open', 'closed', 'awarded', 'cancelled')),
    buyer_id        BIGINT REFERENCES users (id),
    sched_item_id   BIGINT,                         -- informational link to a Schedule due-date reminder (§3C)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_purchase_rfx_hdrs_status ON "PURCHASE".pur_rfx_hdrs (status);

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_rfx_lines (
    id           BIGSERIAL PRIMARY KEY,
    rfx_id       BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_hdrs (id) ON DELETE CASCADE,
    line_no      INT NOT NULL,
    description  VARCHAR(255) NOT NULL,
    quantity     NUMERIC(14, 3) NOT NULL,
    UNIQUE (rfx_id, line_no)
);

-- Lightweight "supplier portal" for MVP (§2/§5): a signed response token per
-- invited supplier, no supplier login/session required.
CREATE TABLE IF NOT EXISTS "PURCHASE".pur_rfx_invitations (
    id              BIGSERIAL PRIMARY KEY,
    rfx_id          BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_hdrs (id) ON DELETE CASCADE,
    partner_id      BIGINT NOT NULL REFERENCES "CRM".partners (id),
    invited_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    response_token  UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    responded_at    TIMESTAMPTZ,
    UNIQUE (rfx_id, partner_id)
);

CREATE INDEX IF NOT EXISTS idx_purchase_rfx_invitations_partner ON "PURCHASE".pur_rfx_invitations (partner_id);

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_rfx_responses (
    id                 BIGSERIAL PRIMARY KEY,
    rfx_invitation_id  BIGINT NOT NULL UNIQUE REFERENCES "PURCHASE".pur_rfx_invitations (id) ON DELETE CASCADE,
    submitted_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    notes              TEXT,
    document_id        BIGINT REFERENCES "DMS".documents (id)
);

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_rfx_response_lines (
    id                BIGSERIAL PRIMARY KEY,
    rfx_response_id   BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_responses (id) ON DELETE CASCADE,
    rfx_line_id       BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_lines (id),
    unit_price        NUMERIC(14, 2) NOT NULL,
    lead_time_days    INT,
    notes             VARCHAR(255),
    UNIQUE (rfx_response_id, rfx_line_id)
);

-- -----------------------------------------------------------------------------
-- §3D / §4. Purchase Order (PO)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_order_hdrs (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    po_number                   VARCHAR(30) NOT NULL UNIQUE,
    supplier_partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id),
    rfx_id                          BIGINT REFERENCES "PURCHASE".pur_rfx_hdrs (id),
    requisition_id                     BIGINT REFERENCES "PURCHASE".pur_requisition_hdrs (id),
    currency                              VARCHAR(3) NOT NULL DEFAULT 'IDR',
    incoterms                                VARCHAR(20),
    payment_terms                               VARCHAR(100),
    status                                          VARCHAR(20) NOT NULL DEFAULT 'draft'
                                                         CHECK (status IN ('draft', 'pending_approval', 'approved', 'sent',
                                                                            'acknowledged', 'partially_received', 'received',
                                                                            'closed', 'cancelled')),
    wne_workflow_instance_id                            BIGINT, -- informational only
    revision_no                                            INT NOT NULL DEFAULT 0,
    document_id                                               BIGINT REFERENCES "DMS".documents (id), -- generated PO PDF
    created_by                                                   BIGINT REFERENCES users (id),
    created_at                                                      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_purchase_order_hdrs_supplier ON "PURCHASE".pur_order_hdrs (supplier_partner_id);
CREATE INDEX IF NOT EXISTS idx_purchase_order_hdrs_status   ON "PURCHASE".pur_order_hdrs (status);

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_order_lines (
    id                       BIGSERIAL PRIMARY KEY,
    order_id                 BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_hdrs (id) ON DELETE CASCADE,
    line_no                   INT NOT NULL,
    catalog_item_id             BIGINT REFERENCES "PURCHASE".pur_catalog_items (id),
    description                    VARCHAR(255) NOT NULL,
    quantity                          NUMERIC(14, 3) NOT NULL,
    unit_price                           NUMERIC(14, 2) NOT NULL,
    line_total                              NUMERIC(14, 2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    expected_delivery_date                     DATE,
    tax_amount                                    NUMERIC(14, 2) NOT NULL DEFAULT 0,
    category_id                                      BIGINT REFERENCES "PURCHASE".categories (id),
    UNIQUE (order_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_order ON "PURCHASE".pur_order_lines (order_id);

-- Amendment history — a PO is never silently edited after 'sent' (§3D rule);
-- the caller snapshots the pre-amendment header+lines here BEFORE updating
-- pur_order_hdrs/pur_order_lines in place and bumping revision_no.
CREATE TABLE IF NOT EXISTS "PURCHASE".pur_order_revisions (
    id           BIGSERIAL PRIMARY KEY,
    order_id     BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_hdrs (id) ON DELETE CASCADE,
    revision_no  INT NOT NULL,
    snapshot     JSONB NOT NULL,             -- full header+lines snapshot at time of amendment
    reason       VARCHAR(255),
    revised_by   BIGINT REFERENCES users (id),
    revised_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (order_id, revision_no)
);

-- -----------------------------------------------------------------------------
-- §3E / §4. Goods Receipt (GR)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_receipt_hdrs (
    id                            BIGSERIAL PRIMARY KEY,
    uuid                          UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    order_id                       BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_hdrs (id),
    receiver_id                       BIGINT REFERENCES users (id),
    received_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    inventory_goods_receipt_id              BIGINT, -- informational; Inventory is an optional install (§3E/§4)
    notes                                       TEXT,
    created_at                                     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_purchase_receipt_hdrs_order ON "PURCHASE".pur_receipt_hdrs (order_id);

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_receipt_lines (
    id                  BIGSERIAL PRIMARY KEY,
    receipt_id          BIGINT NOT NULL REFERENCES "PURCHASE".pur_receipt_hdrs (id) ON DELETE CASCADE,
    order_line_id       BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_lines (id),
    quantity_received   NUMERIC(14, 3) NOT NULL,   -- always the authoritative "did it arrive" figure (§3F rule)
    condition_notes     VARCHAR(255),
    photo_document_id   BIGINT REFERENCES "DMS".documents (id),
    is_over_receipt     BOOLEAN NOT NULL DEFAULT FALSE
);

-- -----------------------------------------------------------------------------
-- §3F / §4. Invoice Capture & Three-Way Match
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_invoice_hdrs (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    order_id                     BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_hdrs (id),
    supplier_invoice_no             VARCHAR(50) NOT NULL,
    supplier_invoice_date              DATE NOT NULL,
    currency                              VARCHAR(3) NOT NULL DEFAULT 'IDR',
    amount                                    NUMERIC(14, 2) NOT NULL,
    document_id                                 BIGINT REFERENCES "DMS".documents (id),
    submission_channel                             VARCHAR(20) NOT NULL DEFAULT 'manual'
                                                        CHECK (submission_channel IN ('manual', 'supplier_link')),
    status                                             VARCHAR(20) NOT NULL DEFAULT 'submitted'
                                                            CHECK (status IN ('submitted', 'matched', 'mismatched',
                                                                               'approved_for_payment', 'sent_for_payment',
                                                                               'paid', 'rejected')),
    wne_workflow_instance_id                               BIGINT, -- informational only
    created_at                                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (order_id, supplier_invoice_no)
);

CREATE INDEX IF NOT EXISTS idx_purchase_invoice_hdrs_order  ON "PURCHASE".pur_invoice_hdrs (order_id);
CREATE INDEX IF NOT EXISTS idx_purchase_invoice_hdrs_status ON "PURCHASE".pur_invoice_hdrs (status);

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_invoice_lines (
    id             BIGSERIAL PRIMARY KEY,
    invoice_id     BIGINT NOT NULL REFERENCES "PURCHASE".pur_invoice_hdrs (id) ON DELETE CASCADE,
    order_line_id  BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_lines (id),
    quantity       NUMERIC(14, 3) NOT NULL,
    unit_price     NUMERIC(14, 2) NOT NULL,
    line_total     NUMERIC(14, 2) GENERATED ALWAYS AS (quantity * unit_price) STORED
);

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_invoice_matches (
    id                BIGSERIAL PRIMARY KEY,
    invoice_id        BIGINT NOT NULL REFERENCES "PURCHASE".pur_invoice_hdrs (id) ON DELETE CASCADE,
    order_line_id     BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_lines (id),
    receipt_line_id   BIGINT REFERENCES "PURCHASE".pur_receipt_lines (id), -- nullable: unmatched invoices may have no receipt leg yet
    qty_variance      NUMERIC(14, 3) NOT NULL DEFAULT 0,
    price_variance    NUMERIC(14, 2) NOT NULL DEFAULT 0,
    within_tolerance  BOOLEAN NOT NULL DEFAULT TRUE,
    matched_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3H / §4. Contract Management
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_contract_hdrs (
    id                     BIGSERIAL PRIMARY KEY,
    uuid                   UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    supplier_partner_id    BIGINT NOT NULL REFERENCES "CRM".partners (id),
    title                  VARCHAR(200) NOT NULL,
    contract_type          VARCHAR(20) NOT NULL CHECK (contract_type IN ('framework', 'blanket', 'project')),
    contract_value         NUMERIC(14, 2),
    currency               VARCHAR(3) NOT NULL DEFAULT 'IDR',
    start_date             DATE NOT NULL,
    end_date               DATE NOT NULL,
    auto_renewal           BOOLEAN NOT NULL DEFAULT FALSE,
    renewal_notice_days    INT DEFAULT 90,
    document_id            BIGINT REFERENCES "DMS".documents (id),
    status                 VARCHAR(20) NOT NULL DEFAULT 'draft'
                                CHECK (status IN ('draft', 'active', 'expiring_soon', 'expired', 'renewed', 'terminated')),
    owner_id               BIGINT REFERENCES users (id),
    sched_item_id          BIGINT,             -- informational link to a Schedule renewal-reminder item (§3H)
    local_content_pct      NUMERIC(5, 2),      -- MVP ESG placeholder: free-form %, no enforcement logic (§3M)
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (end_date > start_date)
);

CREATE INDEX IF NOT EXISTS idx_purchase_contract_hdrs_supplier ON "PURCHASE".pur_contract_hdrs (supplier_partner_id);
CREATE INDEX IF NOT EXISTS idx_purchase_contract_hdrs_status   ON "PURCHASE".pur_contract_hdrs (status);
CREATE INDEX IF NOT EXISTS idx_purchase_contract_hdrs_end_date ON "PURCHASE".pur_contract_hdrs (end_date);

-- -----------------------------------------------------------------------------
-- §3K / §4. Exception Management — single append-style log feeding 3A/3K
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_exceptions (
    id              BIGSERIAL PRIMARY KEY,
    exception_type  VARCHAR(30) NOT NULL
                        CHECK (exception_type IN ('overdue_approval', 'late_delivery', 'price_variance',
                                                   'budget_flag', 'unmatched_invoice')),
    subject_type    VARCHAR(50) NOT NULL,       -- 'requisition' | 'order' | 'invoice' — NOT a FK (varies by type)
    subject_id      BIGINT NOT NULL,
    detected_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    status          VARCHAR(20) NOT NULL DEFAULT 'open'
                        CHECK (status IN ('open', 'acknowledged', 'resolved')),
    resolved_at     TIMESTAMPTZ,
    resolved_by     BIGINT REFERENCES users (id),
    notes           VARCHAR(255)
);

CREATE INDEX IF NOT EXISTS idx_purchase_exceptions_status  ON "PURCHASE".pur_exceptions (status);
CREATE INDEX IF NOT EXISTS idx_purchase_exceptions_subject ON "PURCHASE".pur_exceptions (subject_type, subject_id);

-- -----------------------------------------------------------------------------
-- §3B / §4. Budgets — simple period × cost-center × category soft-budget (MVP)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PURCHASE".pur_budgets (
    id                BIGSERIAL PRIMARY KEY,
    cost_center_id    BIGINT NOT NULL REFERENCES "PURCHASE".cost_centers (id),
    category_id       BIGINT NOT NULL REFERENCES "PURCHASE".categories (id),
    period_start      DATE NOT NULL,
    period_end        DATE NOT NULL,
    budget_amount     NUMERIC(14, 2) NOT NULL,
    currency          VARCHAR(3) NOT NULL DEFAULT 'IDR',
    consumed_amount   NUMERIC(14, 2) NOT NULL DEFAULT 0,
    UNIQUE (cost_center_id, category_id, period_start, period_end),
    CHECK (period_end > period_start)
);

CREATE INDEX IF NOT EXISTS idx_purchase_budgets_period ON "PURCHASE".pur_budgets (period_start, period_end);

-- =============================================================================
-- NOTE — Future Version tables intentionally NOT created here (§2/§5 MVP scope
-- boundary): pur_rfx_scorecards, "PURCHASE".rfx_criteria, pur_capa_records,
-- pur_audit_records, pur_esg_scores, pur_supplier_scorecards. None of the MVP
-- tables above require breaking changes to accommodate them later (§5).
-- =============================================================================

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Demonstrates the full Requisition -> PO -> Receipt -> Invoice -> Match flow
-- twice: once a clean routine catalog buy (PO-2026-0001, fully matched), once
-- via RFQ sourcing that ends in a price-variance exception (PO-2026-0002) —
-- plus a catalog, an expiring contract, vendor compliance docs, and budgets.
--
-- Assumes CRM_SPECS.sql's seed has already run (partners "Example Corp" /
-- "Office Rentals Vendor", role type code 'VENDOR', users admin@nusaevo.com /
-- staff@nusaevo.com from database/seeders/DatabaseSeeder.php) and
-- DMS_SPECS.sql's seed has already run (schema present; this script inserts
-- its own "DMS".documents rows for PO PDFs / certs / invoices / photos).
-- Reuses "Office Rentals Vendor" as one supplier and adds three
-- procurement-flavored vendors of its own.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Lookups: categories, cost centers
-- ---------------------------------------------------------------------------

INSERT INTO "PURCHASE".categories (code, name, spend_type, capex_opex) VALUES
    ('OFFICE_SUPPLIES', 'Office Supplies',        'indirect', 'opex'),
    ('PRINT_BINDING',   'Printing & Binding',     'direct',   'opex'),
    ('IT_SOFTWARE',     'IT & Software',          'indirect', 'opex'),
    ('FACILITIES',      'Facilities Maintenance', 'indirect', 'opex'),
    ('IT_HARDWARE',     'IT Hardware',            'indirect', 'capex');

INSERT INTO "PURCHASE".cost_centers (code, name) VALUES
    ('CC-ADMIN', 'Administration'),
    ('CC-LITIG', 'Litigation Practice Group'),
    ('CC-IT',    'IT & Systems');

-- ---------------------------------------------------------------------------
-- 2. New CRM vendor partners (beyond CRM_SPECS.sql's own demo data) + roles
-- ---------------------------------------------------------------------------

INSERT INTO "CRM".partners (type, name, registration_tax_id, source)
VALUES
    ('organization', 'Nusantara Office Supplies Pte',    'NPWP-01.111.222.3-000', 'manual'),
    ('organization', 'Prima Legal Print & Binding Co.',  'NPWP-01.222.333.4-000', 'manual'),
    ('organization', 'Cakrawala IT Solutions',           'NPWP-01.333.444.5-000', 'manual');

-- "Office Rentals Vendor" already got the VENDOR role in CRM_SPECS.sql's own
-- seed — only the three new partners need it here.
INSERT INTO "CRM".partner_roles (partner_id, role_type_id, assigned_by)
SELECT p.id, rt.id, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "CRM".partners p, "CRM".partner_role_types rt
WHERE p.name IN ('Nusantara Office Supplies Pte', 'Prima Legal Print & Binding Co.',
                  'Cakrawala IT Solutions')
  AND rt.code = 'VENDOR';

-- ---------------------------------------------------------------------------
-- 3. Supporting DMS documents (PO PDFs, vendor cert, contract, invoices, photo)
-- ---------------------------------------------------------------------------

INSERT INTO "DMS".documents (title, subject_type) VALUES
    ('PO-2026-0001.pdf', 'purchase.pur_order_hdrs'),
    ('PO-2026-0002.pdf', 'purchase.pur_order_hdrs'),
    ('Nusantara Office Supplies - COI 2026.pdf', 'purchase.pur_vendor_documents'),
    ('Cakrawala IT - Master Services Agreement.pdf', 'purchase.pur_contract_hdrs'),
    ('INV-NOS-88213.pdf', 'purchase.pur_invoice_hdrs'),
    ('INV-CIT-4471.pdf', 'purchase.pur_invoice_hdrs'),
    ('GR-0002 delivery photo.jpg', 'purchase.pur_receipt_lines');

-- ---------------------------------------------------------------------------
-- 4. Vendor profiles + compliance documents
-- ---------------------------------------------------------------------------

INSERT INTO "PURCHASE".vendor_profiles
    (partner_id, payment_terms, incoterms, preferred_currency, tax_registration_no, preferred_status, onboarding_status)
SELECT p.id, terms.payment_terms, terms.incoterms, terms.currency, p.registration_tax_id, terms.preferred, terms.onboarding
FROM "CRM".partners p
JOIN (VALUES
    ('Nusantara Office Supplies Pte',   'Net 30', 'DAP', 'IDR', TRUE,  'active'),
    ('Prima Legal Print & Binding Co.', 'Net 14', 'DAP', 'IDR', TRUE,  'active'),
    ('Cakrawala IT Solutions',          'Net 30', NULL,  'USD', FALSE, 'active'),
    ('Office Rentals Vendor',           'Net 45', NULL,  'IDR', FALSE, 'pending')
) AS terms (name, payment_terms, incoterms, currency, preferred, onboarding) ON terms.name = p.name;

INSERT INTO "PURCHASE".pur_vendor_documents (vendor_profile_id, doc_type, document_id, expiry_date, status)
SELECT vp.id, 'insurance_coi', d.id, DATE '2026-12-31', 'valid'
FROM "PURCHASE".vendor_profiles vp
JOIN "CRM".partners p ON p.id = vp.partner_id AND p.name = 'Nusantara Office Supplies Pte'
JOIN "DMS".documents d ON d.title = 'Nusantara Office Supplies - COI 2026.pdf'
UNION ALL
SELECT vp.id, 'msa_contract', d.id, DATE '2027-06-30', 'valid'
FROM "PURCHASE".vendor_profiles vp
JOIN "CRM".partners p ON p.id = vp.partner_id AND p.name = 'Cakrawala IT Solutions'
JOIN "DMS".documents d ON d.title = 'Cakrawala IT - Master Services Agreement.pdf'
UNION ALL
SELECT vp.id, 'business_license', NULL::bigint, DATE '2026-08-15', 'expiring_soon'
FROM "PURCHASE".vendor_profiles vp
JOIN "CRM".partners p ON p.id = vp.partner_id AND p.name = 'Office Rentals Vendor';

-- ---------------------------------------------------------------------------
-- 5. Catalog items
-- ---------------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_catalog_items
    (item_code, description, category_id, unit_of_measure, preferred_partner_id, negotiated_price, currency,
     price_valid_from, price_valid_to, source)
SELECT i.item_code, i.description, c.id, i.uom, p.id, i.price, i.currency, DATE '2026-01-01', DATE '2026-12-31', i.source
FROM (VALUES
    ('OS-A4-PAPER-80G',   'A4 Copy Paper 80gsm, 5-ream box',      'OFFICE_SUPPLIES', 'BOX', 'Nusantara Office Supplies Pte',   185000::numeric, 'IDR', 'manual'),
    ('OS-TONER-HP26A',    'HP 26A Compatible Toner Cartridge',    'OFFICE_SUPPLIES', 'EA',  'Nusantara Office Supplies Pte',   420000::numeric, 'IDR', 'manual'),
    ('PB-CASEBIND-STD',   'Standard Case Binding, per volume',    'PRINT_BINDING',   'EA',  'Prima Legal Print & Binding Co.',  65000::numeric, 'IDR', 'rfx_award'),
    ('IT-SAAS-DISCOVERY', 'Global Discovery SaaS seat, monthly',  'IT_SOFTWARE',      'EA',  'Cakrawala IT Solutions',              45::numeric, 'USD', 'manual')
) AS i (item_code, description, cat_code, uom, vendor_name, price, currency, source)
JOIN "PURCHASE".categories c ON c.code = i.cat_code
JOIN "CRM".partners p ON p.name = i.vendor_name;

-- ---------------------------------------------------------------------------
-- 6. Budgets (soft check, MVP)
-- ---------------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_budgets (cost_center_id, category_id, period_start, period_end, budget_amount, currency, consumed_amount)
SELECT cc.id, cat.id, DATE '2026-01-01', DATE '2026-12-31', b.amount, b.currency, b.consumed
FROM (VALUES
    ('CC-ADMIN', 'OFFICE_SUPPLIES', 30000000::numeric, 'IDR', 4250000::numeric),
    ('CC-LITIG', 'PRINT_BINDING',   20000000::numeric, 'IDR', 6500000::numeric),
    ('CC-IT',    'IT_SOFTWARE',         15000::numeric, 'USD',    12800::numeric)
) AS b (cc_code, cat_code, amount, currency, consumed)
JOIN "PURCHASE".cost_centers cc ON cc.code = b.cc_code
JOIN "PURCHASE".categories cat ON cat.code = b.cat_code;

-- ---------------------------------------------------------------------------
-- 7. Requisition 1 (routine, catalog-based, no RFQ) -> PO 1 -> full match
-- ---------------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_requisition_hdrs (requester_id, cost_center_id, needed_by, status, wne_workflow_instance_id)
SELECT (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), cc.id, DATE '2026-08-01', 'converted', 2
FROM "PURCHASE".cost_centers cc WHERE cc.code = 'CC-ADMIN';

INSERT INTO "PURCHASE".pur_requisition_lines (requisition_id, line_no, catalog_item_id, description, category_id, quantity, estimated_unit_price)
SELECT r.id, 1, ci.id, ci.description, ci.category_id, 10, ci.negotiated_price
FROM "PURCHASE".pur_requisition_hdrs r, "PURCHASE".pur_catalog_items ci
WHERE r.status = 'converted' AND ci.item_code = 'OS-A4-PAPER-80G'
UNION ALL
SELECT r.id, 2, ci.id, ci.description, ci.category_id, 4, ci.negotiated_price
FROM "PURCHASE".pur_requisition_hdrs r, "PURCHASE".pur_catalog_items ci
WHERE r.status = 'converted' AND ci.item_code = 'OS-TONER-HP26A';

INSERT INTO "PURCHASE".pur_order_hdrs
    (po_number, supplier_partner_id, requisition_id, currency, payment_terms, status, wne_workflow_instance_id, document_id, created_by)
SELECT 'PO-2026-0001', p.id, r.id, 'IDR', 'Net 30', 'received', 3, d.id,
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "CRM".partners p, "PURCHASE".pur_requisition_hdrs r, "DMS".documents d
WHERE p.name = 'Nusantara Office Supplies Pte' AND r.status = 'converted' AND d.title = 'PO-2026-0001.pdf';

INSERT INTO "PURCHASE".pur_order_lines (order_id, line_no, catalog_item_id, description, quantity, unit_price, expected_delivery_date, category_id)
SELECT o.id, 1, ci.id, ci.description, 10, 185000, DATE '2026-07-25', ci.category_id
FROM "PURCHASE".pur_order_hdrs o, "PURCHASE".pur_catalog_items ci
WHERE o.po_number = 'PO-2026-0001' AND ci.item_code = 'OS-A4-PAPER-80G'
UNION ALL
SELECT o.id, 2, ci.id, ci.description, 4, 420000, DATE '2026-07-25', ci.category_id
FROM "PURCHASE".pur_order_hdrs o, "PURCHASE".pur_catalog_items ci
WHERE o.po_number = 'PO-2026-0001' AND ci.item_code = 'OS-TONER-HP26A';

-- Fully received, on time
INSERT INTO "PURCHASE".pur_receipt_hdrs (order_id, receiver_id, received_at, notes)
SELECT o.id, (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), TIMESTAMPTZ '2026-07-24 10:15:00+07',
       'Delivered complete, no damage.'
FROM "PURCHASE".pur_order_hdrs o WHERE o.po_number = 'PO-2026-0001';

INSERT INTO "PURCHASE".pur_receipt_lines (receipt_id, order_line_id, quantity_received)
SELECT rh.id, ol.id, ol.quantity
FROM "PURCHASE".pur_receipt_hdrs rh
JOIN "PURCHASE".pur_order_hdrs o ON o.id = rh.order_id AND o.po_number = 'PO-2026-0001'
JOIN "PURCHASE".pur_order_lines ol ON ol.order_id = o.id;

INSERT INTO "PURCHASE".pur_invoice_hdrs
    (order_id, supplier_invoice_no, supplier_invoice_date, currency, amount, document_id, submission_channel, status, wne_workflow_instance_id)
SELECT o.id, 'INV-NOS-88213', DATE '2026-07-25', 'IDR', 3530000, d.id, 'supplier_link', 'approved_for_payment', 4
FROM "PURCHASE".pur_order_hdrs o, "DMS".documents d
WHERE o.po_number = 'PO-2026-0001' AND d.title = 'INV-NOS-88213.pdf';

INSERT INTO "PURCHASE".pur_invoice_lines (invoice_id, order_line_id, quantity, unit_price)
SELECT ih.id, ol.id, ol.quantity, ol.unit_price
FROM "PURCHASE".pur_invoice_hdrs ih
JOIN "PURCHASE".pur_order_hdrs o ON o.id = ih.order_id AND o.po_number = 'PO-2026-0001'
JOIN "PURCHASE".pur_order_lines ol ON ol.order_id = o.id;

INSERT INTO "PURCHASE".pur_invoice_matches (invoice_id, order_line_id, receipt_line_id, qty_variance, price_variance, within_tolerance)
SELECT ih.id, ol.id, rl.id, 0, 0, TRUE
FROM "PURCHASE".pur_invoice_hdrs ih
JOIN "PURCHASE".pur_order_hdrs o ON o.id = ih.order_id AND o.po_number = 'PO-2026-0001'
JOIN "PURCHASE".pur_order_lines ol ON ol.order_id = o.id
JOIN "PURCHASE".pur_receipt_lines rl ON rl.order_line_id = ol.id;

-- ---------------------------------------------------------------------------
-- 8. Requisition 2 -> RFQ -> awarded PO 2 -> partial receipt -> price-variance
--    mismatch (demonstrates the sourcing flow and the exception engine, §3K)
-- ---------------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_requisition_hdrs (requester_id, cost_center_id, needed_by, status)
SELECT (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), cc.id, DATE '2026-08-15', 'converted'
FROM "PURCHASE".cost_centers cc WHERE cc.code = 'CC-LITIG';

INSERT INTO "PURCHASE".pur_requisition_lines (requisition_id, line_no, description, category_id, quantity, estimated_unit_price)
SELECT r.id, 1, 'Case binding for trial exhibit volumes (approx. 40 volumes)', cat.id, 40, 65000
FROM "PURCHASE".pur_requisition_hdrs r, "PURCHASE".cost_centers cc, "PURCHASE".categories cat
WHERE r.cost_center_id = cc.id AND cc.code = 'CC-LITIG' AND r.status = 'converted' AND cat.code = 'PRINT_BINDING'
  AND NOT EXISTS (SELECT 1 FROM "PURCHASE".pur_requisition_lines WHERE requisition_id = r.id);

INSERT INTO "PURCHASE".pur_rfx_hdrs (requisition_id, rfx_type, title, due_at, status, buyer_id)
SELECT r.id, 'rfq', 'RFQ - Trial Exhibit Binding Aug 2026', TIMESTAMPTZ '2026-07-20 17:00:00+07', 'awarded',
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "PURCHASE".pur_requisition_hdrs r
JOIN "PURCHASE".pur_requisition_lines rl ON rl.requisition_id = r.id AND rl.description LIKE 'Case binding%';

INSERT INTO "PURCHASE".pur_rfx_lines (rfx_id, line_no, description, quantity)
SELECT x.id, 1, 'Standard case binding, per volume', 40
FROM "PURCHASE".pur_rfx_hdrs x WHERE x.title = 'RFQ - Trial Exhibit Binding Aug 2026';

INSERT INTO "PURCHASE".pur_rfx_invitations (rfx_id, partner_id, invited_at, responded_at)
SELECT x.id, p.id, TIMESTAMPTZ '2026-07-14 09:00:00+07', invite.responded_at
FROM "PURCHASE".pur_rfx_hdrs x
JOIN (VALUES
    ('Prima Legal Print & Binding Co.', TIMESTAMPTZ '2026-07-16 14:20:00+07'),
    ('Nusantara Office Supplies Pte',   NULL::timestamptz)
) AS invite (vendor_name, responded_at) ON TRUE
JOIN "CRM".partners p ON p.name = invite.vendor_name
WHERE x.title = 'RFQ - Trial Exhibit Binding Aug 2026';

INSERT INTO "PURCHASE".pur_rfx_responses (rfx_invitation_id, submitted_at, notes)
SELECT inv.id, TIMESTAMPTZ '2026-07-16 14:20:00+07', 'Can turn around in 3 business days for volumes over 20.'
FROM "PURCHASE".pur_rfx_invitations inv
JOIN "PURCHASE".pur_rfx_hdrs x ON x.id = inv.rfx_id AND x.title = 'RFQ - Trial Exhibit Binding Aug 2026'
JOIN "CRM".partners p ON p.id = inv.partner_id AND p.name = 'Prima Legal Print & Binding Co.';

INSERT INTO "PURCHASE".pur_rfx_response_lines (rfx_response_id, rfx_line_id, unit_price, lead_time_days)
SELECT resp.id, xl.id, 65000, 3
FROM "PURCHASE".pur_rfx_responses resp
JOIN "PURCHASE".pur_rfx_invitations inv ON inv.id = resp.rfx_invitation_id
JOIN "PURCHASE".pur_rfx_hdrs x ON x.id = inv.rfx_id AND x.title = 'RFQ - Trial Exhibit Binding Aug 2026'
JOIN "PURCHASE".pur_rfx_lines xl ON xl.rfx_id = x.id;

INSERT INTO "PURCHASE".pur_order_hdrs
    (po_number, supplier_partner_id, rfx_id, requisition_id, currency, payment_terms, status, wne_workflow_instance_id, document_id, created_by)
SELECT 'PO-2026-0002', p.id, x.id, x.requisition_id, 'IDR', 'Net 14', 'partially_received', 5, d.id,
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "PURCHASE".pur_rfx_hdrs x, "CRM".partners p, "DMS".documents d
WHERE x.title = 'RFQ - Trial Exhibit Binding Aug 2026' AND p.name = 'Prima Legal Print & Binding Co.'
  AND d.title = 'PO-2026-0002.pdf';

INSERT INTO "PURCHASE".pur_order_lines (order_id, line_no, catalog_item_id, description, quantity, unit_price, expected_delivery_date, category_id)
SELECT o.id, 1, ci.id, 'Standard case binding, per volume', 40, 65000, DATE '2026-08-10', ci.category_id
FROM "PURCHASE".pur_order_hdrs o, "PURCHASE".pur_catalog_items ci
WHERE o.po_number = 'PO-2026-0002' AND ci.item_code = 'PB-CASEBIND-STD';

-- Partial, slightly late receipt -> feeds the late_delivery exception below
INSERT INTO "PURCHASE".pur_receipt_hdrs (order_id, receiver_id, received_at, notes)
SELECT o.id, (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), TIMESTAMPTZ '2026-08-13 16:40:00+07',
       'First batch of 25 volumes received; remainder due next week.'
FROM "PURCHASE".pur_order_hdrs o WHERE o.po_number = 'PO-2026-0002';

INSERT INTO "PURCHASE".pur_receipt_lines (receipt_id, order_line_id, quantity_received, photo_document_id)
SELECT rh.id, ol.id, 25, d.id
FROM "PURCHASE".pur_receipt_hdrs rh
JOIN "PURCHASE".pur_order_hdrs o ON o.id = rh.order_id AND o.po_number = 'PO-2026-0002'
JOIN "PURCHASE".pur_order_lines ol ON ol.order_id = o.id
JOIN "DMS".documents d ON d.title = 'GR-0002 delivery photo.jpg';

-- Invoice for the partial batch, priced above the awarded RFQ rate -> price variance
INSERT INTO "PURCHASE".pur_invoice_hdrs (order_id, supplier_invoice_no, supplier_invoice_date, currency, amount, document_id, submission_channel, status)
SELECT o.id, 'INV-CIT-4471', DATE '2026-08-14', 'IDR', 1750000, d.id, 'manual', 'mismatched'
FROM "PURCHASE".pur_order_hdrs o, "DMS".documents d
WHERE o.po_number = 'PO-2026-0002' AND d.title = 'INV-CIT-4471.pdf';

INSERT INTO "PURCHASE".pur_invoice_lines (invoice_id, order_line_id, quantity, unit_price)
SELECT ih.id, ol.id, 25, 70000  -- invoiced at 70,000 vs. awarded 65,000/unit
FROM "PURCHASE".pur_invoice_hdrs ih
JOIN "PURCHASE".pur_order_hdrs o ON o.id = ih.order_id AND o.po_number = 'PO-2026-0002'
JOIN "PURCHASE".pur_order_lines ol ON ol.order_id = o.id;

INSERT INTO "PURCHASE".pur_invoice_matches (invoice_id, order_line_id, receipt_line_id, qty_variance, price_variance, within_tolerance)
SELECT ih.id, ol.id, rl.id, 0, 5000, FALSE
FROM "PURCHASE".pur_invoice_hdrs ih
JOIN "PURCHASE".pur_order_hdrs o ON o.id = ih.order_id AND o.po_number = 'PO-2026-0002'
JOIN "PURCHASE".pur_order_lines ol ON ol.order_id = o.id
JOIN "PURCHASE".pur_receipt_lines rl ON rl.order_line_id = ol.id;

-- ---------------------------------------------------------------------------
-- 9. Contract (Cakrawala IT - MSA, expiring soon, to demo the renewal reminder)
-- ---------------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_contract_hdrs
    (supplier_partner_id, title, contract_type, contract_value, currency, start_date, end_date, auto_renewal,
     renewal_notice_days, document_id, status, owner_id, sched_item_id)
SELECT p.id, 'Cakrawala IT Solutions - Master Services Agreement', 'framework', 240000000, 'IDR',
       DATE '2025-09-01', DATE '2026-08-31', TRUE, 90, d.id, 'expiring_soon',
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), 14 -- references a SCHEDULE.sched_items row
FROM "CRM".partners p, "DMS".documents d
WHERE p.name = 'Cakrawala IT Solutions' AND d.title = 'Cakrawala IT - Master Services Agreement.pdf';

-- ---------------------------------------------------------------------------
-- 10. Exceptions (mirrors the mismatches/flags seeded above)
-- ---------------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_exceptions (exception_type, subject_type, subject_id, detected_at, status, notes)
SELECT 'unmatched_invoice', 'invoice', ih.id, TIMESTAMPTZ '2026-08-14 09:05:00+07', 'open',
       'Invoiced unit price 70,000 exceeds awarded RFQ rate of 65,000 by more than 5% tolerance.'
FROM "PURCHASE".pur_invoice_hdrs ih WHERE ih.supplier_invoice_no = 'INV-CIT-4471'
UNION ALL
SELECT 'price_variance', 'invoice', ih.id, TIMESTAMPTZ '2026-08-14 09:05:00+07', 'open',
       'Line 1: price variance of IDR 5,000/unit against PO-2026-0002.'
FROM "PURCHASE".pur_invoice_hdrs ih WHERE ih.supplier_invoice_no = 'INV-CIT-4471'
UNION ALL
SELECT 'late_delivery', 'order', o.id, TIMESTAMPTZ '2026-08-13 16:45:00+07', 'acknowledged',
       'Remaining 15 of 40 volumes still outstanding past first expected delivery window.'
FROM "PURCHASE".pur_order_hdrs o WHERE o.po_number = 'PO-2026-0002';

COMMIT;

-- =============================================================================
-- Sanity check counts
-- =============================================================================
SELECT 'vendor_profiles' AS table_name, COUNT(*) FROM "PURCHASE".vendor_profiles
UNION ALL SELECT 'pur_catalog_items', COUNT(*) FROM "PURCHASE".pur_catalog_items
UNION ALL SELECT 'pur_requisition_hdrs', COUNT(*) FROM "PURCHASE".pur_requisition_hdrs
UNION ALL SELECT 'pur_rfx_hdrs', COUNT(*) FROM "PURCHASE".pur_rfx_hdrs
UNION ALL SELECT 'pur_order_hdrs', COUNT(*) FROM "PURCHASE".pur_order_hdrs
UNION ALL SELECT 'pur_receipt_hdrs', COUNT(*) FROM "PURCHASE".pur_receipt_hdrs
UNION ALL SELECT 'pur_invoice_hdrs', COUNT(*) FROM "PURCHASE".pur_invoice_hdrs
UNION ALL SELECT 'pur_contract_hdrs', COUNT(*) FROM "PURCHASE".pur_contract_hdrs
UNION ALL SELECT 'pur_exceptions', COUNT(*) FROM "PURCHASE".pur_exceptions
UNION ALL SELECT 'pur_budgets', COUNT(*) FROM "PURCHASE".pur_budgets;
