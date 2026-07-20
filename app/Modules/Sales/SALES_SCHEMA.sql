-- ============================================================================
-- SALES Module — PostgreSQL Schema
-- Schema: "SALES" (per tenant DB, per CLAUDE.md §7A — schema-per-module,
-- no tenant_id column; the tenant DB itself is the isolation boundary).
--
-- Hard dependency: "CRM" schema must already exist (Customers = CRM.partners,
-- role = Customer; Opportunities may optionally link to CRM.leads).
-- Soft dependencies (WNE / DMS / SCHEDULE) are event-driven — no schema-level
-- FK into those modules anywhere in this file, by design (see SALES_SPECS.md
-- §5 "Dependency posture").
--
-- Conventions (CLAUDE.md §7):
--   - BIGSERIAL/BIGINT for all internal PK/FK, UUID for external-facing refs.
--   - Master tables: single word. Transaction tables: domain-prefixed 2-part.
--   - Nothing is ever hard-deleted from financial/audit tables — void/cancel
--     flags and reversal rows instead.
--
-- Run order: this file assumes "CRM" schema already exists in the target DB.
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid()

CREATE SCHEMA IF NOT EXISTS "SALES";
SET search_path TO "SALES", public;

-- ============================================================================
-- 1. MASTER / LOOKUP / CONFIG TABLES
-- ============================================================================

CREATE TABLE "SALES".territories (
    id          BIGSERIAL PRIMARY KEY,
    name        TEXT NOT NULL UNIQUE,
    description TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".sales_teams (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL UNIQUE,
    territory_id  BIGINT REFERENCES "SALES".territories(id),
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".sales_team_members (
    id             BIGSERIAL PRIMARY KEY,
    sales_team_id  BIGINT NOT NULL REFERENCES "SALES".sales_teams(id),
    user_id        BIGINT NOT NULL, -- references central/tenant user, no local FK (auth is platform-level, not Sales-owned)
    role_in_team   TEXT NOT NULL DEFAULT 'member' CHECK (role_in_team IN ('lead','member')),
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (sales_team_id, user_id)
);

-- Minimal internal item/service catalog. Sales needs *something* to sell;
-- no dedicated Inventory/Items module exists yet in this project. Kept
-- deliberately thin (id, sku, name, uom, type, default price) so it can be
-- swapped for a real Items/Inventory module later via FK repoint only —
-- no other SALES table needs to change if/when that module ships.
CREATE TABLE "SALES".items (
    id             BIGSERIAL PRIMARY KEY,
    sku            TEXT NOT NULL UNIQUE,
    name           TEXT NOT NULL,
    description    TEXT,
    uom            TEXT NOT NULL DEFAULT 'unit',
    item_type      TEXT NOT NULL DEFAULT 'service' CHECK (item_type IN ('product','service')),
    default_price  NUMERIC(14,2) NOT NULL DEFAULT 0,
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".price_lists (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    currency      TEXT NOT NULL DEFAULT 'USD',
    territory_id  BIGINT REFERENCES "SALES".territories(id),
    is_default    BOOLEAN NOT NULL DEFAULT FALSE,
    valid_from    DATE,
    valid_to      DATE,
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".price_list_lines (
    id             BIGSERIAL PRIMARY KEY,
    price_list_id  BIGINT NOT NULL REFERENCES "SALES".price_lists(id) ON DELETE CASCADE,
    item_id        BIGINT NOT NULL REFERENCES "SALES".items(id),
    unit_price     NUMERIC(14,2) NOT NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (price_list_id, item_id)
);

