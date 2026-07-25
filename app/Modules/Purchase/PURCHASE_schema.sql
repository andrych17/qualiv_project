-- =====================================================================
-- PURCHASE MODULE — SCHEMA SCRIPT
-- Purchase & Procurement System — Core Shared Module
-- Target: PostgreSQL 16, one schema per module inside a tenant DB
-- No tenant_id column anywhere — isolation is the DB-per-tenant boundary
-- (CLAUDE.md §4/§7). Built clean against that rule per PURCHASE_SPECS.md §5.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. Extensions
-- ---------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid()

-- ---------------------------------------------------------------------
-- 0A. PREREQUISITE STUBS
-- These represent tables owned by OTHER modules (public.users = Laravel
-- auth, "CRM".partners* = CRM module, "DMS".documents = DMS module).
-- They are included here ONLY so this script can be validated standalone
-- against a live database. In the real codebase they already exist via
-- their own module's migrations/scripts (see CRM_SPECS.md / DMS_SPECS.md)
-- — do NOT re-run this stub section against an environment where those
-- modules are already installed.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.users (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    email       VARCHAR(150) NOT NULL UNIQUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE SCHEMA IF NOT EXISTS "CRM";

CREATE TABLE IF NOT EXISTS "CRM".partners (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
    type                    VARCHAR(20) NOT NULL CHECK (type IN ('individual','organization')),
    parent_partner_id       BIGINT REFERENCES "CRM".partners(id),
    name                    VARCHAR(200) NOT NULL,
    is_active               BOOLEAN NOT NULL DEFAULT true,
    merged_into_partner_id  BIGINT REFERENCES "CRM".partners(id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "CRM".partner_role_types (
    id      BIGSERIAL PRIMARY KEY,
    code    VARCHAR(50) NOT NULL UNIQUE,
    label   VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS "CRM".partner_roles (
    id            BIGSERIAL PRIMARY KEY,
    partner_id    BIGINT NOT NULL REFERENCES "CRM".partners(id),
    role_type_id  BIGINT NOT NULL REFERENCES "CRM".partner_role_types(id),
    assigned_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    assigned_by   BIGINT REFERENCES public.users(id),
    is_active     BOOLEAN NOT NULL DEFAULT true,
    UNIQUE (partner_id, role_type_id)
);

CREATE SCHEMA IF NOT EXISTS "DMS";

CREATE TABLE IF NOT EXISTS "DMS".documents (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid(),
    title               VARCHAR(255) NOT NULL,
    current_version_no  INT NOT NULL DEFAULT 1,
    lifecycle_state     VARCHAR(20) NOT NULL DEFAULT 'active',
    owning_module       VARCHAR(50),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =====================================================================
-- 1. PURCHASE SCHEMA
-- =====================================================================
CREATE SCHEMA IF NOT EXISTS "PURCHASE";

-- ---------------------------------------------------------------------
-- 1A. Master / lookup tables
-- ---------------------------------------------------------------------

-- Vendor Profile — 1:1 extension of CRM.partners (never a duplicate
-- vendor master). A partner only gets a row here once it holds the
-- Vendor role in CRM.
CREATE TABLE "PURCHASE".vendor_profiles (
    id                          BIGSERIAL PRIMARY KEY,
    partner_id                  BIGINT NOT NULL UNIQUE REFERENCES "CRM".partners(id),
    payment_terms                VARCHAR(100),
    incoterms                    VARCHAR(20),
    preferred_currency           VARCHAR(3) NOT NULL DEFAULT 'USD',
    tax_registration_no          VARCHAR(100),
    bank_name                    VARCHAR(150),
    bank_account_name            VARCHAR(150),
    bank_account_no_encrypted    TEXT,           -- app-layer encrypted at rest
    preferred_status             BOOLEAN NOT NULL DEFAULT false,
    onboarding_status            VARCHAR(20) NOT NULL DEFAULT 'pending'
                                    CHECK (onboarding_status IN ('pending','active','suspended')),
    created_at                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Tenant-editable spend category lookup (direct/indirect, CAPEX/OPEX)
CREATE TABLE "PURCHASE".categories (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    spend_type  VARCHAR(10) NOT NULL CHECK (spend_type IN ('direct','indirect')),
    capex_opex  VARCHAR(10) NOT NULL CHECK (capex_opex IN ('capex','opex')),
    is_active   BOOLEAN NOT NULL DEFAULT true
);

-- Tenant-editable cost center / department lookup
CREATE TABLE "PURCHASE".cost_centers (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT true
);

-- ---------------------------------------------------------------------
-- 1B. Vendor documents (certs / licenses / insurance — MVP compliance)
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_vendor_documents (
    id                  BIGSERIAL PRIMARY KEY,
    vendor_profile_id   BIGINT NOT NULL REFERENCES "PURCHASE".vendor_profiles(id) ON DELETE CASCADE,
    doc_type            VARCHAR(50) NOT NULL, -- insurance, business_license, tax_cert, other
    document_id         BIGINT REFERENCES "DMS".documents(id), -- nullable: DMS is optional
    expiry_date         DATE,
    status              VARCHAR(20) NOT NULL DEFAULT 'valid'
                            CHECK (status IN ('valid','expiring_soon','expired')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 1C. Catalog
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_catalog_items (
    id                  BIGSERIAL PRIMARY KEY,
    item_code           VARCHAR(50) NOT NULL UNIQUE,
    description         VARCHAR(255) NOT NULL,
    category_id         BIGINT REFERENCES "PURCHASE".categories(id),
    unit_of_measure     VARCHAR(20) NOT NULL DEFAULT 'EA',
    preferred_partner_id BIGINT REFERENCES "CRM".partners(id),
    negotiated_price    NUMERIC(14,2),
    currency            VARCHAR(3) NOT NULL DEFAULT 'USD',
    price_valid_from    DATE,
    price_valid_to      DATE,
    source              VARCHAR(20) NOT NULL DEFAULT 'manual'
                            CHECK (source IN ('manual','rfx_award')),
    is_active           BOOLEAN NOT NULL DEFAULT true,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 1D. Purchase Requisition (PR)
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_requisition_hdrs (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
    requester_id            BIGINT NOT NULL REFERENCES public.users(id),
    cost_center_id          BIGINT REFERENCES "PURCHASE".cost_centers(id),
    needed_by               DATE,
    status                  VARCHAR(20) NOT NULL DEFAULT 'draft'
                                CHECK (status IN ('draft','pending_approval','approved','rejected','converted','cancelled')),
    subject_type            VARCHAR(100), -- optional polymorphic link (e.g. 'legal.case_hdrs')
    subject_id              BIGINT,
    wne_workflow_instance_id BIGINT,      -- informational only; WNE is a peer Core module, no hard FK
    duplicate_flag          BOOLEAN NOT NULL DEFAULT false,
    budget_flag             BOOLEAN NOT NULL DEFAULT false,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PURCHASE".pur_requisition_lines (
    id                      BIGSERIAL PRIMARY KEY,
    requisition_id          BIGINT NOT NULL REFERENCES "PURCHASE".pur_requisition_hdrs(id) ON DELETE CASCADE,
    line_no                 INT NOT NULL,
    catalog_item_id         BIGINT REFERENCES "PURCHASE".pur_catalog_items(id),
    description              VARCHAR(255) NOT NULL,
    category_id              BIGINT REFERENCES "PURCHASE".categories(id),
    quantity                 NUMERIC(14,3) NOT NULL,
    estimated_unit_price     NUMERIC(14,2),
    estimated_line_total     NUMERIC(14,2) GENERATED ALWAYS AS (quantity * COALESCE(estimated_unit_price,0)) STORED,
    UNIQUE (requisition_id, line_no)
);

-- ---------------------------------------------------------------------
-- 1E. Sourcing / RFx (MVP: RFQ only, flat comparison)
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_rfx_hdrs (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
    requisition_id  BIGINT REFERENCES "PURCHASE".pur_requisition_hdrs(id),
    rfx_type        VARCHAR(10) NOT NULL DEFAULT 'rfq' CHECK (rfx_type IN ('rfi','rfq','rfp')), -- MVP: 'rfq' only
    title           VARCHAR(200) NOT NULL,
    due_at          TIMESTAMPTZ NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft'
                        CHECK (status IN ('draft','open','closed','awarded','cancelled')),
    buyer_id        BIGINT REFERENCES public.users(id),
    sched_item_id   BIGINT, -- informational link to a Schedule reminder for the due date
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PURCHASE".pur_rfx_lines (
    id           BIGSERIAL PRIMARY KEY,
    rfx_id       BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_hdrs(id) ON DELETE CASCADE,
    line_no      INT NOT NULL,
    description  VARCHAR(255) NOT NULL,
    quantity     NUMERIC(14,3) NOT NULL,
    UNIQUE (rfx_id, line_no)
);

-- Lightweight "supplier portal" for MVP: a signed response token per
-- invited supplier, no supplier login/session required.
CREATE TABLE "PURCHASE".pur_rfx_invitations (
    id              BIGSERIAL PRIMARY KEY,
    rfx_id          BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_hdrs(id) ON DELETE CASCADE,
    partner_id      BIGINT NOT NULL REFERENCES "CRM".partners(id),
    invited_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    response_token  UUID NOT NULL DEFAULT gen_random_uuid(),
    responded_at    TIMESTAMPTZ,
    UNIQUE (rfx_id, partner_id)
);

CREATE TABLE "PURCHASE".pur_rfx_responses (
    id                     BIGSERIAL PRIMARY KEY,
    rfx_invitation_id      BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_invitations(id) ON DELETE CASCADE,
    submitted_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    notes                  TEXT,
    document_id            BIGINT REFERENCES "DMS".documents(id)
);

CREATE TABLE "PURCHASE".pur_rfx_response_lines (
    id                BIGSERIAL PRIMARY KEY,
    rfx_response_id   BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_responses(id) ON DELETE CASCADE,
    rfx_line_id       BIGINT NOT NULL REFERENCES "PURCHASE".pur_rfx_lines(id),
    unit_price        NUMERIC(14,2) NOT NULL,
    lead_time_days    INT,
    notes             VARCHAR(255),
    UNIQUE (rfx_response_id, rfx_line_id)
);

-- ---------------------------------------------------------------------
-- 1F. Purchase Order (PO)
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_order_hdrs (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
    po_number               VARCHAR(30) NOT NULL UNIQUE,
    supplier_partner_id     BIGINT NOT NULL REFERENCES "CRM".partners(id),
    rfx_id                  BIGINT REFERENCES "PURCHASE".pur_rfx_hdrs(id),
    requisition_id          BIGINT REFERENCES "PURCHASE".pur_requisition_hdrs(id),
    currency                VARCHAR(3) NOT NULL DEFAULT 'USD',
    incoterms               VARCHAR(20),
    payment_terms           VARCHAR(100),
    status                  VARCHAR(20) NOT NULL DEFAULT 'draft'
                                CHECK (status IN ('draft','pending_approval','approved','sent','acknowledged',
                                                   'partially_received','received','closed','cancelled')),
    wne_workflow_instance_id BIGINT, -- informational only
    revision_no             INT NOT NULL DEFAULT 0,
    document_id              BIGINT REFERENCES "DMS".documents(id), -- generated PO PDF
    created_by               BIGINT REFERENCES public.users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PURCHASE".pur_order_lines (
    id                      BIGSERIAL PRIMARY KEY,
    order_id                BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_hdrs(id) ON DELETE CASCADE,
    line_no                  INT NOT NULL,
    catalog_item_id           BIGINT REFERENCES "PURCHASE".pur_catalog_items(id),
    description                VARCHAR(255) NOT NULL,
    quantity                    NUMERIC(14,3) NOT NULL,
    unit_price                   NUMERIC(14,2) NOT NULL,
    line_total                    NUMERIC(14,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    expected_delivery_date         DATE,
    tax_amount                      NUMERIC(14,2) NOT NULL DEFAULT 0,
    category_id                      BIGINT REFERENCES "PURCHASE".categories(id),
    UNIQUE (order_id, line_no)
);

-- Amendment history — a PO is never silently edited after 'sent'
CREATE TABLE "PURCHASE".pur_order_revisions (
    id           BIGSERIAL PRIMARY KEY,
    order_id     BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_hdrs(id) ON DELETE CASCADE,
    revision_no  INT NOT NULL,
    snapshot     JSONB NOT NULL, -- full header+lines snapshot at time of amendment
    reason       VARCHAR(255),
    revised_by   BIGINT REFERENCES public.users(id),
    revised_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (order_id, revision_no)
);

-- ---------------------------------------------------------------------
-- 1G. Goods Receipt (GR)
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_receipt_hdrs (
    id           BIGSERIAL PRIMARY KEY,
    uuid         UUID NOT NULL DEFAULT gen_random_uuid(),
    order_id     BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_hdrs(id),
    receiver_id  BIGINT REFERENCES public.users(id),
    received_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    notes        TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "PURCHASE".pur_receipt_lines (
    id                  BIGSERIAL PRIMARY KEY,
    receipt_id          BIGINT NOT NULL REFERENCES "PURCHASE".pur_receipt_hdrs(id) ON DELETE CASCADE,
    order_line_id       BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_lines(id),
    quantity_received   NUMERIC(14,3) NOT NULL,
    condition_notes     VARCHAR(255),
    photo_document_id   BIGINT REFERENCES "DMS".documents(id),
    is_over_receipt     BOOLEAN NOT NULL DEFAULT false
);

-- ---------------------------------------------------------------------
-- 1H. Invoice Capture & Three-Way Match
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_invoice_hdrs (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
    order_id                BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_hdrs(id),
    supplier_invoice_no     VARCHAR(50) NOT NULL,
    supplier_invoice_date   DATE NOT NULL,
    currency                VARCHAR(3) NOT NULL DEFAULT 'USD',
    amount                  NUMERIC(14,2) NOT NULL,
    document_id             BIGINT REFERENCES "DMS".documents(id),
    submission_channel      VARCHAR(20) NOT NULL DEFAULT 'manual'
                                CHECK (submission_channel IN ('manual','supplier_link')),
    status                  VARCHAR(20) NOT NULL DEFAULT 'submitted'
                                CHECK (status IN ('submitted','matched','mismatched','approved_for_payment','rejected')),
    wne_workflow_instance_id BIGINT, -- informational only
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (order_id, supplier_invoice_no)
);

CREATE TABLE "PURCHASE".pur_invoice_lines (
    id             BIGSERIAL PRIMARY KEY,
    invoice_id     BIGINT NOT NULL REFERENCES "PURCHASE".pur_invoice_hdrs(id) ON DELETE CASCADE,
    order_line_id  BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_lines(id),
    quantity       NUMERIC(14,3) NOT NULL,
    unit_price     NUMERIC(14,2) NOT NULL,
    line_total     NUMERIC(14,2) GENERATED ALWAYS AS (quantity * unit_price) STORED
);

CREATE TABLE "PURCHASE".pur_invoice_matches (
    id               BIGSERIAL PRIMARY KEY,
    invoice_id       BIGINT NOT NULL REFERENCES "PURCHASE".pur_invoice_hdrs(id) ON DELETE CASCADE,
    order_line_id    BIGINT NOT NULL REFERENCES "PURCHASE".pur_order_lines(id),
    receipt_line_id  BIGINT REFERENCES "PURCHASE".pur_receipt_lines(id),
    qty_variance     NUMERIC(14,3) NOT NULL DEFAULT 0,
    price_variance   NUMERIC(14,2) NOT NULL DEFAULT 0,
    within_tolerance BOOLEAN NOT NULL DEFAULT true,
    matched_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 1I. Contract Management
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_contract_hdrs (
    id                     BIGSERIAL PRIMARY KEY,
    uuid                   UUID NOT NULL DEFAULT gen_random_uuid(),
    supplier_partner_id    BIGINT NOT NULL REFERENCES "CRM".partners(id),
    title                  VARCHAR(200) NOT NULL,
    contract_type          VARCHAR(20) NOT NULL CHECK (contract_type IN ('framework','blanket','project')),
    contract_value         NUMERIC(14,2),
    currency               VARCHAR(3) NOT NULL DEFAULT 'USD',
    start_date             DATE NOT NULL,
    end_date               DATE NOT NULL,
    auto_renewal           BOOLEAN NOT NULL DEFAULT false,
    renewal_notice_days    INT DEFAULT 90,
    document_id            BIGINT REFERENCES "DMS".documents(id),
    status                 VARCHAR(20) NOT NULL DEFAULT 'draft'
                              CHECK (status IN ('draft','active','expiring_soon','expired','renewed','terminated')),
    owner_id               BIGINT REFERENCES public.users(id),
    sched_item_id           BIGINT, -- informational link to Schedule renewal reminder
    local_content_pct        NUMERIC(5,2), -- MVP ESG placeholder: free-form %, no enforcement
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (end_date > start_date)
);

-- ---------------------------------------------------------------------
-- 1J. Exception Management (single log feeding the dashboard + WNE)
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_exceptions (
    id              BIGSERIAL PRIMARY KEY,
    exception_type  VARCHAR(30) NOT NULL
                        CHECK (exception_type IN ('overdue_approval','late_delivery','price_variance',
                                                   'budget_flag','unmatched_invoice')),
    subject_type    VARCHAR(50) NOT NULL, -- 'requisition' | 'order' | 'invoice'
    subject_id      BIGINT NOT NULL,
    detected_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    status          VARCHAR(20) NOT NULL DEFAULT 'open'
                        CHECK (status IN ('open','acknowledged','resolved')),
    resolved_at     TIMESTAMPTZ,
    resolved_by     BIGINT REFERENCES public.users(id),
    notes           VARCHAR(255)
);

-- ---------------------------------------------------------------------
-- 1K. Budgets (soft check, MVP)
-- ---------------------------------------------------------------------
CREATE TABLE "PURCHASE".pur_budgets (
    id                BIGSERIAL PRIMARY KEY,
    cost_center_id    BIGINT NOT NULL REFERENCES "PURCHASE".cost_centers(id),
    category_id       BIGINT NOT NULL REFERENCES "PURCHASE".categories(id),
    period_start      DATE NOT NULL,
    period_end        DATE NOT NULL,
    budget_amount     NUMERIC(14,2) NOT NULL,
    currency          VARCHAR(3) NOT NULL DEFAULT 'USD',
    consumed_amount   NUMERIC(14,2) NOT NULL DEFAULT 0,
    UNIQUE (cost_center_id, category_id, period_start, period_end),
    CHECK (period_end > period_start)
);

-- =====================================================================
-- 2. INDEXES
-- (PKs/UNIQUEs already indexed automatically; these cover common lookups)
-- =====================================================================
CREATE INDEX idx_pur_vendor_documents_vendor   ON "PURCHASE".pur_vendor_documents(vendor_profile_id);
CREATE INDEX idx_pur_vendor_documents_expiry    ON "PURCHASE".pur_vendor_documents(expiry_date);
CREATE INDEX idx_pur_catalog_items_category     ON "PURCHASE".pur_catalog_items(category_id);
CREATE INDEX idx_pur_catalog_items_supplier     ON "PURCHASE".pur_catalog_items(preferred_partner_id);
CREATE INDEX idx_pur_requisition_hdrs_status    ON "PURCHASE".pur_requisition_hdrs(status);
CREATE INDEX idx_pur_requisition_hdrs_requester ON "PURCHASE".pur_requisition_hdrs(requester_id);
CREATE INDEX idx_pur_rfx_hdrs_status            ON "PURCHASE".pur_rfx_hdrs(status);
CREATE INDEX idx_pur_rfx_invitations_partner    ON "PURCHASE".pur_rfx_invitations(partner_id);
CREATE INDEX idx_pur_order_hdrs_supplier        ON "PURCHASE".pur_order_hdrs(supplier_partner_id);
CREATE INDEX idx_pur_order_hdrs_status          ON "PURCHASE".pur_order_hdrs(status);
CREATE INDEX idx_pur_order_lines_order          ON "PURCHASE".pur_order_lines(order_id);
CREATE INDEX idx_pur_receipt_hdrs_order         ON "PURCHASE".pur_receipt_hdrs(order_id);
CREATE INDEX idx_pur_invoice_hdrs_order         ON "PURCHASE".pur_invoice_hdrs(order_id);
CREATE INDEX idx_pur_invoice_hdrs_status        ON "PURCHASE".pur_invoice_hdrs(status);
CREATE INDEX idx_pur_contract_hdrs_supplier     ON "PURCHASE".pur_contract_hdrs(supplier_partner_id);
CREATE INDEX idx_pur_contract_hdrs_status       ON "PURCHASE".pur_contract_hdrs(status);
CREATE INDEX idx_pur_contract_hdrs_end_date     ON "PURCHASE".pur_contract_hdrs(end_date);
CREATE INDEX idx_pur_exceptions_status          ON "PURCHASE".pur_exceptions(status);
CREATE INDEX idx_pur_exceptions_subject         ON "PURCHASE".pur_exceptions(subject_type, subject_id);
CREATE INDEX idx_pur_budgets_period             ON "PURCHASE".pur_budgets(period_start, period_end);

-- =====================================================================
-- NOTE — Future Version tables intentionally NOT created here (per
-- PURCHASE_SPECS.md §2/§5 MVP scope boundary): pur_rfx_scorecards,
-- rfx_criteria, pur_capa_records, pur_audit_records, pur_esg_scores,
-- pur_supplier_scorecards. These are additive migrations once their
-- features are actually built — none of the MVP tables above require
-- breaking changes to accommodate them later.
-- =====================================================================
