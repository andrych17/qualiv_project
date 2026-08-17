-- =============================================================================
-- Sales module — schema + example seed data
-- Source spec: app/Modules/Sales/SALES_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), schema "SALES", no tenant_id column
--              anywhere.
--
-- Prerequisites (run their own *_SPECS.sql first): "CRM".partners/leads
-- (CRM_SPECS.sql). Unlike Purchase, CRM is Sales's ONLY hard dependency (§5:
-- "A tenant cannot enable Sales without CRM enabled") — every FK into CRM
-- below (partners AND leads) is real and enforced, not informational.
--
-- THE BIG ONE — Sales owns NO invoice, payment, or AR ledger tables. §4
-- literally has a struck-through line for this ("removed — invoices, invoice
-- lines, and payments are owned by ACCOUNTING.ar_invoices / ..."). Billing is
-- a request-orchestrator only (§3I): Sales fires InvoiceRequested/
-- PaymentRequested at Accounting and tracks status purely via
-- so_lines.qty_invoiced (updated from Accounting's InvoicePosted/
-- PaymentRecorded echo events), never a local financial row. This is the
-- starkest "one ledger, many requesters" application yet (vs. Purchase, which
-- at least keeps its own pur_invoice_hdrs as a procurement-intake record) —
-- Sales keeps NOTHING invoice-shaped, not even an intake copy.
--
-- Cross-module reference posture (per §5, each flagged inline below):
--   - "CRM".partners / "CRM".leads     — REAL FK (hard dependency)
--   - Accounting (invoices/payments)    — no column at all; tracked only via
--                                          so_lines.qty_invoiced + subject_type/
--                                          subject_id echoed back from events
--   - Inventory (products, goods_issues,
--     locations)                        — informational BIGINT, no FK (soft
--                                          dependency, scoped to fulfillment)
--   - WNE (workflow_instances)          — informational BIGINT, no FK (soft
--                                          dependency)
--   - DMS (documents)                   — informational BIGINT, no FK — a
--                                          DELIBERATE DIVERGENCE from
--                                          PURCHASE_SPECS.sql, which gives DMS
--                                          a real FK. Purchase's own §5 never
--                                          hedges DMS; Sales's §5 explicitly
--                                          calls DMS "soft — ... Sales falls
--                                          back to generating a downloadable
--                                          PDF on the fly with no persistent
--                                          version history" if absent, so a
--                                          real enforced FK would be wrong here.
--
-- No Sales-owned item/product catalog exists anywhere in §4's storage list
-- (unlike Purchase's own pur_catalog_items). Every line table below carries
-- item_type + an informational product_id (→ INVENTORY.products, when
-- installed) + a required free-text description, so the module works for a
-- pure-services tenant (e.g. Legal retainers) with zero Inventory install.
--
-- Two inferred resolutions of internal spec tension, flagged here rather than
-- silently picked:
--   1. §3B says Customer -> Territory assignment goes through a CRM.partners
--      CUSTOMFIELDS entry ("reusing CUSTOMFIELDS rather than a new column on
--      a Core-owned table"), but §4's own field list for
--      customer_sales_profiles names "territory" as one of its columns.
--      Resolved in favor of §3B's stated reasoning (the whole point of the
--      CUSTOMFIELDS choice is to avoid a second place price/territory
--      assignment can live) — customer_sales_profiles below has NO
--      territory_id column; sales_team_id/price_list_id/assigned_rep_id only.
--   2. §3L lists next_bill_date as a field on both contr_subscriptions (the
--      static per-line recurring template) and implies it again on
--      recurring_billing_schedules (the operational table "the Billing
--      engine's scheduled job consumes"). Keeping it in both places is two
--      sources of truth for the same date. Resolved by keeping next_bill_date
--      (and last_billed_at/is_active) ONLY on recurring_billing_schedules —
--      the row the job actually reads/writes — with contr_subscriptions
--      holding just the static amount/interval template.
--
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- or other client-specific meta-commands — so it runs through any Postgres
-- client. Row-creating INSERTs are split into their own top-level statements
-- ahead of any statement that needs to UPDATE that same row (the recurring
-- same-statement/same-snapshot CTE limitation documented since DMS_SPECS.sql).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

CREATE SCHEMA IF NOT EXISTS "SALES";

-- -----------------------------------------------------------------------------
-- §3B / §4. Sales Master — the configuration layer every engine reads from
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SALES".territories (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "SALES".sales_teams (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    territory_id    BIGINT REFERENCES "SALES".territories (id),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "SALES".sales_team_members (
    id              BIGSERIAL PRIMARY KEY,
    sales_team_id   BIGINT NOT NULL REFERENCES "SALES".sales_teams (id) ON DELETE CASCADE,
    user_id         BIGINT NOT NULL REFERENCES users (id),
    role            VARCHAR(10) NOT NULL DEFAULT 'member' CHECK (role IN ('lead', 'member')),
    joined_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (sales_team_id, user_id)
);

CREATE TABLE IF NOT EXISTS "SALES".price_lists (
    id                  BIGSERIAL PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    currency            VARCHAR(3) NOT NULL DEFAULT 'IDR',
    territory_id        BIGINT REFERENCES "SALES".territories (id), -- optional territory scope (§3B)
    customer_segment    VARCHAR(50),                -- optional segment scope, tenant-editable free text
    effective_from      DATE,
    effective_to        DATE,
    is_tenant_default   BOOLEAN NOT NULL DEFAULT FALSE, -- the fallback when a customer has no explicit assignment (§3B/§3G)
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "SALES".price_list_lines (
    id              BIGSERIAL PRIMARY KEY,
    price_list_id   BIGINT NOT NULL REFERENCES "SALES".price_lists (id) ON DELETE CASCADE,
    item_type       VARCHAR(10) NOT NULL DEFAULT 'service' CHECK (item_type IN ('product', 'service')),
    product_id      BIGINT,                         -- informational; INVENTORY.products.id when Inventory is installed
    description     VARCHAR(255) NOT NULL,
    unit_price      NUMERIC(14, 2) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sales_price_list_lines_list ON "SALES".price_list_lines (price_list_id);

CREATE TABLE IF NOT EXISTS "SALES".promo_codes (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30) NOT NULL UNIQUE,
    discount_type   VARCHAR(10) NOT NULL CHECK (discount_type IN ('percentage', 'fixed')),
    discount_value  NUMERIC(14, 2) NOT NULL,
    valid_from      DATE NOT NULL,
    valid_to        DATE NOT NULL,
    usage_limit     INT,                            -- nullable = unlimited
    usage_count     INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (valid_to > valid_from)
);

CREATE TABLE IF NOT EXISTS "SALES".commission_plans (
    id                          BIGSERIAL PRIMARY KEY,
    name                        VARCHAR(150) NOT NULL,
    basis                       VARCHAR(10) NOT NULL CHECK (basis IN ('flat_pct', 'tiered')),
    flat_rate_pct               NUMERIC(5, 2),       -- set when basis = 'flat_pct'
    tier_rules                  JSONB,               -- set when basis = 'tiered': [{min_revenue,max_revenue,rate_pct}, ...]
    applies_to_type             VARCHAR(10) NOT NULL CHECK (applies_to_type IN ('team', 'rep')),
    applies_to_sales_team_id    BIGINT REFERENCES "SALES".sales_teams (id),
    applies_to_user_id          BIGINT REFERENCES users (id),
    effective_from              DATE NOT NULL,
    effective_to                DATE,
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (
        (applies_to_type = 'team' AND applies_to_sales_team_id IS NOT NULL AND applies_to_user_id IS NULL) OR
        (applies_to_type = 'rep'  AND applies_to_user_id IS NOT NULL AND applies_to_sales_team_id IS NULL)
    )
);

-- 1:1 extension of "CRM".partners — Sales-owned config, never written back to
-- CRM.partners itself (§3B rule). Deliberately has NO territory_id — see
-- header note (1): territory assignment lives in CUSTOMFIELDS per §3B.
CREATE TABLE IF NOT EXISTS "SALES".customer_sales_profiles (
    id                  BIGSERIAL PRIMARY KEY,
    partner_id          BIGINT NOT NULL UNIQUE REFERENCES "CRM".partners (id),
    sales_team_id       BIGINT REFERENCES "SALES".sales_teams (id),
    price_list_id       BIGINT REFERENCES "SALES".price_lists (id),
    assigned_rep_id     BIGINT REFERENCES users (id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "SALES".customer_credit_profiles (
    id                      BIGSERIAL PRIMARY KEY,
    partner_id              BIGINT NOT NULL UNIQUE REFERENCES "CRM".partners (id),
    credit_limit            NUMERIC(14, 2) NOT NULL DEFAULT 0,
    payment_terms_days      INT NOT NULL DEFAULT 30,
    on_hold                 BOOLEAN NOT NULL DEFAULT FALSE, -- manual override, blocks all new orders regardless of limit (§3K)
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "SALES".sales_portal_tokens (
    id              BIGSERIAL PRIMARY KEY,
    token           UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- signed-link access, same pattern as Schedule's ICS feeds (§3D)
    partner_id      BIGINT NOT NULL REFERENCES "CRM".partners (id),
    expires_at      TIMESTAMPTZ,
    revoked_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sales_portal_tokens_partner ON "SALES".sales_portal_tokens (partner_id);

-- -----------------------------------------------------------------------------
-- §3C / §4. Opportunity Management (CRM Integration)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SALES".opp_hdrs (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name                VARCHAR(200) NOT NULL,
    customer_id         BIGINT REFERENCES "CRM".partners (id), -- nullable: can start against a prospect not yet a Customer
    lead_id             BIGINT REFERENCES "CRM".leads (id),    -- nullable: real FK, CRM is a hard dependency (§5)
    stage               VARCHAR(15) NOT NULL DEFAULT 'new'
                            CHECK (stage IN ('new', 'qualifying', 'quoted', 'won', 'lost')),
    owner_id            BIGINT REFERENCES users (id),
    sales_team_id       BIGINT REFERENCES "SALES".sales_teams (id),
    estimated_value     NUMERIC(14, 2),             -- informational only in MVP (§3C, no weighted forecasting)
    expected_close_date DATE,
    loss_reason         VARCHAR(100),                -- required (app-enforced) when stage = 'lost'
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK ((stage = 'lost') = (loss_reason IS NOT NULL))
);

CREATE INDEX IF NOT EXISTS idx_sales_opp_hdrs_stage ON "SALES".opp_hdrs (stage, owner_id);

-- -----------------------------------------------------------------------------
-- §3E / §4. Quotation Engine — versioned, never overwritten (same philosophy
-- as DMS document versioning). quote_group_id ties every revision of "the
-- same logical quote" together; revision_no orders them within the group.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SALES".quot_hdrs (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    quote_group_id              UUID NOT NULL DEFAULT gen_random_uuid(), -- shared across all revisions of one quote
    revision_no                 INT NOT NULL DEFAULT 1,
    customer_id                 BIGINT NOT NULL REFERENCES "CRM".partners (id),
    opportunity_id               BIGINT REFERENCES "SALES".opp_hdrs (id),
    price_list_id                   BIGINT REFERENCES "SALES".price_lists (id),
    validity_date                      DATE,
    status                                 VARCHAR(15) NOT NULL DEFAULT 'draft'
                                               CHECK (status IN ('draft', 'sent', 'approved', 'accepted', 'declined', 'expired', 'converted')),
    subject_type                              VARCHAR(100), -- optional polymorphic link, e.g. 'legal.matters' — NOT a FK (§5)
    subject_id                                   BIGINT,
    wne_workflow_instance_id                        BIGINT, -- informational only; WNE is a soft dependency (§5)
    document_id                                        BIGINT, -- informational; DMS is a soft dependency (§5) — see header note
    converted_so_id                                       BIGINT, -- FK added below via ALTER TABLE, after so_hdrs exists
    created_by                                               BIGINT REFERENCES users (id),
    created_at                                                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (quote_group_id, revision_no)
);

CREATE INDEX IF NOT EXISTS idx_sales_quot_hdrs_customer ON "SALES".quot_hdrs (customer_id, status);
CREATE INDEX IF NOT EXISTS idx_sales_quot_hdrs_group    ON "SALES".quot_hdrs (quote_group_id);

CREATE TABLE IF NOT EXISTS "SALES".quot_lines (
    id              BIGSERIAL PRIMARY KEY,
    quot_hdr_id     BIGINT NOT NULL REFERENCES "SALES".quot_hdrs (id) ON DELETE CASCADE,
    line_no         INT NOT NULL,
    item_type       VARCHAR(10) NOT NULL DEFAULT 'service' CHECK (item_type IN ('product', 'service')),
    product_id      BIGINT,                         -- informational; INVENTORY.products.id when installed
    description     VARCHAR(255) NOT NULL,
    quantity        NUMERIC(14, 3) NOT NULL,
    unit_price      NUMERIC(14, 2) NOT NULL,
    discount_amount NUMERIC(14, 2) NOT NULL DEFAULT 0,
    tax_amount      NUMERIC(14, 2) NOT NULL DEFAULT 0,
    line_total      NUMERIC(14, 2) GENERATED ALWAYS AS (quantity * unit_price) STORED, -- gross; discount/tax applied at app layer, same convention as Purchase
    UNIQUE (quot_hdr_id, line_no)
);

-- -----------------------------------------------------------------------------
-- §3F / §4. Sales Order Engine
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SALES".so_hdrs (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    so_number                   VARCHAR(30) NOT NULL UNIQUE,
    customer_id                  BIGINT NOT NULL REFERENCES "CRM".partners (id),
    quote_id                        BIGINT REFERENCES "SALES".quot_hdrs (id), -- nullable: direct orders allowed (§3F)
    price_list_id                      BIGINT REFERENCES "SALES".price_lists (id), -- locked at order time
    status                                 VARCHAR(20) NOT NULL DEFAULT 'draft'
                                               CHECK (status IN ('draft', 'confirmed', 'partially_fulfilled', 'fulfilled', 'cancelled')),
    subject_type                              VARCHAR(100), -- optional polymorphic link, e.g. 'legal.matters' — NOT a FK (§3F)
    subject_id                                   BIGINT,
    wne_workflow_instance_id                        BIGINT, -- informational only (credit-override / approval workflows)
    created_by                                         BIGINT REFERENCES users (id),
    created_at                                            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                               TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sales_so_hdrs_customer ON "SALES".so_hdrs (customer_id, status);
CREATE INDEX IF NOT EXISTS idx_sales_so_hdrs_subject   ON "SALES".so_hdrs (subject_type, subject_id);

ALTER TABLE "SALES".quot_hdrs
    ADD CONSTRAINT fk_sales_quot_hdrs_converted_so
        FOREIGN KEY (converted_so_id) REFERENCES "SALES".so_hdrs (id);

CREATE TABLE IF NOT EXISTS "SALES".so_lines (
    id              BIGSERIAL PRIMARY KEY,
    so_hdr_id       BIGINT NOT NULL REFERENCES "SALES".so_hdrs (id) ON DELETE CASCADE,
    line_no         INT NOT NULL,
    item_type       VARCHAR(10) NOT NULL DEFAULT 'service' CHECK (item_type IN ('product', 'service')),
    product_id      BIGINT,                         -- informational; INVENTORY.products.id when installed
    description     VARCHAR(255) NOT NULL,
    qty_ordered     NUMERIC(14, 3) NOT NULL,
    qty_delivered   NUMERIC(14, 3) NOT NULL DEFAULT 0, -- derived rollup from dlv_lines (§3H rule) — never manually edited
    qty_invoiced    NUMERIC(14, 3) NOT NULL DEFAULT 0, -- derived rollup from Accounting's InvoicePosted echo (§3I) — never manually edited
    unit_price      NUMERIC(14, 2) NOT NULL,
    discount_amount NUMERIC(14, 2) NOT NULL DEFAULT 0,
    tax_amount      NUMERIC(14, 2) NOT NULL DEFAULT 0,
    line_total      NUMERIC(14, 2) GENERATED ALWAYS AS (qty_ordered * unit_price) STORED,
    UNIQUE (so_hdr_id, line_no)
);

-- -----------------------------------------------------------------------------
-- §3H / §4. Delivery Engine
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SALES".dlv_hdrs (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    so_hdr_id                   BIGINT NOT NULL REFERENCES "SALES".so_hdrs (id),
    status                      VARCHAR(15) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending', 'picked', 'packed', 'shipped', 'delivered', 'cancelled')),
    carrier                     VARCHAR(100),
    tracking_number              VARCHAR(100),
    source_location_id              BIGINT, -- informational; INVENTORY.locations.id, shown only when Inventory installed (§3H)
    inventory_goods_issue_id           BIGINT, -- informational; INVENTORY.goods_issues.id once posted there (§4)
    shipped_at                            TIMESTAMPTZ,
    delivered_at                             TIMESTAMPTZ,
    created_at                                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sales_dlv_hdrs_order ON "SALES".dlv_hdrs (so_hdr_id);

CREATE TABLE IF NOT EXISTS "SALES".dlv_lines (
    id              BIGSERIAL PRIMARY KEY,
    dlv_hdr_id      BIGINT NOT NULL REFERENCES "SALES".dlv_hdrs (id) ON DELETE CASCADE,
    so_line_id      BIGINT NOT NULL REFERENCES "SALES".so_lines (id),
    qty_shipped     NUMERIC(14, 3) NOT NULL           -- supports partial/split shipments — one order can have N deliveries (§3H)
);

-- -----------------------------------------------------------------------------
-- §3L / §4. Contracts & Subscriptions — feeds the Billing engine's recurring
-- generator via recurring_billing_schedules (see header note (2))
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SALES".contr_hdrs (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    customer_id     BIGINT NOT NULL REFERENCES "CRM".partners (id),
    name            VARCHAR(200) NOT NULL,
    term_start      DATE NOT NULL,
    term_end        DATE NOT NULL,
    auto_renew      BOOLEAN NOT NULL DEFAULT FALSE,
    status          VARCHAR(15) NOT NULL DEFAULT 'draft'
                        CHECK (status IN ('draft', 'active', 'renewed', 'cancelled', 'expired')),
    price_list_id   BIGINT REFERENCES "SALES".price_lists (id),
    created_by      BIGINT REFERENCES users (id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (term_end > term_start)
);

CREATE INDEX IF NOT EXISTS idx_sales_contr_hdrs_customer ON "SALES".contr_hdrs (customer_id, status);

CREATE TABLE IF NOT EXISTS "SALES".contr_subscriptions (
    id                  BIGSERIAL PRIMARY KEY,
    contr_hdr_id        BIGINT NOT NULL REFERENCES "SALES".contr_hdrs (id) ON DELETE CASCADE,
    line_no             INT NOT NULL,
    item_type           VARCHAR(10) NOT NULL DEFAULT 'service' CHECK (item_type IN ('product', 'service')),
    product_id          BIGINT,                     -- informational; INVENTORY.products.id when installed
    description         VARCHAR(255) NOT NULL,
    recurring_amount    NUMERIC(14, 2) NOT NULL,
    currency            VARCHAR(3) NOT NULL DEFAULT 'IDR',
    billing_interval    VARCHAR(10) NOT NULL CHECK (billing_interval IN ('monthly', 'quarterly', 'annual')), -- simple enum, not RRULE (§3L)
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (contr_hdr_id, line_no)
);

CREATE TABLE IF NOT EXISTS "SALES".recurring_billing_schedules (
    id                      BIGSERIAL PRIMARY KEY,
    contr_subscription_id   BIGINT NOT NULL REFERENCES "SALES".contr_subscriptions (id) ON DELETE CASCADE,
    customer_id             BIGINT NOT NULL REFERENCES "CRM".partners (id),
    next_bill_date          DATE NOT NULL,           -- the field the Billing job's scheduled job actually reads (§3I/§3L)
    last_billed_at          TIMESTAMPTZ,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sales_recurring_schedules_next_bill
    ON "SALES".recurring_billing_schedules (next_bill_date) WHERE is_active;

-- -----------------------------------------------------------------------------
-- §3J / §4. Returns Engine
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SALES".ret_hdrs (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    so_hdr_id                   BIGINT REFERENCES "SALES".so_hdrs (id), -- original order (§3J)
    accounting_invoice_id       BIGINT, -- informational; ACCOUNTING.ar_invoices.id — the "original ... invoice_id" (§3J)
    customer_id                 BIGINT NOT NULL REFERENCES "CRM".partners (id),
    reason_code                 VARCHAR(50) NOT NULL,
    status                      VARCHAR(15) NOT NULL DEFAULT 'requested'
                                    CHECK (status IN ('requested', 'approved', 'received', 'refunded', 'replaced', 'closed')),
    subject_type                VARCHAR(100), -- optional polymorphic link — NOT a FK (§5)
    subject_id                  BIGINT,
    wne_workflow_instance_id    BIGINT, -- informational only (return approval, §3J)
    replacement_so_id           BIGINT REFERENCES "SALES".so_hdrs (id), -- set when the replacement path (§3J) generates a new order
    created_by                  BIGINT REFERENCES users (id),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sales_ret_hdrs_customer ON "SALES".ret_hdrs (customer_id, status);

CREATE TABLE IF NOT EXISTS "SALES".ret_lines (
    id                  BIGSERIAL PRIMARY KEY,
    ret_hdr_id          BIGINT NOT NULL REFERENCES "SALES".ret_hdrs (id) ON DELETE CASCADE,
    so_line_id          BIGINT REFERENCES "SALES".so_lines (id),
    qty_returned        NUMERIC(14, 3) NOT NULL,
    condition_notes     VARCHAR(255)
);

-- -----------------------------------------------------------------------------
-- §3M / §4. Commission Engine — settlement is always per rep (§3M), computed
-- off invoiced-and-paid revenue only, reversals are new lines, never edits
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SALES".comm_settlements (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    rep_id              BIGINT NOT NULL REFERENCES users (id),
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    status              VARCHAR(15) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'paid')),
    total_amount        NUMERIC(14, 2) NOT NULL DEFAULT 0,
    currency            VARCHAR(3) NOT NULL DEFAULT 'IDR',
    wne_workflow_instance_id BIGINT, -- informational only (manager approval, §3M)
    approved_by         BIGINT REFERENCES users (id),
    approved_at         TIMESTAMPTZ,
    paid_at             TIMESTAMPTZ,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (period_end > period_start)
);

CREATE INDEX IF NOT EXISTS idx_sales_comm_settlements_rep ON "SALES".comm_settlements (rep_id, status);

CREATE TABLE IF NOT EXISTS "SALES".comm_settlement_lines (
    id                  BIGSERIAL PRIMARY KEY,
    settlement_id       BIGINT NOT NULL REFERENCES "SALES".comm_settlements (id) ON DELETE CASCADE,
    commission_plan_id  BIGINT NOT NULL REFERENCES "SALES".commission_plans (id),
    so_line_id          BIGINT REFERENCES "SALES".so_lines (id), -- the order line whose invoice triggered this line
    line_type           VARCHAR(10) NOT NULL DEFAULT 'earned' CHECK (line_type IN ('earned', 'reversal')),
    amount              NUMERIC(14, 2) NOT NULL,     -- positive for 'earned', negative for 'reversal' (§3M rule)
    notes               VARCHAR(255)
);

-- =============================================================================
-- NOTE — Future Version scope intentionally NOT built here (§2/§5 MVP scope
-- boundary): payment gateway tables, multi-jurisdiction tax engine, carrier
-- API/webhook tables, dynamic/tiered/bundle pricing rule tables, usage-based
-- billing/proration fields, full self-service portal auth, forecasting
-- models, multi-currency conversion tables, automated credit scoring, and
-- multi-level/split commission structures. None of the MVP tables above
-- require breaking changes to accommodate them later.
-- =============================================================================

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Demonstrates the full Opportunity -> Quotation (with one revision) -> Sales
-- Order -> partial Delivery -> Return flow, plus a Contract/Subscription
-- driving a recurring billing schedule, a commission settlement, price
-- lists/promo codes, and customer credit/sales profiles.
--
-- Assumes CRM_SPECS.sql's seed has already run (partners "Example Corp" /
-- "Office Rentals Vendor", users admin@nusaevo.com / staff@nusaevo.com from
-- database/seeders/DatabaseSeeder.php). "Example Corp" plays the Customer
-- here (CRM_SPECS.sql's seed already gave it the CLIENT role, not a distinct
-- Sales-specific role — Sales reads role=Customer per its own vocabulary,
-- which for a Legal-flavored tenant is the same partner CRM already tags
-- CLIENT; no new CRM role type is created here to avoid two labels for one
-- concept on the same demo partner).
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Sales Master: territory, team, price list, promo code, commission plan
-- ---------------------------------------------------------------------------

INSERT INTO "SALES".territories (code, name) VALUES ('JKT', 'Jakarta Metro');

INSERT INTO "SALES".sales_teams (name, territory_id)
SELECT 'Litigation Sales Team', t.id FROM "SALES".territories t WHERE t.code = 'JKT';

INSERT INTO "SALES".sales_team_members (sales_team_id, user_id, role)
SELECT st.id, u.id, 'lead'
FROM "SALES".sales_teams st, users u
WHERE st.name = 'Litigation Sales Team' AND u.email = 'staff@nusaevo.com';

INSERT INTO "SALES".price_lists (name, currency, territory_id, is_tenant_default, effective_from, effective_to)
SELECT 'Standard 2026 Price List', 'IDR', t.id, TRUE, DATE '2026-01-01', DATE '2026-12-31'
FROM "SALES".territories t WHERE t.code = 'JKT';

INSERT INTO "SALES".price_list_lines (price_list_id, item_type, description, unit_price)
SELECT pl.id, 'service', 'Litigation Retainer - Monthly', 15000000
FROM "SALES".price_lists pl WHERE pl.name = 'Standard 2026 Price List'
UNION ALL
SELECT pl.id, 'service', 'Contract Review - Per Document', 2500000
FROM "SALES".price_lists pl WHERE pl.name = 'Standard 2026 Price List';

INSERT INTO "SALES".promo_codes (code, discount_type, discount_value, valid_from, valid_to, usage_limit)
VALUES ('WELCOME10', 'percentage', 10, DATE '2026-01-01', DATE '2026-12-31', 50);

INSERT INTO "SALES".commission_plans (name, basis, flat_rate_pct, applies_to_type, applies_to_sales_team_id, effective_from)
SELECT 'Litigation Team Standard Commission', 'flat_pct', 5, 'team', st.id, DATE '2026-01-01'
FROM "SALES".sales_teams st WHERE st.name = 'Litigation Sales Team';

-- ---------------------------------------------------------------------------
-- 2. Customer sales/credit profiles for "Example Corp"
-- ---------------------------------------------------------------------------

INSERT INTO "SALES".customer_sales_profiles (partner_id, sales_team_id, price_list_id, assigned_rep_id)
SELECT p.id, st.id, pl.id, (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "CRM".partners p, "SALES".sales_teams st, "SALES".price_lists pl
WHERE p.name = 'Example Corp' AND st.name = 'Litigation Sales Team' AND pl.name = 'Standard 2026 Price List';

INSERT INTO "SALES".customer_credit_profiles (partner_id, credit_limit, payment_terms_days)
SELECT p.id, 100000000, 30 FROM "CRM".partners p WHERE p.name = 'Example Corp';

INSERT INTO "SALES".sales_portal_tokens (partner_id, expires_at)
SELECT p.id, now() + INTERVAL '90 days' FROM "CRM".partners p WHERE p.name = 'Example Corp';

-- ---------------------------------------------------------------------------
-- 3. Opportunity -> Quotation (v1 sent, v2 revision after a discount ask) -> Won
-- ---------------------------------------------------------------------------

INSERT INTO "SALES".opp_hdrs (name, customer_id, stage, owner_id, sales_team_id, estimated_value, expected_close_date)
SELECT 'Example Corp - 2026 Litigation Retainer', p.id, 'won',
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), st.id, 15000000, DATE '2026-07-15'
FROM "CRM".partners p, "SALES".sales_teams st
WHERE p.name = 'Example Corp' AND st.name = 'Litigation Sales Team';

-- Revision 1: sent
INSERT INTO "SALES".quot_hdrs (revision_no, customer_id, opportunity_id, price_list_id, validity_date, status, created_by)
SELECT 1, p.id, o.id, pl.id, DATE '2026-07-20', 'declined', (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "CRM".partners p, "SALES".opp_hdrs o, "SALES".price_lists pl
WHERE p.name = 'Example Corp' AND o.name = 'Example Corp - 2026 Litigation Retainer'
  AND pl.name = 'Standard 2026 Price List';

INSERT INTO "SALES".quot_lines (quot_hdr_id, line_no, item_type, description, quantity, unit_price)
SELECT q.id, 1, 'service', 'Litigation Retainer - Monthly', 1, 15000000
FROM "SALES".quot_hdrs q WHERE q.revision_no = 1
  AND q.quote_group_id = (SELECT quote_group_id FROM "SALES".quot_hdrs WHERE revision_no = 1 LIMIT 1);

-- Revision 2: same quote_group_id, discounted after customer pushback -> converted
INSERT INTO "SALES".quot_hdrs (quote_group_id, revision_no, customer_id, opportunity_id, price_list_id, validity_date, status, created_by)
SELECT q1.quote_group_id, 2, q1.customer_id, q1.opportunity_id, q1.price_list_id, DATE '2026-07-28', 'converted',
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "SALES".quot_hdrs q1 WHERE q1.revision_no = 1;

INSERT INTO "SALES".quot_lines (quot_hdr_id, line_no, item_type, description, quantity, unit_price, discount_amount)
SELECT q2.id, 1, 'service', 'Litigation Retainer - Monthly', 1, 15000000, 1500000 -- WELCOME10 applied
FROM "SALES".quot_hdrs q2 WHERE q2.revision_no = 2;

-- ---------------------------------------------------------------------------
-- 4. Sales Order (from the converted revision 2) -> confirmed -> partial delivery
-- ---------------------------------------------------------------------------

INSERT INTO "SALES".so_hdrs (so_number, customer_id, quote_id, price_list_id, status, created_by)
SELECT 'SO-2026-0001', q2.customer_id, q2.id, q2.price_list_id, 'partially_fulfilled',
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "SALES".quot_hdrs q2 WHERE q2.revision_no = 2;

-- Back-fill the quote's converted_so_id pointer (separate statement — the row
-- being updated wasn't inserted earlier in THIS statement, so this is safe)
UPDATE "SALES".quot_hdrs
SET converted_so_id = so.id
FROM "SALES".so_hdrs so
WHERE so.so_number = 'SO-2026-0001' AND "SALES".quot_hdrs.revision_no = 2;

INSERT INTO "SALES".so_lines (so_hdr_id, line_no, item_type, description, qty_ordered, qty_delivered, unit_price, discount_amount)
SELECT so.id, 1, 'service', 'Litigation Retainer - Monthly', 2, 1, 15000000, 1500000 -- 2 months ordered, 1 delivered so far
FROM "SALES".so_hdrs so WHERE so.so_number = 'SO-2026-0001';

INSERT INTO "SALES".dlv_hdrs (so_hdr_id, status, shipped_at, delivered_at)
SELECT so.id, 'delivered', TIMESTAMPTZ '2026-08-01 09:00:00+07', TIMESTAMPTZ '2026-08-01 09:05:00+07'
FROM "SALES".so_hdrs so WHERE so.so_number = 'SO-2026-0001';

INSERT INTO "SALES".dlv_lines (dlv_hdr_id, so_line_id, qty_shipped)
SELECT d.id, sl.id, 1
FROM "SALES".dlv_hdrs d
JOIN "SALES".so_hdrs so ON so.id = d.so_hdr_id AND so.so_number = 'SO-2026-0001'
JOIN "SALES".so_lines sl ON sl.so_hdr_id = so.id;

-- ---------------------------------------------------------------------------
-- 5. Contract & Subscription driving a recurring billing schedule
-- ---------------------------------------------------------------------------

INSERT INTO "SALES".contr_hdrs (customer_id, name, term_start, term_end, auto_renew, status, price_list_id, created_by)
SELECT p.id, 'Example Corp - Annual Retainer Agreement', DATE '2026-08-01', DATE '2027-07-31', TRUE, 'active', pl.id,
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "CRM".partners p, "SALES".price_lists pl
WHERE p.name = 'Example Corp' AND pl.name = 'Standard 2026 Price List';

INSERT INTO "SALES".contr_subscriptions (contr_hdr_id, line_no, item_type, description, recurring_amount, currency, billing_interval)
SELECT c.id, 1, 'service', 'Litigation Retainer - Monthly', 13500000, 'IDR', 'monthly'
FROM "SALES".contr_hdrs c WHERE c.name = 'Example Corp - Annual Retainer Agreement';

INSERT INTO "SALES".recurring_billing_schedules (contr_subscription_id, customer_id, next_bill_date, last_billed_at)
SELECT cs.id, c.customer_id, DATE '2026-09-01', TIMESTAMPTZ '2026-08-01 08:00:00+07'
FROM "SALES".contr_subscriptions cs
JOIN "SALES".contr_hdrs c ON c.id = cs.contr_hdr_id AND c.name = 'Example Corp - Annual Retainer Agreement';

-- ---------------------------------------------------------------------------
-- 6. Return against the first delivered month (partial dissatisfaction credit)
-- ---------------------------------------------------------------------------

INSERT INTO "SALES".ret_hdrs (so_hdr_id, customer_id, reason_code, status, created_by)
SELECT so.id, so.customer_id, 'service_shortfall', 'approved', (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM "SALES".so_hdrs so WHERE so.so_number = 'SO-2026-0001';

INSERT INTO "SALES".ret_lines (ret_hdr_id, so_line_id, qty_returned, condition_notes)
SELECT r.id, sl.id, 1, 'Partial credit agreed after August billing dispute call.'
FROM "SALES".ret_hdrs r
JOIN "SALES".so_hdrs so ON so.id = r.so_hdr_id AND so.so_number = 'SO-2026-0001'
JOIN "SALES".so_lines sl ON sl.so_hdr_id = so.id;

-- ---------------------------------------------------------------------------
-- 7. Commission settlement for the rep, with an earned line and a reversal
--    (mirroring the return above — never edits an already-earned line)
-- ---------------------------------------------------------------------------

INSERT INTO "SALES".comm_settlements (rep_id, period_start, period_end, status, total_amount)
SELECT (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), DATE '2026-08-01', DATE '2026-08-31', 'draft', 607500
;

INSERT INTO "SALES".comm_settlement_lines (settlement_id, commission_plan_id, so_line_id, line_type, amount, notes)
SELECT cs.id, cp.id, sl.id, 'earned', 675000, 'August retainer invoice paid in full (5% of IDR 13,500,000).'
FROM "SALES".comm_settlements cs, "SALES".commission_plans cp, "SALES".so_lines sl
JOIN "SALES".so_hdrs so ON so.id = sl.so_hdr_id AND so.so_number = 'SO-2026-0001'
WHERE cs.period_start = DATE '2026-08-01' AND cp.name = 'Litigation Team Standard Commission'
UNION ALL
SELECT cs.id, cp.id, sl.id, 'reversal', -67500, 'Reversal for the approved return above (5% of the credited amount).'
FROM "SALES".comm_settlements cs, "SALES".commission_plans cp, "SALES".so_lines sl
JOIN "SALES".so_hdrs so ON so.id = sl.so_hdr_id AND so.so_number = 'SO-2026-0001'
WHERE cs.period_start = DATE '2026-08-01' AND cp.name = 'Litigation Team Standard Commission';

COMMIT;

-- =============================================================================
-- Sanity check counts
-- =============================================================================
SELECT 'price_lists' AS table_name, COUNT(*) FROM "SALES".price_lists
UNION ALL SELECT 'opp_hdrs', COUNT(*) FROM "SALES".opp_hdrs
UNION ALL SELECT 'quot_hdrs', COUNT(*) FROM "SALES".quot_hdrs
UNION ALL SELECT 'so_hdrs', COUNT(*) FROM "SALES".so_hdrs
UNION ALL SELECT 'dlv_hdrs', COUNT(*) FROM "SALES".dlv_hdrs
UNION ALL SELECT 'contr_hdrs', COUNT(*) FROM "SALES".contr_hdrs
UNION ALL SELECT 'recurring_billing_schedules', COUNT(*) FROM "SALES".recurring_billing_schedules
UNION ALL SELECT 'ret_hdrs', COUNT(*) FROM "SALES".ret_hdrs
UNION ALL SELECT 'comm_settlements', COUNT(*) FROM "SALES".comm_settlements
UNION ALL SELECT 'comm_settlement_lines', COUNT(*) FROM "SALES".comm_settlement_lines;