CREATE TABLE "SALES".promo_codes (
    id              BIGSERIAL PRIMARY KEY,
    code            TEXT NOT NULL UNIQUE,
    description     TEXT,
    discount_type   TEXT NOT NULL CHECK (discount_type IN ('percentage','fixed')),
    discount_value  NUMERIC(14,2) NOT NULL,
    valid_from      DATE,
    valid_to        DATE,
    usage_limit     INT,
    usage_count     INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".commission_plans (
    id                BIGSERIAL PRIMARY KEY,
    name              TEXT NOT NULL,
    basis             TEXT NOT NULL CHECK (basis IN ('flat_percent','tiered')),
    flat_percent      NUMERIC(6,3), -- used when basis = 'flat_percent'
    applies_to_type   TEXT NOT NULL CHECK (applies_to_type IN ('team','rep')),
    applies_to_id     BIGINT NOT NULL, -- sales_teams.id or a user_id, per applies_to_type
    effective_from    DATE NOT NULL,
    effective_to      DATE,
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".commission_plan_tiers (
    id                  BIGSERIAL PRIMARY KEY,
    commission_plan_id  BIGINT NOT NULL REFERENCES "SALES".commission_plans(id) ON DELETE CASCADE,
    min_amount          NUMERIC(14,2) NOT NULL,
    max_amount          NUMERIC(14,2), -- NULL = open-ended top tier
    percent             NUMERIC(6,3) NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Sales-owned data about a CRM partner. CRM.partners is never migrated by
-- Sales — this is the seam, same "one store, many views" pattern DMS uses.
CREATE TABLE "SALES".customer_sales_profiles (
    id                    BIGSERIAL PRIMARY KEY,
    partner_id            BIGINT NOT NULL UNIQUE REFERENCES "CRM".partners(id),
    territory_id          BIGINT REFERENCES "SALES".territories(id),
    sales_team_id         BIGINT REFERENCES "SALES".sales_teams(id),
    price_list_id         BIGINT REFERENCES "SALES".price_lists(id),
    assigned_rep_user_id  BIGINT,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".customer_credit_profiles (
    id                    BIGSERIAL PRIMARY KEY,
    partner_id            BIGINT NOT NULL UNIQUE REFERENCES "CRM".partners(id),
    credit_limit          NUMERIC(14,2) NOT NULL DEFAULT 0,
    payment_terms_days    INT NOT NULL DEFAULT 30,
    on_hold               BOOLEAN NOT NULL DEFAULT FALSE,
    on_hold_reason        TEXT,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".sales_portal_tokens (
    id          BIGSERIAL PRIMARY KEY,
    partner_id  BIGINT NOT NULL REFERENCES "CRM".partners(id),
    token       UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    expires_at  TIMESTAMPTZ,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 2. OPPORTUNITIES (CRM Integration)
-- ============================================================================

CREATE TABLE "SALES".opp_hdrs (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    name                TEXT NOT NULL,
    customer_id         BIGINT REFERENCES "CRM".partners(id), -- nullable: may predate a Customer role
    lead_id             BIGINT REFERENCES "CRM".leads(id),    -- nullable: may not have come from a Lead
    stage               TEXT NOT NULL DEFAULT 'New'
                         CHECK (stage IN ('New','Qualifying','Quoted','Won','Lost')),
    owner_user_id       BIGINT,
    sales_team_id       BIGINT REFERENCES "SALES".sales_teams(id),
    estimated_value     NUMERIC(14,2),
    expected_close_date DATE,
    loss_reason         TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 3. QUOTATIONS
-- ============================================================================

CREATE TABLE "SALES".quot_hdrs (
    id               BIGSERIAL PRIMARY KEY,
    uuid             UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    quote_no         TEXT NOT NULL UNIQUE,
    customer_id      BIGINT NOT NULL REFERENCES "CRM".partners(id),
    opportunity_id   BIGINT REFERENCES "SALES".opp_hdrs(id),
    price_list_id    BIGINT REFERENCES "SALES".price_lists(id),
    revision_no      INT NOT NULL DEFAULT 1,
    parent_quote_id  BIGINT REFERENCES "SALES".quot_hdrs(id), -- prior revision, if any
    status           TEXT NOT NULL DEFAULT 'draft'
                     CHECK (status IN ('draft','sent','approved','accepted','declined','expired','converted')),
    valid_until      DATE,
    converted_so_id  BIGINT, -- FK added after so_hdrs exists (see §7 deferred constraints)
    promo_code_id    BIGINT REFERENCES "SALES".promo_codes(id),
    subject_type     TEXT,   -- optional polymorphic link back to a vertical record
    subject_id       BIGINT,
    subtotal         NUMERIC(14,2) NOT NULL DEFAULT 0,
    discount_total   NUMERIC(14,2) NOT NULL DEFAULT 0,
    tax_total        NUMERIC(14,2) NOT NULL DEFAULT 0,
    grand_total      NUMERIC(14,2) NOT NULL DEFAULT 0,
    created_by       BIGINT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".quot_lines (
    id                BIGSERIAL PRIMARY KEY,
    quot_hdr_id       BIGINT NOT NULL REFERENCES "SALES".quot_hdrs(id) ON DELETE CASCADE,
    line_no           INT NOT NULL,
    item_id           BIGINT REFERENCES "SALES".items(id),
    description       TEXT,
    qty               NUMERIC(14,3) NOT NULL DEFAULT 1,
    unit_price        NUMERIC(14,2) NOT NULL DEFAULT 0,
    discount_percent  NUMERIC(6,3) NOT NULL DEFAULT 0,
    discount_amount   NUMERIC(14,2) NOT NULL DEFAULT 0,
    tax_percent       NUMERIC(6,3) NOT NULL DEFAULT 0,
    line_total        NUMERIC(14,2) NOT NULL DEFAULT 0,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (quot_hdr_id, line_no)
);

-- ============================================================================
-- 4. SALES ORDERS
-- ============================================================================

CREATE TABLE "SALES".so_hdrs (
    id             BIGSERIAL PRIMARY KEY,
    uuid           UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    so_no          TEXT NOT NULL UNIQUE,
    customer_id    BIGINT NOT NULL REFERENCES "CRM".partners(id),
    quote_id       BIGINT REFERENCES "SALES".quot_hdrs(id),
    price_list_id  BIGINT REFERENCES "SALES".price_lists(id),
    status         TEXT NOT NULL DEFAULT 'draft'
                   CHECK (status IN ('draft','confirmed','partially_fulfilled','fulfilled','cancelled')),
    order_date     DATE NOT NULL DEFAULT CURRENT_DATE,
    promo_code_id  BIGINT REFERENCES "SALES".promo_codes(id),
    subject_type   TEXT,
    subject_id     BIGINT,
    subtotal       NUMERIC(14,2) NOT NULL DEFAULT 0,
    discount_total NUMERIC(14,2) NOT NULL DEFAULT 0,
    tax_total      NUMERIC(14,2) NOT NULL DEFAULT 0,
    grand_total    NUMERIC(14,2) NOT NULL DEFAULT 0,
    created_by     BIGINT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".so_lines (
    id                BIGSERIAL PRIMARY KEY,
    so_hdr_id         BIGINT NOT NULL REFERENCES "SALES".so_hdrs(id) ON DELETE CASCADE,
    line_no           INT NOT NULL,
    item_id           BIGINT REFERENCES "SALES".items(id),
    description       TEXT,
    qty_ordered       NUMERIC(14,3) NOT NULL DEFAULT 1,
    qty_delivered     NUMERIC(14,3) NOT NULL DEFAULT 0, -- derived rollup from dlv_lines
    qty_invoiced      NUMERIC(14,3) NOT NULL DEFAULT 0, -- derived rollup from inv_lines
    unit_price        NUMERIC(14,2) NOT NULL DEFAULT 0,
    discount_percent  NUMERIC(6,3) NOT NULL DEFAULT 0,
    discount_amount   NUMERIC(14,2) NOT NULL DEFAULT 0,
    tax_percent       NUMERIC(6,3) NOT NULL DEFAULT 0,
    line_total        NUMERIC(14,2) NOT NULL DEFAULT 0,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (so_hdr_id, line_no)
);

-- ============================================================================
-- 5. DELIVERIES
-- ============================================================================

CREATE TABLE "SALES".dlv_hdrs (
    id           BIGSERIAL PRIMARY KEY,
    uuid         UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    dlv_no       TEXT NOT NULL UNIQUE,
    so_hdr_id    BIGINT NOT NULL REFERENCES "SALES".so_hdrs(id),
    status       TEXT NOT NULL DEFAULT 'pending'
                 CHECK (status IN ('pending','picked','packed','shipped','delivered','cancelled')),
    carrier      TEXT,
    tracking_no  TEXT,
    shipped_at   TIMESTAMPTZ,
    delivered_at TIMESTAMPTZ,
    created_by   BIGINT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".dlv_lines (
    id           BIGSERIAL PRIMARY KEY,
    dlv_hdr_id   BIGINT NOT NULL REFERENCES "SALES".dlv_hdrs(id) ON DELETE CASCADE,
    so_line_id   BIGINT NOT NULL REFERENCES "SALES".so_lines(id),
    qty_shipped  NUMERIC(14,3) NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 6. CONTRACTS & SUBSCRIPTIONS
-- (created before Billing so inv_hdrs / recurring_billing_schedules can FK
-- straight in without a deferred constraint)
-- ============================================================================

CREATE TABLE "SALES".contr_hdrs (
    id             BIGSERIAL PRIMARY KEY,
    uuid           UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    contract_no    TEXT NOT NULL UNIQUE,
    customer_id    BIGINT NOT NULL REFERENCES "CRM".partners(id),
    name           TEXT NOT NULL,
    term_start     DATE NOT NULL,
    term_end       DATE,
    auto_renew     BOOLEAN NOT NULL DEFAULT FALSE,
    price_list_id  BIGINT REFERENCES "SALES".price_lists(id),
    status         TEXT NOT NULL DEFAULT 'draft'
                   CHECK (status IN ('draft','active','renewed','cancelled','expired')),
    created_by     BIGINT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".contr_subscriptions (
    id                 BIGSERIAL PRIMARY KEY,
    contr_hdr_id       BIGINT NOT NULL REFERENCES "SALES".contr_hdrs(id) ON DELETE CASCADE,
    item_id            BIGINT REFERENCES "SALES".items(id),
    description        TEXT,
    recurring_amount   NUMERIC(14,2) NOT NULL,
    billing_interval   TEXT NOT NULL CHECK (billing_interval IN ('monthly','quarterly','annual')),
    next_bill_date     DATE NOT NULL,
    is_active          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 7. BILLING (Invoices, Payments, Recurring Schedule)
-- ============================================================================

CREATE TABLE "SALES".inv_hdrs (
    id            BIGSERIAL PRIMARY KEY,
    uuid          UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    invoice_no    TEXT NOT NULL UNIQUE,
    customer_id   BIGINT NOT NULL REFERENCES "CRM".partners(id),
    so_hdr_id     BIGINT REFERENCES "SALES".so_hdrs(id),
    contract_id   BIGINT REFERENCES "SALES".contr_hdrs(id), -- set for recurring/contract invoices
    invoice_type  TEXT NOT NULL DEFAULT 'standard'
                  CHECK (invoice_type IN ('standard','deposit','recurring','credit_note')),
    status        TEXT NOT NULL DEFAULT 'draft'
                  CHECK (status IN ('draft','sent','partially_paid','paid','overdue','void')),
    issue_date    DATE NOT NULL DEFAULT CURRENT_DATE,
    due_date      DATE,
    subtotal      NUMERIC(14,2) NOT NULL DEFAULT 0,
    tax_total     NUMERIC(14,2) NOT NULL DEFAULT 0,
    grand_total   NUMERIC(14,2) NOT NULL DEFAULT 0,
    amount_paid   NUMERIC(14,2) NOT NULL DEFAULT 0,
    void_reason   TEXT,
    created_by    BIGINT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".inv_lines (
    id           BIGSERIAL PRIMARY KEY,
    inv_hdr_id   BIGINT NOT NULL REFERENCES "SALES".inv_hdrs(id) ON DELETE CASCADE,
    line_no      INT NOT NULL,
    so_line_id   BIGINT REFERENCES "SALES".so_lines(id),
    description  TEXT,
    qty          NUMERIC(14,3) NOT NULL DEFAULT 1,
    unit_price   NUMERIC(14,2) NOT NULL DEFAULT 0,
    tax_percent  NUMERIC(6,3) NOT NULL DEFAULT 0,
    line_total   NUMERIC(14,2) NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (inv_hdr_id, line_no)
);

CREATE TABLE "SALES".inv_payments (
    id            BIGSERIAL PRIMARY KEY,
    inv_hdr_id    BIGINT NOT NULL REFERENCES "SALES".inv_hdrs(id),
    amount        NUMERIC(14,2) NOT NULL,
    method        TEXT NOT NULL, -- e.g. bank_transfer, cash, card, other
    reference     TEXT,
    paid_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    recorded_by   BIGINT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".recurring_billing_schedules (
    id                    BIGSERIAL PRIMARY KEY,
    contract_id           BIGINT NOT NULL REFERENCES "SALES".contr_hdrs(id),
    subscription_line_id  BIGINT NOT NULL REFERENCES "SALES".contr_subscriptions(id),
    customer_id           BIGINT NOT NULL REFERENCES "CRM".partners(id),
    amount                NUMERIC(14,2) NOT NULL,
    billing_interval      TEXT NOT NULL CHECK (billing_interval IN ('monthly','quarterly','annual')),
    next_bill_date        DATE NOT NULL,
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    last_invoice_id       BIGINT REFERENCES "SALES".inv_hdrs(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 8. RETURNS
-- ============================================================================

CREATE TABLE "SALES".ret_hdrs (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    ret_no              TEXT NOT NULL UNIQUE,
    so_hdr_id           BIGINT REFERENCES "SALES".so_hdrs(id),
    inv_hdr_id          BIGINT REFERENCES "SALES".inv_hdrs(id),
    customer_id         BIGINT NOT NULL REFERENCES "CRM".partners(id),
    reason_code         TEXT NOT NULL,
    status              TEXT NOT NULL DEFAULT 'requested'
                        CHECK (status IN ('requested','approved','received','refunded','replaced','closed')),
    replacement_so_id   BIGINT REFERENCES "SALES".so_hdrs(id),
    created_by          BIGINT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE "SALES".ret_lines (
    id               BIGSERIAL PRIMARY KEY,
    ret_hdr_id       BIGINT NOT NULL REFERENCES "SALES".ret_hdrs(id) ON DELETE CASCADE,
    so_line_id       BIGINT REFERENCES "SALES".so_lines(id),
    qty_returned     NUMERIC(14,3) NOT NULL,
    condition_notes  TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 9. COMMISSIONS
-- ============================================================================

CREATE TABLE "SALES".comm_settlements (
    id             BIGSERIAL PRIMARY KEY,
    period_start   DATE NOT NULL,
    period_end     DATE NOT NULL,
    sales_team_id  BIGINT REFERENCES "SALES".sales_teams(id),
    rep_user_id    BIGINT,
    status         TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','approved','paid')),
    total_amount   NUMERIC(14,2) NOT NULL DEFAULT 0,
    approved_by    BIGINT,
    approved_at    TIMESTAMPTZ,
    paid_at        TIMESTAMPTZ,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (period_end >= period_start)
);

CREATE TABLE "SALES".comm_settlement_lines (
    id                    BIGSERIAL PRIMARY KEY,
    comm_settlement_id    BIGINT NOT NULL REFERENCES "SALES".comm_settlements(id) ON DELETE CASCADE,
    inv_hdr_id            BIGINT REFERENCES "SALES".inv_hdrs(id),
    commission_plan_id    BIGINT REFERENCES "SALES".commission_plans(id),
    base_amount           NUMERIC(14,2) NOT NULL,
    commission_amount     NUMERIC(14,2) NOT NULL,
    is_reversal           BOOLEAN NOT NULL DEFAULT FALSE,
    reversal_of_line_id   BIGINT REFERENCES "SALES".comm_settlement_lines(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 10. DEFERRED CROSS-REFERENCES
-- quot_hdrs.converted_so_id can't FK inline (so_hdrs is defined later above,
-- but Postgres still requires the referenced table to exist at ALTER time —
-- kept as an explicit deferred step here for readability/documentation of
-- the circular relationship between Quotation <-> Sales Order).
-- ============================================================================

ALTER TABLE "SALES".quot_hdrs
    ADD CONSTRAINT fk_quot_converted_so
    FOREIGN KEY (converted_so_id) REFERENCES "SALES".so_hdrs(id);

-- ============================================================================
-- 11. INDEXES
-- (FK columns get an index; unique constraints already create one)
-- ============================================================================

CREATE INDEX idx_sales_team_members_team        ON "SALES".sales_team_members(sales_team_id);
CREATE INDEX idx_price_list_lines_pricelist      ON "SALES".price_list_lines(price_list_id);
CREATE INDEX idx_commission_plan_tiers_plan      ON "SALES".commission_plan_tiers(commission_plan_id);
CREATE INDEX idx_customer_sales_profiles_partner ON "SALES".customer_sales_profiles(partner_id);
CREATE INDEX idx_customer_credit_profiles_partner ON "SALES".customer_credit_profiles(partner_id);
CREATE INDEX idx_sales_portal_tokens_partner     ON "SALES".sales_portal_tokens(partner_id);
CREATE INDEX idx_opp_hdrs_customer               ON "SALES".opp_hdrs(customer_id);
CREATE INDEX idx_opp_hdrs_lead                   ON "SALES".opp_hdrs(lead_id);
CREATE INDEX idx_opp_hdrs_stage                  ON "SALES".opp_hdrs(stage);
CREATE INDEX idx_quot_hdrs_customer              ON "SALES".quot_hdrs(customer_id);
CREATE INDEX idx_quot_hdrs_opportunity           ON "SALES".quot_hdrs(opportunity_id);
CREATE INDEX idx_quot_hdrs_status                ON "SALES".quot_hdrs(status);
CREATE INDEX idx_quot_hdrs_subject                ON "SALES".quot_hdrs(subject_type, subject_id);
CREATE INDEX idx_quot_lines_hdr                  ON "SALES".quot_lines(quot_hdr_id);
CREATE INDEX idx_so_hdrs_customer                ON "SALES".so_hdrs(customer_id);
CREATE INDEX idx_so_hdrs_quote                   ON "SALES".so_hdrs(quote_id);
CREATE INDEX idx_so_hdrs_status                  ON "SALES".so_hdrs(status);
CREATE INDEX idx_so_hdrs_subject                  ON "SALES".so_hdrs(subject_type, subject_id);
CREATE INDEX idx_so_lines_hdr                    ON "SALES".so_lines(so_hdr_id);
CREATE INDEX idx_dlv_hdrs_so                     ON "SALES".dlv_hdrs(so_hdr_id);
CREATE INDEX idx_dlv_lines_hdr                   ON "SALES".dlv_lines(dlv_hdr_id);
CREATE INDEX idx_dlv_lines_soline                ON "SALES".dlv_lines(so_line_id);
CREATE INDEX idx_inv_hdrs_customer                ON "SALES".inv_hdrs(customer_id);
CREATE INDEX idx_inv_hdrs_so                      ON "SALES".inv_hdrs(so_hdr_id);
CREATE INDEX idx_inv_hdrs_contract                ON "SALES".inv_hdrs(contract_id);
CREATE INDEX idx_inv_hdrs_status                  ON "SALES".inv_hdrs(status);
CREATE INDEX idx_inv_lines_hdr                    ON "SALES".inv_lines(inv_hdr_id);
CREATE INDEX idx_inv_payments_hdr                 ON "SALES".inv_payments(inv_hdr_id);
CREATE INDEX idx_rbs_contract                     ON "SALES".recurring_billing_schedules(contract_id);
CREATE INDEX idx_rbs_next_bill_date               ON "SALES".recurring_billing_schedules(next_bill_date) WHERE is_active;
CREATE INDEX idx_ret_hdrs_so                      ON "SALES".ret_hdrs(so_hdr_id);
CREATE INDEX idx_ret_hdrs_inv                     ON "SALES".ret_hdrs(inv_hdr_id);
CREATE INDEX idx_ret_hdrs_customer                ON "SALES".ret_hdrs(customer_id);
CREATE INDEX idx_ret_lines_hdr                    ON "SALES".ret_lines(ret_hdr_id);
CREATE INDEX idx_comm_settlement_lines_settlement ON "SALES".comm_settlement_lines(comm_settlement_id);
CREATE INDEX idx_comm_settlement_lines_inv        ON "SALES".comm_settlement_lines(inv_hdr_id);
CREATE INDEX idx_contr_hdrs_customer              ON "SALES".contr_hdrs(customer_id);
CREATE INDEX idx_contr_subscriptions_hdr          ON "SALES".contr_subscriptions(contr_hdr_id);

-- ============================================================================
-- End of SALES schema
-- ============================================================================
