-- =============================================================================
-- POS module (Point of Sale — configurable transaction engine) — schema +
-- example seed data
-- Source spec: app/Modules/POS/POS_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), schema "POS", no tenant_id column
--              anywhere.
--
-- Cross-module references follow the same convention MES_SPECS.sql / PP_SPECS.sql
-- already established: a real FK only where the referenced module is a hard,
-- always-present dependency; an *informational* BIGINT column (commented,
-- never a real FK) everywhere the referenced module is plan-optional relative
-- to POS (CLAUDE.md §4 — modules are meant to be toggle-able per tenant plan),
-- exactly as POS_SPECS.md's own prose repeatedly states ("if the tenant has
-- Sales installed", "if Sales is not installed", "HCM.employees ... when HCM
-- is installed").
--   - INVENTORY.products / INVENTORY.warehouses / INVENTORY.stock_batches /
--     INVENTORY.stock_serials  → REAL FK (Inventory is on every plan today,
--     config/tenant_modules.php).
--   - users (Laravel's own core table)                        → REAL FK.
--   - SALES.price_lists (pos_terminals.default_price_list_id, pos_txn_hdrs.
--     price_list_id) → informational only, per POS_SPECS.md §3G/§3J's own
--     "if Sales is not installed" language.
--   - CRM.partners (pos_txn_hdrs.customer_id, pos_loyalty_accounts.customer_id,
--     pos_store_credits.customer_id) → informational only, per §3I "optional
--     identification".
--   - HCM.employees (pos_sessions.cashier_employee_id) → informational only,
--     per §3C/§7's open item ("HCM.employees ... a fallback to plain SYSCONFIG
--     platform users").
--
-- One narrow, deliberate change to an EXISTING Core module's table: §3E reuses
-- INVENTORY.product_barcodes for PLU codes (`type = 'plu'`) rather than a
-- second barcode table. INVENTORY_SPECS.sql's own CHECK constraint on
-- product_barcodes.type only allows ('primary','case_pack','alternate'), so
-- this script widens it additively (see step 0 below) — the same category of
-- narrow, explained cross-module DDL touch PP_SPECS.sql/MES_SPECS.sql already
-- use when one module's schema needs to accommodate another's reuse of it.
--
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- or other client-specific meta-commands — so it runs through any Postgres
-- client.
-- =============================================================================

BEGIN;

CREATE SCHEMA IF NOT EXISTS "POS";

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- -----------------------------------------------------------------------------
-- 0. Narrow, additive widening of INVENTORY.product_barcodes.type so POS can
--    store PLU codes in the same table (§3E) instead of a second one. Purely
--    additive — every existing 'primary'/'case_pack'/'alternate' row and every
--    other caller of that table is unaffected.
-- -----------------------------------------------------------------------------

ALTER TABLE "INVENTORY".product_barcodes DROP CONSTRAINT IF EXISTS product_barcodes_type_check;
ALTER TABLE "INVENTORY".product_barcodes ADD CONSTRAINT product_barcodes_type_check
    CHECK (type IN ('primary', 'case_pack', 'alternate', 'plu'));

-- -----------------------------------------------------------------------------
-- §3A / §4. POS Profile & Capability Matrix
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_profiles (
    id                      BIGSERIAL PRIMARY KEY,
    code                    VARCHAR(30) NOT NULL UNIQUE,
    name                    VARCHAR(150) NOT NULL,
    base_type               VARCHAR(15) NOT NULL DEFAULT 'retail' CHECK (base_type IN ('retail', 'restaurant', 'service')),
    requires_barcode        BOOLEAN NOT NULL DEFAULT TRUE,
    touch_menu              BOOLEAN NOT NULL DEFAULT FALSE,
    multi_uom               BOOLEAN NOT NULL DEFAULT TRUE,
    batch_expiry_tracking   BOOLEAN NOT NULL DEFAULT FALSE,
    weight_scale            BOOLEAN NOT NULL DEFAULT FALSE,
    customer_required       BOOLEAN NOT NULL DEFAULT FALSE,
    loyalty_enabled         BOOLEAN NOT NULL DEFAULT TRUE,
    promotion_enabled       BOOLEAN NOT NULL DEFAULT TRUE,
    table_management        BOOLEAN NOT NULL DEFAULT FALSE,
    modifiers_enabled       BOOLEAN NOT NULL DEFAULT FALSE,
    kds_enabled              BOOLEAN NOT NULL DEFAULT FALSE,
    recipe_consumption      BOOLEAN NOT NULL DEFAULT FALSE,
    delivery_enabled        BOOLEAN NOT NULL DEFAULT FALSE,
    offline_enabled         BOOLEAN NOT NULL DEFAULT TRUE,   -- Backgrounds: on by default for every profile
    multi_branch            BOOLEAN NOT NULL DEFAULT TRUE,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3B / §4. Terminal / Branch / Register Topology
-- -----------------------------------------------------------------------------

-- Minimal branch lookup, only until a tenant-wide Branch concept exists
-- elsewhere in the platform (POS_SPECS.md §5/§7 open item).
CREATE TABLE IF NOT EXISTS "POS".pos_branches (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "POS".pos_terminals (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing, offline client pairing (CLAUDE.md §7A)
    branch_id               BIGINT REFERENCES "POS".pos_branches (id),
    warehouse_id            BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id), -- which warehouse this terminal's sales issue stock from
    profile_id              BIGINT NOT NULL REFERENCES "POS".pos_profiles (id),
    code                    VARCHAR(30) NOT NULL UNIQUE,
    name                    VARCHAR(150) NOT NULL,
    default_price_list_id   BIGINT,                     -- informational; SALES.price_lists.id when Sales is installed (§3G)
    default_tax_code        VARCHAR(20),
    receipt_template        VARCHAR(50),
    receipt_prefix          VARCHAR(10) NOT NULL UNIQUE, -- §3S: own local numbering, never SYSCONFIG.config_snums
    last_local_seq          BIGINT NOT NULL DEFAULT 0,   -- server-side mirror for online terminals; the offline client owns its own counter
    device_fingerprint      VARCHAR(255),
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pos_terminals_branch ON "POS".pos_terminals (branch_id);
CREATE INDEX IF NOT EXISTS idx_pos_terminals_warehouse ON "POS".pos_terminals (warehouse_id);

CREATE TABLE IF NOT EXISTS "POS".pos_terminal_devices (
    id                  BIGSERIAL PRIMARY KEY,
    terminal_id         BIGINT NOT NULL REFERENCES "POS".pos_terminals (id) ON DELETE CASCADE,
    device_type         VARCHAR(20) NOT NULL CHECK (device_type IN (
                            'receipt_printer', 'kitchen_printer', 'cash_drawer',
                            'customer_display', 'weighing_scale', 'card_terminal'
                        )),
    adapter_code        VARCHAR(50) NOT NULL,           -- POSHardwareAdapter implementation code (§3Q)
    connection_config   JSONB,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS idx_pos_terminal_devices_terminal ON "POS".pos_terminal_devices (terminal_id);

-- -----------------------------------------------------------------------------
-- §3E / §4. Product Catalog & Search — POS-owned additions only (Inventory's
-- product/barcode/UoM master itself is reused unmodified, per §5's boundary
-- note; see step 0 above for the one narrow exception).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_weighted_barcode_templates (
    id                  BIGSERIAL PRIMARY KEY,
    name                VARCHAR(100) NOT NULL,
    prefix_from         VARCHAR(4) NOT NULL,            -- leading-digit range, e.g. '20'-'29'
    prefix_to           VARCHAR(4) NOT NULL,
    item_code_start     SMALLINT NOT NULL,
    item_code_length    SMALLINT NOT NULL,
    value_start         SMALLINT NOT NULL,
    value_length        SMALLINT NOT NULL,
    value_type          VARCHAR(10) NOT NULL CHECK (value_type IN ('weight', 'price')),
    decimal_places       SMALLINT NOT NULL DEFAULT 3,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "POS".pos_favorite_items (
    id                  BIGSERIAL PRIMARY KEY,
    terminal_id         BIGINT REFERENCES "POS".pos_terminals (id) ON DELETE CASCADE,
    cashier_user_id     BIGINT REFERENCES users (id),
    product_id          BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    sort_order          INT NOT NULL DEFAULT 0,
    CHECK (terminal_id IS NOT NULL OR cashier_user_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_pos_favorite_items_terminal ON "POS".pos_favorite_items (terminal_id);

-- -----------------------------------------------------------------------------
-- §3H / §4. Promotion Engine (Phase 2)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_promotion_rules (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    type            VARCHAR(25) NOT NULL CHECK (type IN (
                        'simple_discount', 'buy_x_get_y', 'bundle', 'mix_and_match',
                        'threshold', 'time_window', 'customer_tier', 'promo_code_passthrough'
                    )),
    scope           VARCHAR(15) NOT NULL DEFAULT 'basket' CHECK (scope IN ('product', 'category', 'basket')),
    value_type      VARCHAR(10) CHECK (value_type IN ('percent', 'fixed', 'bundle_price')),
    value           NUMERIC(14, 4),
    constraints     JSONB,                              -- qty thresholds, valid product set, time window, customer tier/segment
    valid_from      TIMESTAMPTZ,
    valid_to        TIMESTAMPTZ,
    priority        INT NOT NULL DEFAULT 0,
    stackable       BOOLEAN NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3M / §4. Restaurant Extension — Floor & Table Management (Phase 2)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_floors (
    id              BIGSERIAL PRIMARY KEY,
    branch_id       BIGINT REFERENCES "POS".pos_branches (id),
    name            VARCHAR(100) NOT NULL,
    layout_ref      VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS "POS".pos_tables (
    id              BIGSERIAL PRIMARY KEY,
    floor_id        BIGINT NOT NULL REFERENCES "POS".pos_floors (id) ON DELETE CASCADE,
    code            VARCHAR(20) NOT NULL,
    seat_count      INT NOT NULL DEFAULT 4,
    pos_x           INT NOT NULL DEFAULT 0,
    pos_y           INT NOT NULL DEFAULT 0,
    status          VARCHAR(15) NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'occupied', 'reserved', 'cleaning')),
    UNIQUE (floor_id, code)
);

-- -----------------------------------------------------------------------------
-- §3N / §4. Restaurant Extension — Order Lines & Modifiers (Phase 2)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_modifier_groups (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    selection_type  VARCHAR(10) NOT NULL DEFAULT 'single' CHECK (selection_type IN ('single', 'multiple')),
    min_selections  INT NOT NULL DEFAULT 0,
    max_selections  INT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS "POS".pos_modifiers (
    id                      BIGSERIAL PRIMARY KEY,
    group_id                BIGINT NOT NULL REFERENCES "POS".pos_modifier_groups (id) ON DELETE CASCADE,
    name                    VARCHAR(100) NOT NULL,
    price_delta             NUMERIC(14, 2) NOT NULL DEFAULT 0,
    replaces_base_price     BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS idx_pos_modifiers_group ON "POS".pos_modifiers (group_id);

CREATE TABLE IF NOT EXISTS "POS".pos_product_modifier_groups (
    id              BIGSERIAL PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    group_id        BIGINT NOT NULL REFERENCES "POS".pos_modifier_groups (id) ON DELETE CASCADE,
    UNIQUE (product_id, group_id)
);

-- -----------------------------------------------------------------------------
-- §3O / §4. Kitchen Display System (Phase 2)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_kds_stations (
    id              BIGSERIAL PRIMARY KEY,
    branch_id       BIGINT REFERENCES "POS".pos_branches (id),
    code            VARCHAR(30) NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS "POS".pos_product_kds_routing (
    id                  BIGSERIAL PRIMARY KEY,
    product_id          BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    kds_station_id      BIGINT NOT NULL REFERENCES "POS".pos_kds_stations (id),
    UNIQUE (product_id, kds_station_id)
);

-- -----------------------------------------------------------------------------
-- §3R / §4. Loyalty Tiers (Phase 2 config)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_loyalty_tiers (
    id                          BIGSERIAL PRIMARY KEY,
    name                        VARCHAR(50) NOT NULL,
    points_per_currency_unit    NUMERIC(10, 4) NOT NULL DEFAULT 1,
    tier_threshold              NUMERIC(14, 2) NOT NULL DEFAULT 0,
    sort_order                  INT NOT NULL DEFAULT 0
);

-- -----------------------------------------------------------------------------
-- §3C / §4. POS Session (Cash Shift)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_sessions (
    id                      BIGSERIAL PRIMARY KEY,
    terminal_id             BIGINT NOT NULL REFERENCES "POS".pos_terminals (id),
    cashier_user_id         BIGINT NOT NULL REFERENCES users (id),
    cashier_employee_id     BIGINT,                     -- informational; HCM.employees.id when HCM is installed (§3C/§7)
    opened_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    opening_cash            NUMERIC(14, 2) NOT NULL DEFAULT 0,
    status                  VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closed')),
    closed_at               TIMESTAMPTZ,
    expected_cash           NUMERIC(14, 2),
    actual_cash             NUMERIC(14, 2),
    variance                NUMERIC(14, 2),
    closed_by               BIGINT REFERENCES users (id),
    approved_by             BIGINT REFERENCES users (id) -- supervisor sign-off on a variance beyond threshold (§3C/§3T)
);

-- One open session per terminal at a time (§3C).
CREATE UNIQUE INDEX IF NOT EXISTS uq_pos_sessions_one_open_per_terminal ON "POS".pos_sessions (terminal_id) WHERE status = 'open';
CREATE INDEX IF NOT EXISTS idx_pos_sessions_terminal ON "POS".pos_sessions (terminal_id);

-- Append-only (§3C/§5).
CREATE TABLE IF NOT EXISTS "POS".pos_cash_movements (
    id              BIGSERIAL PRIMARY KEY,
    session_id      BIGINT NOT NULL REFERENCES "POS".pos_sessions (id),
    type            VARCHAR(15) NOT NULL CHECK (type IN ('cash_in', 'cash_out', 'petty_cash')),
    amount          NUMERIC(14, 2) NOT NULL CHECK (amount > 0),
    reason          VARCHAR(255),
    user_id         BIGINT NOT NULL REFERENCES users (id),
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pos_cash_movements_session ON "POS".pos_cash_movements (session_id);

-- -----------------------------------------------------------------------------
-- §3F / §3J / §4. Cart / Transaction Header — carries §3S's offline
-- idempotency columns (client_txn_uuid, occurred_at, synced_at) and §3J's AR
-- boundary flag (is_on_account) from day one, not bolted on later.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_txn_hdrs (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing (CLAUDE.md §7A)
    client_txn_uuid         UUID NOT NULL UNIQUE,      -- §3S: client-generated at cart-creation time, sync idempotency key
    session_id              BIGINT NOT NULL REFERENCES "POS".pos_sessions (id),
    terminal_id             BIGINT NOT NULL REFERENCES "POS".pos_terminals (id),
    receipt_number          VARCHAR(30) NOT NULL UNIQUE, -- {terminal.receipt_prefix}-{local_seq}, never SYSCONFIG.config_snums (§3S)
    customer_id             BIGINT,                    -- informational; CRM.partners.id (§3I)
    table_id                BIGINT REFERENCES "POS".pos_tables (id), -- only meaningful when table_management is on (§3A/§3M)
    dining_mode             VARCHAR(12) NOT NULL DEFAULT 'sale' CHECK (dining_mode IN ('sale', 'dine_in', 'takeaway', 'delivery')),
    price_list_id           BIGINT,                    -- informational; SALES.price_lists.id (§3G)
    status                  VARCHAR(12) NOT NULL DEFAULT 'draft' CHECK (status IN (
                                'draft', 'parked', 'completed', 'voided', 'cancelled', 'refunded'
                            )),
    subtotal                NUMERIC(14, 2) NOT NULL DEFAULT 0,
    discount_total          NUMERIC(14, 2) NOT NULL DEFAULT 0,
    tax_total                NUMERIC(14, 2) NOT NULL DEFAULT 0,
    service_charge           NUMERIC(14, 2) NOT NULL DEFAULT 0,
    rounding                 NUMERIC(10, 2) NOT NULL DEFAULT 0,
    grand_total               NUMERIC(14, 2) NOT NULL DEFAULT 0,
    is_on_account             BOOLEAN NOT NULL DEFAULT FALSE, -- §3J: routes AR through Sales instead of session-close journal
    sales_order_subject_id   BIGINT,                    -- informational; SALES.so_hdrs.id once SalesOrderService responds (§3J)
    park_label               VARCHAR(100),
    notes                     TEXT,
    created_offline           BOOLEAN NOT NULL DEFAULT FALSE,
    occurred_at               TIMESTAMPTZ NOT NULL DEFAULT now(), -- terminal clock (§3S)
    synced_at                 TIMESTAMPTZ,               -- server receipt time, NULL until synced (§3S)
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pos_txn_hdrs_session ON "POS".pos_txn_hdrs (session_id);
CREATE INDEX IF NOT EXISTS idx_pos_txn_hdrs_terminal_occurred ON "POS".pos_txn_hdrs (terminal_id, occurred_at);
CREATE INDEX IF NOT EXISTS idx_pos_txn_hdrs_customer ON "POS".pos_txn_hdrs (customer_id);
CREATE INDEX IF NOT EXISTS idx_pos_txn_hdrs_status ON "POS".pos_txn_hdrs (status);

CREATE TABLE IF NOT EXISTS "POS".pos_txn_lines (
    id                      BIGSERIAL PRIMARY KEY,
    txn_id                  BIGINT NOT NULL REFERENCES "POS".pos_txn_hdrs (id) ON DELETE CASCADE,
    line_no                 INT NOT NULL,
    product_id              BIGINT REFERENCES "INVENTORY".products (id), -- NULL only for an open item (§3E)
    is_open_item            BOOLEAN NOT NULL DEFAULT FALSE,
    description             VARCHAR(255) NOT NULL,     -- open-item free text, or a resolved-product-name snapshot
    uom_code                VARCHAR(10),
    qty                     NUMERIC(14, 4) NOT NULL,
    unit_price               NUMERIC(14, 2) NOT NULL,
    discount_amount           NUMERIC(14, 2) NOT NULL DEFAULT 0,
    tax_amount                NUMERIC(14, 2) NOT NULL DEFAULT 0,
    line_total                 NUMERIC(14, 2) NOT NULL,
    batch_id                    BIGINT REFERENCES "INVENTORY".stock_batches (id),
    serial_id                    BIGINT REFERENCES "INVENTORY".stock_serials (id),
    kds_station_id                BIGINT REFERENCES "POS".pos_kds_stations (id), -- §3N/§3O, Phase 2
    course                         VARCHAR(20),
    seat_number                     INT,
    special_instruction               TEXT,
    kitchen_note                       TEXT,
    kds_status                          VARCHAR(12) CHECK (kds_status IN ('new', 'preparing', 'ready', 'served')),
    inventory_posted                     BOOLEAN NOT NULL DEFAULT FALSE, -- §3K: set once InventoryService::issue() succeeds
    CHECK (is_open_item OR product_id IS NOT NULL),
    UNIQUE (txn_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_pos_txn_lines_txn ON "POS".pos_txn_lines (txn_id);
CREATE INDEX IF NOT EXISTS idx_pos_txn_lines_product ON "POS".pos_txn_lines (product_id);

-- §3N, Phase 2 — price_delta is a snapshot at time of sale, never re-resolved.
CREATE TABLE IF NOT EXISTS "POS".pos_txn_line_modifiers (
    id                  BIGSERIAL PRIMARY KEY,
    txn_line_id         BIGINT NOT NULL REFERENCES "POS".pos_txn_lines (id) ON DELETE CASCADE,
    modifier_id         BIGINT NOT NULL REFERENCES "POS".pos_modifiers (id),
    modifier_name       VARCHAR(100) NOT NULL,          -- snapshot
    price_delta         NUMERIC(14, 2) NOT NULL DEFAULT 0 -- snapshot
);

CREATE INDEX IF NOT EXISTS idx_pos_txn_line_modifiers_line ON "POS".pos_txn_line_modifiers (txn_line_id);

-- §3O, Phase 2 — append-only ticket status trail per line.
CREATE TABLE IF NOT EXISTS "POS".pos_kds_ticket_events (
    id              BIGSERIAL PRIMARY KEY,
    txn_line_id     BIGINT NOT NULL REFERENCES "POS".pos_txn_lines (id),
    status          VARCHAR(12) NOT NULL CHECK (status IN ('new', 'preparing', 'ready', 'served', 'refired')),
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    user_id         BIGINT REFERENCES users (id),
    note            TEXT
);

CREATE INDEX IF NOT EXISTS idx_pos_kds_ticket_events_line ON "POS".pos_kds_ticket_events (txn_line_id);

-- -----------------------------------------------------------------------------
-- §3I / §4. Payment Engine
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_payments (
    id                  BIGSERIAL PRIMARY KEY,
    txn_id              BIGINT NOT NULL REFERENCES "POS".pos_txn_hdrs (id) ON DELETE CASCADE,
    method              VARCHAR(15) NOT NULL CHECK (method IN (
                            'cash', 'card', 'qris', 'bank_transfer', 'e_wallet', 'voucher',
                            'gift_card', 'store_credit', 'customer_credit', 'on_account'
                        )),
    amount              NUMERIC(14, 2) NOT NULL,
    reference           VARCHAR(100),                    -- auth/approval code, free text (no live gateway in Phase 1/2)
    change_given        NUMERIC(14, 2) NOT NULL DEFAULT 0,
    gift_card_id        BIGINT,                           -- FK added below once pos_gift_cards exists
    store_credit_id     BIGINT,                           -- FK added below once pos_store_credits exists
    occurred_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pos_payments_txn ON "POS".pos_payments (txn_id);

-- -----------------------------------------------------------------------------
-- §3L / §4. Returns / Refunds
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_return_hdrs (
    id                  BIGSERIAL PRIMARY KEY,
    original_txn_id     BIGINT NOT NULL REFERENCES "POS".pos_txn_hdrs (id),
    session_id          BIGINT NOT NULL REFERENCES "POS".pos_sessions (id),
    reason_code         VARCHAR(30) NOT NULL,
    status              VARCHAR(12) NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'approved', 'completed', 'rejected')),
    refund_method       VARCHAR(15) CHECK (refund_method IN (
                            'cash', 'card', 'qris', 'bank_transfer', 'e_wallet',
                            'voucher', 'gift_card', 'store_credit', 'customer_credit'
                        )),
    without_receipt     BOOLEAN NOT NULL DEFAULT FALSE,   -- §3L: allowed only if tenant-configured, requires manager PIN
    approved_by         BIGINT REFERENCES users (id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pos_return_hdrs_original_txn ON "POS".pos_return_hdrs (original_txn_id);

CREATE TABLE IF NOT EXISTS "POS".pos_return_lines (
    id                          BIGSERIAL PRIMARY KEY,
    return_id                   BIGINT NOT NULL REFERENCES "POS".pos_return_hdrs (id) ON DELETE CASCADE,
    original_txn_line_id        BIGINT REFERENCES "POS".pos_txn_lines (id),
    qty                          NUMERIC(14, 4) NOT NULL,
    unit_price                    NUMERIC(14, 2) NOT NULL,
    condition_note                  VARCHAR(255),
    restockable                        BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS idx_pos_return_lines_return ON "POS".pos_return_lines (return_id);

-- -----------------------------------------------------------------------------
-- §3R / §4. Gift Card / Store Credit (Phase 2) — created before Loyalty so
-- pos_payments' FKs can be added immediately after.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_gift_cards (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30) NOT NULL UNIQUE,
    balance         NUMERIC(14, 2) NOT NULL DEFAULT 0,
    currency        VARCHAR(3) NOT NULL DEFAULT 'IDR',
    expiry_date     DATE,
    status          VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'redeemed', 'expired')),
    issued_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "POS".pos_store_credits (
    id              BIGSERIAL PRIMARY KEY,
    customer_id     BIGINT NOT NULL,                    -- informational; CRM.partners.id (§3I)
    balance         NUMERIC(14, 2) NOT NULL DEFAULT 0,
    source_type     VARCHAR(30),                        -- e.g. 'pos.pos_return_hdrs'
    source_id       BIGINT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pos_store_credits_customer ON "POS".pos_store_credits (customer_id);

ALTER TABLE "POS".pos_payments ADD CONSTRAINT fk_pos_payments_gift_card FOREIGN KEY (gift_card_id) REFERENCES "POS".pos_gift_cards (id);
ALTER TABLE "POS".pos_payments ADD CONSTRAINT fk_pos_payments_store_credit FOREIGN KEY (store_credit_id) REFERENCES "POS".pos_store_credits (id);

-- -----------------------------------------------------------------------------
-- §3R / §4. Loyalty / Membership (Phase 2)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_loyalty_accounts (
    id              BIGSERIAL PRIMARY KEY,
    customer_id     BIGINT NOT NULL UNIQUE,             -- informational; CRM.partners.id (§3I)
    tier_id         BIGINT REFERENCES "POS".pos_loyalty_tiers (id),
    points_balance  NUMERIC(14, 2) NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Append-only (§3R/§5).
CREATE TABLE IF NOT EXISTS "POS".pos_loyalty_ledger (
    id              BIGSERIAL PRIMARY KEY,
    account_id      BIGINT NOT NULL REFERENCES "POS".pos_loyalty_accounts (id),
    txn_id          BIGINT REFERENCES "POS".pos_txn_hdrs (id),
    type            VARCHAR(10) NOT NULL CHECK (type IN ('earn', 'redeem', 'expire', 'adjust')),
    points_delta    NUMERIC(14, 2) NOT NULL,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pos_loyalty_ledger_account ON "POS".pos_loyalty_ledger (account_id);

-- -----------------------------------------------------------------------------
-- §3T / §4. Security & Permissions — in-transaction supervisor override log.
-- Append-only, mirrors mes_audit_logs/config_audit_logs (§5).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "POS".pos_override_logs (
    id              BIGSERIAL PRIMARY KEY,
    txn_id          BIGINT REFERENCES "POS".pos_txn_hdrs (id),
    session_id      BIGINT REFERENCES "POS".pos_sessions (id),
    action_type     VARCHAR(30) NOT NULL CHECK (action_type IN (
                        'discount_above_threshold', 'item_void', 'sale_void', 'refund',
                        'price_override', 'drawer_open', 'session_reopen'
                    )),
    requested_by    BIGINT NOT NULL REFERENCES users (id),
    authorized_by   BIGINT NOT NULL REFERENCES users (id),
    reason          VARCHAR(255),
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (txn_id IS NOT NULL OR session_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_pos_override_logs_txn ON "POS".pos_override_logs (txn_id);
CREATE INDEX IF NOT EXISTS idx_pos_override_logs_session ON "POS".pos_override_logs (session_id);

-- =============================================================================
-- NOTE — deliberately not created here: SYSCONFIG.config_consts rows
-- (POS_ALLOW_OVERSELL, POS_DISCOUNT_PIN_ABOVE, gift-card/loyalty offline-
-- redemption policy, cash-variance threshold — POS_SPECS.md §3C/§3K/§3R/§3T)
-- and SYSCONFIG.config_snums (deliberately NOT used for receipt numbering —
-- §3S); CUSTOMFIELDS.field_defs rows for pos_txn_hdrs (POS_SPECS.md §4
-- custom-fields registration — a CustomFields-module concern, not this
-- schema's own DDL); WNE.wrkflow_* rows for `pos.return_approval` (§3L) and
-- `pos.qc`-style notifications — all owned by their respective already-built
-- modules, not re-created here.
-- =============================================================================

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
--
-- Self-contained: unlike MES_SPECS.sql (which assumes PP_SPECS.sql already
-- ran), this script inserts its own small set of INVENTORY prerequisite rows
-- (products/uoms/barcodes/warehouse) and CRM.partners rows, each guarded by
-- WHERE NOT EXISTS / ON CONFLICT DO NOTHING so it is safe to run whether or
-- not INVENTORY_SPECS.sql / CRM_SPECS.sql have already seeded their own demo
-- data — the SKUs/codes below (POS-* prefix) are chosen not to collide with
-- INVENTORY_SPECS.sql's own seed (SKU-1001/SKU-2001).
--
-- Scenario: one branch, two terminals — POS01 (Convenience Store profile,
-- barcode-first) and POS02 (Restaurant profile, table/modifier/KDS on) — per
-- POS_SPECS.md §3A's own "one branch, two profiles on two terminals" example.
-- Covers, deliberately, everything §1 Backgrounds argues POS must prove in
-- one pass: a case-pack barcode sale, a PLU/weighed item, an offline-created
-- transaction, a dine-in order with a modifier and a KDS ticket, the §3J
-- walk-in-vs-on-account AR posting fork (one of each), a return with
-- automatic reversal, and a supervisor override.
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 0. INVENTORY / CRM prerequisites this seed needs and cannot assume already
--    exist — a warehouse, two UoMs + their conversion, four products with
--    barcodes (one case-pack, one PLU-weighed), and two CRM partners.
-- ---------------------------------------------------------------------------

-- INVENTORY.warehouses has no `code` column (name is the natural key here,
-- per the actual committed INVENTORY_SPECS.sql schema).
INSERT INTO "INVENTORY".warehouses (name, address)
SELECT 'POS Front Counter Stockroom', 'Nusaevo Demo Store — Kemang'
WHERE NOT EXISTS (SELECT 1 FROM "INVENTORY".warehouses WHERE name = 'POS Front Counter Stockroom');

INSERT INTO "INVENTORY".uoms (code, name)
SELECT v.code, v.name FROM (VALUES ('EA', 'Each'), ('CTN', 'Carton'), ('KG', 'Kilogram')) AS v (code, name)
WHERE NOT EXISTS (SELECT 1 FROM "INVENTORY".uoms u WHERE u.code = v.code);

INSERT INTO "INVENTORY".uom_conversions (from_uom_id, to_uom_id, conversion_factor)
SELECT c.id, e.id, 24
FROM "INVENTORY".uoms c, "INVENTORY".uoms e
WHERE c.code = 'CTN' AND e.code = 'EA'
  AND NOT EXISTS (SELECT 1 FROM "INVENTORY".uom_conversions x WHERE x.from_uom_id = c.id AND x.to_uom_id = e.id);

INSERT INTO "INVENTORY".products (sku, name, base_uom_id, costing_method, tracking_mode)
SELECT v.sku, v.name, u.id, 'fifo', 'none'
FROM (VALUES
    ('POS-AQUA600', 'Aqua Mineral Water 600ml', 'EA'),
    ('POS-SNACK-CHIPS', 'Potato Chips 68g', 'EA'),
    ('POS-BANANA', 'Banana (loose, by weight)', 'KG'),
    ('POS-NASGOR', 'Nasi Goreng Spesial', 'EA'),
    ('POS-ESTEH', 'Es Teh Manis', 'EA')
) AS v (sku, name, uom_code)
JOIN "INVENTORY".uoms u ON u.code = v.uom_code
WHERE NOT EXISTS (SELECT 1 FROM "INVENTORY".products p WHERE p.sku = v.sku);

INSERT INTO "INVENTORY".product_barcodes (product_id, barcode, type, unit_multiplier)
SELECT p.id, v.barcode, v.type, v.unit_multiplier
FROM (VALUES
    ('POS-AQUA600', '8991000300101', 'primary', 1),
    ('POS-AQUA600', '8991000300201', 'case_pack', 24), -- 1 carton = 24 bottles, §3 user brief example
    ('POS-SNACK-CHIPS', '8991000400101', 'primary', 1),
    ('POS-BANANA', '4011', 'plu', 1)                    -- classic PLU code
) AS v (sku, barcode, type, unit_multiplier)
JOIN "INVENTORY".products p ON p.sku = v.sku
WHERE NOT EXISTS (SELECT 1 FROM "INVENTORY".product_barcodes b WHERE b.barcode = v.barcode);

INSERT INTO "CRM".partners (type, name)
SELECT v.type, v.name FROM (VALUES
    ('individual', 'Budi Santoso'),
    ('individual', 'Siti Rahayu')
) AS v (type, name)
WHERE NOT EXISTS (SELECT 1 FROM "CRM".partners p WHERE p.name = v.name);

-- ---------------------------------------------------------------------------
-- 1. POS Profiles — the two seeded defaults §3A's own text names exactly.
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_profiles (
    code, name, base_type, requires_barcode, touch_menu, customer_required,
    table_management, modifiers_enabled, kds_enabled, recipe_consumption
) VALUES
    ('CONVENIENCE', 'Convenience Store', 'retail', TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE),
    ('RESTAURANT', 'Restaurant', 'restaurant', FALSE, TRUE, FALSE, TRUE, TRUE, TRUE, TRUE);

-- ---------------------------------------------------------------------------
-- 2. Branch, terminals, terminal devices.
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_branches (code, name) VALUES ('BR-01', 'Nusaevo Demo Store — Kemang');

INSERT INTO "POS".pos_terminals (branch_id, warehouse_id, profile_id, code, name, receipt_prefix)
SELECT b.id, w.id, pr.id, 'POS01', 'Front Counter 1', 'POS01'
FROM "POS".pos_branches b, "INVENTORY".warehouses w, "POS".pos_profiles pr
WHERE b.code = 'BR-01' AND w.name = 'POS Front Counter Stockroom' AND pr.code = 'CONVENIENCE'
UNION ALL
SELECT b.id, w.id, pr.id, 'POS02', 'Dine-In Register', 'POS02'
FROM "POS".pos_branches b, "INVENTORY".warehouses w, "POS".pos_profiles pr
WHERE b.code = 'BR-01' AND w.name = 'POS Front Counter Stockroom' AND pr.code = 'RESTAURANT';

INSERT INTO "POS".pos_terminal_devices (terminal_id, device_type, adapter_code)
SELECT t.id, 'receipt_printer', 'escpos_usb_80mm' FROM "POS".pos_terminals t WHERE t.code = 'POS01'
UNION ALL
SELECT t.id, 'cash_drawer', 'printer_kick_pulse' FROM "POS".pos_terminals t WHERE t.code = 'POS01'
UNION ALL
SELECT t.id, 'receipt_printer', 'escpos_usb_58mm' FROM "POS".pos_terminals t WHERE t.code = 'POS02'
UNION ALL
SELECT t.id, 'kitchen_printer', 'escpos_network' FROM "POS".pos_terminals t WHERE t.code = 'POS02';

-- ---------------------------------------------------------------------------
-- 3. Weighted-barcode template + favorite items (§3E).
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_weighted_barcode_templates (
    name, prefix_from, prefix_to, item_code_start, item_code_length,
    value_start, value_length, value_type, decimal_places
) VALUES ('Scale-Labeled Produce (weight-embedded)', '20', '29', 2, 5, 7, 5, 'weight', 3);

INSERT INTO "POS".pos_favorite_items (terminal_id, product_id, sort_order)
SELECT t.id, p.id, v.sort_order
FROM "POS".pos_terminals t, "INVENTORY".products p,
     (VALUES ('POS-AQUA600', 1), ('POS-SNACK-CHIPS', 2)) AS v (sku, sort_order)
WHERE t.code = 'POS01' AND p.sku = v.sku;

-- ---------------------------------------------------------------------------
-- 4. Restaurant extension master data (§3M/§3N/§3O) — Main Floor with two
--    tables, a Spice Level modifier group on Nasi Goreng, one Kitchen KDS
--    station.
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_floors (branch_id, name)
SELECT b.id, 'Main Floor' FROM "POS".pos_branches b WHERE b.code = 'BR-01';

INSERT INTO "POS".pos_tables (floor_id, code, seat_count, pos_x, pos_y, status)
SELECT f.id, v.code, v.seats, v.x, v.y, 'available'
FROM "POS".pos_floors f, (VALUES ('T1', 4, 40, 40), ('T2', 2, 200, 40)) AS v (code, seats, x, y)
WHERE f.name = 'Main Floor';

INSERT INTO "POS".pos_modifier_groups (name, selection_type, min_selections, max_selections)
VALUES ('Spice Level', 'single', 1, 1);

INSERT INTO "POS".pos_modifiers (group_id, name, price_delta)
SELECT g.id, v.name, v.delta
FROM "POS".pos_modifier_groups g, (VALUES ('Mild', 0), ('Medium', 0), ('Spicy', 0), ('Extra Spicy', 3000)) AS v (name, delta)
WHERE g.name = 'Spice Level';

INSERT INTO "POS".pos_product_modifier_groups (product_id, group_id)
SELECT p.id, g.id FROM "INVENTORY".products p, "POS".pos_modifier_groups g
WHERE p.sku = 'POS-NASGOR' AND g.name = 'Spice Level';

INSERT INTO "POS".pos_kds_stations (branch_id, code, name)
SELECT b.id, 'KITCHEN', 'Kitchen' FROM "POS".pos_branches b WHERE b.code = 'BR-01';

INSERT INTO "POS".pos_product_kds_routing (product_id, kds_station_id)
SELECT p.id, k.id FROM "INVENTORY".products p, "POS".pos_kds_stations k
WHERE p.sku = 'POS-NASGOR' AND k.code = 'KITCHEN';

-- ---------------------------------------------------------------------------
-- 5. Loyalty tiers (§3R).
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_loyalty_tiers (name, points_per_currency_unit, tier_threshold, sort_order)
VALUES ('Silver', 1, 0, 1), ('Gold', 2, 2000000, 2);

-- ---------------------------------------------------------------------------
-- 6. POS Sessions — POS01 opened this morning by staff, POS02 opened and
--    already closed yesterday with a small (within-threshold) variance.
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_sessions (terminal_id, cashier_user_id, opened_at, opening_cash, status)
SELECT t.id, u.id, TIMESTAMPTZ '2026-09-03 08:00:00+07', 500000, 'open'
FROM "POS".pos_terminals t, users u
WHERE t.code = 'POS01' AND u.email = 'staff@nusaevo.com';

INSERT INTO "POS".pos_sessions (
    terminal_id, cashier_user_id, opened_at, opening_cash, status,
    closed_at, expected_cash, actual_cash, variance, closed_by
)
SELECT t.id, u.id, TIMESTAMPTZ '2026-09-02 10:00:00+07', 300000, 'closed',
       TIMESTAMPTZ '2026-09-02 21:00:00+07', 1850000, 1830000, -20000, u.id
FROM "POS".pos_terminals t, users u
WHERE t.code = 'POS02' AND u.email = 'staff@nusaevo.com';

INSERT INTO "POS".pos_cash_movements (session_id, type, amount, reason, user_id, occurred_at)
SELECT s.id, 'petty_cash', 50000, 'Change coins from bank', u.id, TIMESTAMPTZ '2026-09-03 09:15:00+07'
FROM "POS".pos_sessions s
JOIN "POS".pos_terminals t ON t.id = s.terminal_id AND t.code = 'POS01' AND s.status = 'open'
JOIN users u ON u.email = 'staff@nusaevo.com';

-- ---------------------------------------------------------------------------
-- 7. Transaction 1 — POS01 walk-in convenience sale: 1 carton Aqua (case-pack
--    barcode, 24x conversion), 1 bag chips, 0.35kg banana by PLU/weight. Cash
--    payment, fully paid, walk-in — the §3J "no AR at all" path.
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_txn_hdrs (
    client_txn_uuid, session_id, terminal_id, receipt_number, status,
    subtotal, tax_total, grand_total, occurred_at, synced_at
)
SELECT 'a1111111-0000-4000-8000-000000000001', s.id, t.id, 'POS01-000001', 'completed',
       47500, 0, 47500, TIMESTAMPTZ '2026-09-03 09:20:00+07', TIMESTAMPTZ '2026-09-03 09:20:05+07'
FROM "POS".pos_sessions s JOIN "POS".pos_terminals t ON t.id = s.terminal_id AND t.code = 'POS01' AND s.status = 'open';

INSERT INTO "POS".pos_txn_lines (txn_id, line_no, product_id, description, uom_code, qty, unit_price, line_total, inventory_posted)
SELECT h.id, v.line_no, p.id, p.name, v.uom_code, v.qty, v.unit_price, v.line_total, TRUE
FROM "POS".pos_txn_hdrs h
JOIN (VALUES
    (1, 'POS-AQUA600', 'CTN', 1, 22000, 22000),
    (2, 'POS-SNACK-CHIPS', 'EA', 1, 12500, 12500),
    (3, 'POS-BANANA', 'KG', 0.35, 37142.86, 13000)
) AS v (line_no, sku, uom_code, qty, unit_price, line_total) ON TRUE
JOIN "INVENTORY".products p ON p.sku = v.sku
WHERE h.receipt_number = 'POS01-000001';

INSERT INTO "POS".pos_payments (txn_id, method, amount, change_given)
SELECT h.id, 'cash', 50000, 2500 FROM "POS".pos_txn_hdrs h WHERE h.receipt_number = 'POS01-000001';

-- ---------------------------------------------------------------------------
-- 8. Transaction 2 — POS01, the SAME idempotency story §3S describes: created
--    offline at 09:40 (occurred_at), synced 40 minutes later once the
--    terminal reconnected (synced_at) — created_offline = TRUE flags it.
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_txn_hdrs (
    client_txn_uuid, session_id, terminal_id, receipt_number, status,
    subtotal, tax_total, grand_total, created_offline, occurred_at, synced_at
)
SELECT 'a1111111-0000-4000-8000-000000000002', s.id, t.id, 'POS01-000002', 'completed',
       22000, 0, 22000, TRUE, TIMESTAMPTZ '2026-09-03 09:40:00+07', TIMESTAMPTZ '2026-09-03 10:20:00+07'
FROM "POS".pos_sessions s JOIN "POS".pos_terminals t ON t.id = s.terminal_id AND t.code = 'POS01' AND s.status = 'open';

INSERT INTO "POS".pos_txn_lines (txn_id, line_no, product_id, description, uom_code, qty, unit_price, line_total, inventory_posted)
SELECT h.id, 1, p.id, p.name, 'CTN', 1, 22000, 22000, TRUE
FROM "POS".pos_txn_hdrs h JOIN "INVENTORY".products p ON p.sku = 'POS-AQUA600'
WHERE h.receipt_number = 'POS01-000002';

INSERT INTO "POS".pos_payments (txn_id, method, amount)
SELECT h.id, 'qris', 22000 FROM "POS".pos_txn_hdrs h WHERE h.receipt_number = 'POS01-000002';

-- ---------------------------------------------------------------------------
-- 9. Transaction 3 — POS02 dine-in, Table 1: Nasi Goreng (Spicy modifier) +
--    Es Teh. Split payment (cash + card), walk-in — demonstrates §3M/§3N/§3O
--    together. A fresh session is opened on POS02 first since step 6 already
--    closed its earlier one.
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_sessions (terminal_id, cashier_user_id, opened_at, opening_cash, status)
SELECT t.id, u.id, TIMESTAMPTZ '2026-09-03 10:00:00+07', 300000, 'open'
FROM "POS".pos_terminals t, users u
WHERE t.code = 'POS02' AND u.email = 'staff@nusaevo.com';

INSERT INTO "POS".pos_txn_hdrs (
    client_txn_uuid, session_id, terminal_id, receipt_number, table_id, dining_mode, status,
    subtotal, tax_total, grand_total, occurred_at, synced_at
)
SELECT 'a1111111-0000-4000-8000-000000000003', s.id, t.id, 'POS02-000001', tb.id, 'dine_in', 'completed',
       46000, 4600, 50600, TIMESTAMPTZ '2026-09-03 12:15:00+07', TIMESTAMPTZ '2026-09-03 12:15:03+07'
FROM "POS".pos_sessions s
JOIN "POS".pos_terminals t ON t.id = s.terminal_id AND t.code = 'POS02' AND s.status = 'open'
JOIN "POS".pos_tables tb ON tb.code = 'T1';

INSERT INTO "POS".pos_txn_lines (
    txn_id, line_no, product_id, description, uom_code, qty, unit_price, tax_amount, line_total,
    kds_station_id, course, kds_status, inventory_posted
)
SELECT h.id, 1, p.id, p.name, 'EA', 1, 38000, 3800, 41800, k.id, 'main', 'served', TRUE
FROM "POS".pos_txn_hdrs h
JOIN "INVENTORY".products p ON p.sku = 'POS-NASGOR'
JOIN "POS".pos_kds_stations k ON k.code = 'KITCHEN'
WHERE h.receipt_number = 'POS02-000001'
UNION ALL
SELECT h.id, 2, p.id, p.name, 'EA', 1, 8000, 800, 8800, NULL, 'main', NULL, TRUE
FROM "POS".pos_txn_hdrs h JOIN "INVENTORY".products p ON p.sku = 'POS-ESTEH'
WHERE h.receipt_number = 'POS02-000001';

INSERT INTO "POS".pos_txn_line_modifiers (txn_line_id, modifier_id, modifier_name, price_delta)
SELECT l.id, m.id, m.name, m.price_delta
FROM "POS".pos_txn_lines l
JOIN "POS".pos_txn_hdrs h ON h.id = l.txn_id AND h.receipt_number = 'POS02-000001' AND l.line_no = 1
JOIN "POS".pos_modifiers m ON m.name = 'Spicy';

INSERT INTO "POS".pos_kds_ticket_events (txn_line_id, status, occurred_at, user_id)
SELECT l.id, v.status, v.occurred_at, u.id
FROM "POS".pos_txn_lines l
JOIN "POS".pos_txn_hdrs h ON h.id = l.txn_id AND h.receipt_number = 'POS02-000001' AND l.line_no = 1
JOIN users u ON u.email = 'staff@nusaevo.com',
(VALUES
    ('new', TIMESTAMPTZ '2026-09-03 12:15:05+07'),
    ('preparing', TIMESTAMPTZ '2026-09-03 12:16:00+07'),
    ('ready', TIMESTAMPTZ '2026-09-03 12:24:00+07'),
    ('served', TIMESTAMPTZ '2026-09-03 12:25:30+07')
) AS v (status, occurred_at);

INSERT INTO "POS".pos_payments (txn_id, method, amount)
SELECT h.id, v.method, v.amount
FROM "POS".pos_txn_hdrs h,
(VALUES ('cash', 30000), ('card', 20600)) AS v (method, amount)
WHERE h.receipt_number = 'POS02-000001';

-- ---------------------------------------------------------------------------
-- 10. Transaction 4 — POS01, NAMED CUSTOMER, on_account partial payment: the
--     §3J "real receivable" path (is_on_account = TRUE). sales_order_subject_id
--     is left NULL here since this seed does not also run SALES_SPECS.sql —
--     in a live system SalesOrderService::createFromExternalRequest() would
--     populate it once the linked Sales Order exists.
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_txn_hdrs (
    client_txn_uuid, session_id, terminal_id, receipt_number, customer_id, status,
    subtotal, tax_total, grand_total, is_on_account, occurred_at, synced_at
)
SELECT 'a1111111-0000-4000-8000-000000000004', s.id, t.id, 'POS01-000003', c.id, 'completed',
       110000, 0, 110000, TRUE, TIMESTAMPTZ '2026-09-03 11:00:00+07', TIMESTAMPTZ '2026-09-03 11:00:04+07'
FROM "POS".pos_sessions s
JOIN "POS".pos_terminals t ON t.id = s.terminal_id AND t.code = 'POS01' AND s.status = 'open'
JOIN "CRM".partners c ON c.name = 'Budi Santoso';

INSERT INTO "POS".pos_txn_lines (txn_id, line_no, product_id, description, uom_code, qty, unit_price, line_total, inventory_posted)
SELECT h.id, 1, p.id, p.name, 'CTN', 5, 22000, 110000, TRUE
FROM "POS".pos_txn_hdrs h JOIN "INVENTORY".products p ON p.sku = 'POS-AQUA600'
WHERE h.receipt_number = 'POS01-000003';

-- Partial payment now, the remaining Rp 60,000 stays on account (the Sales
-- Order Accounting's InvoiceRequested would carry once posted, per §3J).
INSERT INTO "POS".pos_payments (txn_id, method, amount)
SELECT h.id, 'cash', 50000 FROM "POS".pos_txn_hdrs h WHERE h.receipt_number = 'POS01-000003'
UNION ALL
SELECT h.id, 'on_account', 60000 FROM "POS".pos_txn_hdrs h WHERE h.receipt_number = 'POS01-000003';

-- ---------------------------------------------------------------------------
-- 11. Loyalty accrual for Budi Santoso from Transaction 4 (Silver tier, 1
--     point per Rp 10,000 → 11 points), plus a supervisor-approved discount
--     override on Transaction 1 (illustrative — the discount itself isn't
--     modeled as its own column in this seed, only the audit row §3T
--     requires).
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_loyalty_accounts (customer_id, tier_id, points_balance)
SELECT c.id, tr.id, 0 FROM "CRM".partners c, "POS".pos_loyalty_tiers tr
WHERE c.name = 'Budi Santoso' AND tr.name = 'Silver';

INSERT INTO "POS".pos_loyalty_ledger (account_id, txn_id, type, points_delta, occurred_at)
SELECT a.id, h.id, 'earn', 11, h.occurred_at
FROM "POS".pos_loyalty_accounts a
JOIN "CRM".partners c ON c.id = a.customer_id AND c.name = 'Budi Santoso'
JOIN "POS".pos_txn_hdrs h ON h.receipt_number = 'POS01-000003';

UPDATE "POS".pos_loyalty_accounts a SET points_balance = points_balance + 11
FROM "CRM".partners c WHERE c.id = a.customer_id AND c.name = 'Budi Santoso';

INSERT INTO "POS".pos_override_logs (txn_id, action_type, requested_by, authorized_by, reason, occurred_at)
SELECT h.id, 'discount_above_threshold', staff.id, admin.id, 'Loyalty member courtesy discount', TIMESTAMPTZ '2026-09-03 09:20:02+07'
FROM "POS".pos_txn_hdrs h
JOIN users staff ON staff.email = 'staff@nusaevo.com'
JOIN users admin ON admin.email = 'admin@nusaevo.com'
WHERE h.receipt_number = 'POS01-000001';

-- ---------------------------------------------------------------------------
-- 12. A gift card in circulation, and a return against Transaction 1 (1 bag
--     of chips, restockable) refunded to store credit — the §3L "automatic
--     reversal" story: this seed only records the return/refund documents
--     themselves (the actual InventoryService::receive()/journal reversal
--     calls are application-code, not modeled as rows here).
-- ---------------------------------------------------------------------------

INSERT INTO "POS".pos_gift_cards (code, balance, status) VALUES ('GC-2026-0001', 100000, 'active');

INSERT INTO "POS".pos_return_hdrs (original_txn_id, session_id, reason_code, status, refund_method, approved_by)
SELECT h.id, h.session_id, 'customer_changed_mind', 'completed', 'store_credit', u.id
FROM "POS".pos_txn_hdrs h JOIN users u ON u.email = 'staff@nusaevo.com'
WHERE h.receipt_number = 'POS01-000001';

INSERT INTO "POS".pos_return_lines (return_id, original_txn_line_id, qty, unit_price, condition_note, restockable)
SELECT r.id, l.id, 1, 12500, 'Unopened', TRUE
FROM "POS".pos_return_hdrs r
JOIN "POS".pos_txn_hdrs h ON h.id = r.original_txn_id AND h.receipt_number = 'POS01-000001'
JOIN "POS".pos_txn_lines l ON l.txn_id = h.id AND l.line_no = 2;

INSERT INTO "POS".pos_store_credits (customer_id, balance, source_type, source_id)
SELECT c.id, 12500, 'pos.pos_return_hdrs', r.id
FROM "POS".pos_return_hdrs r, "CRM".partners c
WHERE c.name = 'Siti Rahayu' -- illustrative walk-in-turned-known-customer refund destination
LIMIT 1;

COMMIT;

-- =============================================================================
-- Sanity check counts
-- =============================================================================
SELECT 'pos_profiles' AS table_name, COUNT(*) FROM "POS".pos_profiles
UNION ALL SELECT 'pos_branches', COUNT(*) FROM "POS".pos_branches
UNION ALL SELECT 'pos_terminals', COUNT(*) FROM "POS".pos_terminals
UNION ALL SELECT 'pos_terminal_devices', COUNT(*) FROM "POS".pos_terminal_devices
UNION ALL SELECT 'pos_weighted_barcode_templates', COUNT(*) FROM "POS".pos_weighted_barcode_templates
UNION ALL SELECT 'pos_favorite_items', COUNT(*) FROM "POS".pos_favorite_items
UNION ALL SELECT 'pos_promotion_rules', COUNT(*) FROM "POS".pos_promotion_rules
UNION ALL SELECT 'pos_floors', COUNT(*) FROM "POS".pos_floors
UNION ALL SELECT 'pos_tables', COUNT(*) FROM "POS".pos_tables
UNION ALL SELECT 'pos_modifier_groups', COUNT(*) FROM "POS".pos_modifier_groups
UNION ALL SELECT 'pos_modifiers', COUNT(*) FROM "POS".pos_modifiers
UNION ALL SELECT 'pos_product_modifier_groups', COUNT(*) FROM "POS".pos_product_modifier_groups
UNION ALL SELECT 'pos_kds_stations', COUNT(*) FROM "POS".pos_kds_stations
UNION ALL SELECT 'pos_product_kds_routing', COUNT(*) FROM "POS".pos_product_kds_routing
UNION ALL SELECT 'pos_loyalty_tiers', COUNT(*) FROM "POS".pos_loyalty_tiers
UNION ALL SELECT 'pos_sessions', COUNT(*) FROM "POS".pos_sessions
UNION ALL SELECT 'pos_cash_movements', COUNT(*) FROM "POS".pos_cash_movements
UNION ALL SELECT 'pos_txn_hdrs', COUNT(*) FROM "POS".pos_txn_hdrs
UNION ALL SELECT 'pos_txn_lines', COUNT(*) FROM "POS".pos_txn_lines
UNION ALL SELECT 'pos_txn_line_modifiers', COUNT(*) FROM "POS".pos_txn_line_modifiers
UNION ALL SELECT 'pos_kds_ticket_events', COUNT(*) FROM "POS".pos_kds_ticket_events
UNION ALL SELECT 'pos_payments', COUNT(*) FROM "POS".pos_payments
UNION ALL SELECT 'pos_return_hdrs', COUNT(*) FROM "POS".pos_return_hdrs
UNION ALL SELECT 'pos_return_lines', COUNT(*) FROM "POS".pos_return_lines
UNION ALL SELECT 'pos_gift_cards', COUNT(*) FROM "POS".pos_gift_cards
UNION ALL SELECT 'pos_store_credits', COUNT(*) FROM "POS".pos_store_credits
UNION ALL SELECT 'pos_loyalty_accounts', COUNT(*) FROM "POS".pos_loyalty_accounts
UNION ALL SELECT 'pos_loyalty_ledger', COUNT(*) FROM "POS".pos_loyalty_ledger
UNION ALL SELECT 'pos_override_logs', COUNT(*) FROM "POS".pos_override_logs
ORDER BY 1;
