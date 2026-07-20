-- ============================================================================
-- INVENTORY MODULE — SCHEMA SCRIPT
-- Multi-tenant ERP — DB-per-tenant isolation (no tenant_id column, per
-- CLAUDE.md §4/§7). Run once per tenant database, alongside CRM/DMS/WNE/
-- SCHEDULE schemas already provisioned in that same database.
-- Target: PostgreSQL 16
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid()

CREATE SCHEMA IF NOT EXISTS "INVENTORY";
SET search_path TO "INVENTORY";

-- ============================================================================
-- 1. MASTER / LOOKUP TABLES
-- ============================================================================

CREATE TABLE product_categories (
    id                  BIGSERIAL PRIMARY KEY,
    parent_category_id  BIGINT REFERENCES product_categories(id),
    name                VARCHAR(150) NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMP NOT NULL DEFAULT now(),
    updated_at          TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE uoms (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(20) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT now(),
    updated_at  TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE uom_conversions (
    id           BIGSERIAL PRIMARY KEY,
    from_uom_id  BIGINT NOT NULL REFERENCES uoms(id),
    to_uom_id    BIGINT NOT NULL REFERENCES uoms(id),
    factor       NUMERIC(18,6) NOT NULL CHECK (factor > 0), -- 1 from_uom = factor * to_uom
    UNIQUE (from_uom_id, to_uom_id)
);

CREATE TABLE products (
    id                BIGSERIAL PRIMARY KEY,
    uuid              UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- reserved for future REST clients
    sku               VARCHAR(100) NOT NULL UNIQUE,
    name              VARCHAR(255) NOT NULL,
    description       TEXT,
    category_id       BIGINT REFERENCES product_categories(id),
    base_uom_id       BIGINT NOT NULL REFERENCES uoms(id),
    costing_method    VARCHAR(20) NOT NULL DEFAULT 'fifo'
                          CHECK (costing_method IN ('fifo','average')),
    tracking_mode     VARCHAR(20) NOT NULL DEFAULT 'none'
                          CHECK (tracking_mode IN ('none','batch','serial')),
    reorder_point     NUMERIC(18,4) NOT NULL DEFAULT 0,
    reorder_quantity  NUMERIC(18,4) NOT NULL DEFAULT 0,
    abc_class         VARCHAR(1) CHECK (abc_class IN ('A','B','C')), -- manual v1, informational only
    quality_status    VARCHAR(20), -- placeholder, unused until Quality Management (Future Version)
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMP NOT NULL DEFAULT now(),
    updated_at        TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE product_barcodes (
    id               BIGSERIAL PRIMARY KEY,
    product_id       BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    barcode          VARCHAR(100) NOT NULL UNIQUE,
    barcode_type     VARCHAR(20) NOT NULL DEFAULT 'primary'
                         CHECK (barcode_type IN ('primary','case_pack','alternate')),
    unit_multiplier  NUMERIC(18,4) NOT NULL DEFAULT 1 CHECK (unit_multiplier > 0),
    created_at       TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE warehouses (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(50) NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    address     TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP NOT NULL DEFAULT now(),
    updated_at  TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE locations (
    id                  BIGSERIAL PRIMARY KEY,
    warehouse_id        BIGINT NOT NULL REFERENCES warehouses(id),
    parent_location_id  BIGINT REFERENCES locations(id),
    code                VARCHAR(50) NOT NULL,
    name                VARCHAR(150),
    location_type       VARCHAR(20) NOT NULL DEFAULT 'bin'
                            CHECK (location_type IN ('zone','aisle','bin','staging','dock')),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMP NOT NULL DEFAULT now(),
    updated_at          TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (warehouse_id, code)
);

CREATE TABLE location_barcodes (
    id           BIGSERIAL PRIMARY KEY,
    location_id  BIGINT NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    barcode      VARCHAR(100) NOT NULL UNIQUE,
    created_at   TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE adjustment_reasons (
    id         BIGSERIAL PRIMARY KEY,
    code       VARCHAR(30) NOT NULL UNIQUE,
    label      VARCHAR(150) NOT NULL,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE
);

-- Operational: put-away rules (first-matching-rule wins by priority)
CREATE TABLE putaway_rules (
    id                   BIGSERIAL PRIMARY KEY,
    warehouse_id         BIGINT NOT NULL REFERENCES warehouses(id),
    category_id          BIGINT REFERENCES product_categories(id),
    product_id           BIGINT REFERENCES products(id),
    target_location_id   BIGINT NOT NULL REFERENCES locations(id),
    priority             INT NOT NULL DEFAULT 100,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    created_at           TIMESTAMP NOT NULL DEFAULT now(),
    CHECK (category_id IS NOT NULL OR product_id IS NOT NULL)
);

-- Operational: batch/lot tracking
CREATE TABLE stock_batches (
    id                  BIGSERIAL PRIMARY KEY,
    product_id          BIGINT NOT NULL REFERENCES products(id),
    batch_number        VARCHAR(100) NOT NULL,
    expiry_date         DATE,
    manufacture_date    DATE,
    supplier_reference  VARCHAR(150),
    created_at          TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (product_id, batch_number)
);

-- Operational: serial number tracking (one row per physical unit)
CREATE TABLE stock_serials (
    id                   BIGSERIAL PRIMARY KEY,
    product_id           BIGINT NOT NULL REFERENCES products(id),
    serial_number        VARCHAR(150) NOT NULL UNIQUE,
    status               VARCHAR(20) NOT NULL DEFAULT 'in_stock'
                             CHECK (status IN ('in_stock','reserved','issued')),
    current_location_id  BIGINT REFERENCES locations(id),
    created_at           TIMESTAMP NOT NULL DEFAULT now(),
    updated_at           TIMESTAMP NOT NULL DEFAULT now()
);

-- ============================================================================
-- 2. TRANSACTION DOCUMENTS (header + lines)
-- Note: vendor/customer partner references are SOFT references (plain BIGINT,
-- no FK constraint) into CRM.partners.id. Inventory must run standalone
-- without CRM installed, so a hard cross-schema FK would break that tenant —
-- same principle as WNE/CRM/Schedule's subject_type/subject_id pattern.
-- ============================================================================

CREATE TABLE goods_receipts (
    id                 BIGSERIAL PRIMARY KEY,
    receipt_number     VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id       BIGINT NOT NULL REFERENCES warehouses(id),
    receipt_date       DATE NOT NULL,
    vendor_partner_id  BIGINT, -- soft reference: CRM.partners.id, no FK constraint
    subject_type       VARCHAR(100), -- optional polymorphic link, e.g. 'purchasing.po_hdrs'
    subject_id         BIGINT,
    reference_number   VARCHAR(100),
    status             VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted')),
    posted_at          TIMESTAMP,
    created_by         BIGINT,
    created_at         TIMESTAMP NOT NULL DEFAULT now(),
    updated_at         TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE goods_receipt_lines (
    id                        BIGSERIAL PRIMARY KEY,
    goods_receipt_id          BIGINT NOT NULL REFERENCES goods_receipts(id) ON DELETE CASCADE,
    line_no                   INT NOT NULL,
    product_id                BIGINT NOT NULL REFERENCES products(id),
    batch_id                  BIGINT REFERENCES stock_batches(id),
    quantity                  NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    uom_id                    BIGINT NOT NULL REFERENCES uoms(id),
    unit_cost                 NUMERIC(18,4) NOT NULL CHECK (unit_cost >= 0),
    destination_location_id   BIGINT NOT NULL REFERENCES locations(id),
    created_at                TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (goods_receipt_id, line_no)
);

CREATE TABLE goods_issues (
    id                   BIGSERIAL PRIMARY KEY,
    issue_number         VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id         BIGINT NOT NULL REFERENCES warehouses(id),
    issue_date           DATE NOT NULL,
    customer_partner_id  BIGINT, -- soft reference: CRM.partners.id, no FK constraint
    subject_type         VARCHAR(100), -- optional polymorphic link, e.g. 'sales.order_hdrs'
    subject_id            BIGINT,
    reason_code          VARCHAR(30), -- for unlinked issues: consumption, sample, write_off_pending
    status               VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted')),
    posted_at            TIMESTAMP,
    created_by           BIGINT,
    created_at           TIMESTAMP NOT NULL DEFAULT now(),
    updated_at           TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE goods_issue_lines (
    id                   BIGSERIAL PRIMARY KEY,
    goods_issue_id       BIGINT NOT NULL REFERENCES goods_issues(id) ON DELETE CASCADE,
    line_no              INT NOT NULL,
    product_id           BIGINT NOT NULL REFERENCES products(id),
    batch_id             BIGINT REFERENCES stock_batches(id),
    quantity             NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    uom_id               BIGINT NOT NULL REFERENCES uoms(id),
    source_location_id   BIGINT NOT NULL REFERENCES locations(id),
    created_at           TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (goods_issue_id, line_no)
);

CREATE TABLE transfers (
    id                         BIGSERIAL PRIMARY KEY,
    transfer_number            VARCHAR(50) NOT NULL UNIQUE,
    source_warehouse_id        BIGINT NOT NULL REFERENCES warehouses(id),
    source_location_id         BIGINT NOT NULL REFERENCES locations(id),
    destination_warehouse_id   BIGINT NOT NULL REFERENCES warehouses(id),
    destination_location_id    BIGINT NOT NULL REFERENCES locations(id),
    transfer_date              DATE NOT NULL,
    status                     VARCHAR(20) NOT NULL DEFAULT 'draft'
                                   CHECK (status IN ('draft','in_transit','completed')),
    posted_at                  TIMESTAMP,
    created_by                 BIGINT,
    created_at                 TIMESTAMP NOT NULL DEFAULT now(),
    updated_at                 TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE transfer_lines (
    id            BIGSERIAL PRIMARY KEY,
    transfer_id   BIGINT NOT NULL REFERENCES transfers(id) ON DELETE CASCADE,
    line_no       INT NOT NULL,
    product_id    BIGINT NOT NULL REFERENCES products(id),
    batch_id      BIGINT REFERENCES stock_batches(id),
    quantity      NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    uom_id        BIGINT NOT NULL REFERENCES uoms(id),
    created_at    TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (transfer_id, line_no)
);

CREATE TABLE adjustments (
    id                  BIGSERIAL PRIMARY KEY,
    adjustment_number   VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id        BIGINT NOT NULL REFERENCES warehouses(id),
    location_id         BIGINT NOT NULL REFERENCES locations(id),
    adjustment_date     DATE NOT NULL,
    reason_id           BIGINT NOT NULL REFERENCES adjustment_reasons(id),
    reference_type      VARCHAR(50), -- e.g. 'cycle_count'
    reference_id        BIGINT,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted')),
    posted_at           TIMESTAMP,
    created_by          BIGINT,
    created_at          TIMESTAMP NOT NULL DEFAULT now(),
    updated_at           TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE adjustment_lines (
    id                 BIGSERIAL PRIMARY KEY,
    adjustment_id      BIGINT NOT NULL REFERENCES adjustments(id) ON DELETE CASCADE,
    line_no            INT NOT NULL,
    product_id         BIGINT NOT NULL REFERENCES products(id),
    batch_id           BIGINT REFERENCES stock_batches(id),
    system_quantity    NUMERIC(18,4) NOT NULL,
    counted_quantity   NUMERIC(18,4) NOT NULL,
    variance_quantity  NUMERIC(18,4) GENERATED ALWAYS AS (counted_quantity - system_quantity) STORED,
    unit_cost          NUMERIC(18,4) NOT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (adjustment_id, line_no)
);

-- ============================================================================
-- 3. LEDGER & VALUATION — the source of truth
-- stock_ledger is append-only / immutable at the application layer: no
-- UPDATE or DELETE permitted once a row exists. stock_balances is a
-- denormalized cache, always rebuildable by replaying stock_ledger.
-- ============================================================================

CREATE TABLE stock_ledger (
    id              BIGSERIAL PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES products(id),
    warehouse_id    BIGINT NOT NULL REFERENCES warehouses(id),
    location_id     BIGINT NOT NULL REFERENCES locations(id),
    batch_id        BIGINT REFERENCES stock_batches(id),
    serial_id       BIGINT REFERENCES stock_serials(id),
    movement_type   VARCHAR(20) NOT NULL
                        CHECK (movement_type IN ('receipt','issue','transfer_out','transfer_in','adjustment')),
    quantity        NUMERIC(18,4) NOT NULL, -- signed: positive = in, negative = out
    uom_id          BIGINT NOT NULL REFERENCES uoms(id),
    unit_cost       NUMERIC(18,4) NOT NULL,
    total_cost      NUMERIC(18,4) NOT NULL, -- quantity * unit_cost, may be negative
    reference_type  VARCHAR(50) NOT NULL, -- 'goods_receipt' | 'goods_issue' | 'transfer' | 'adjustment'
    reference_id    BIGINT NOT NULL,
    movement_date   TIMESTAMP NOT NULL DEFAULT now(),
    created_at      TIMESTAMP NOT NULL DEFAULT now()
);

CREATE INDEX ix_stock_ledger_product_wh ON stock_ledger (product_id, warehouse_id, movement_date);
CREATE INDEX ix_stock_ledger_reference ON stock_ledger (reference_type, reference_id);

CREATE TABLE stock_valuation_layers (
    id                  BIGSERIAL PRIMARY KEY,
    product_id          BIGINT NOT NULL REFERENCES products(id),
    warehouse_id        BIGINT NOT NULL REFERENCES warehouses(id),
    batch_id            BIGINT REFERENCES stock_batches(id),
    source_ledger_id    BIGINT NOT NULL REFERENCES stock_ledger(id),
    received_quantity   NUMERIC(18,4) NOT NULL CHECK (received_quantity > 0),
    remaining_quantity  NUMERIC(18,4) NOT NULL CHECK (remaining_quantity >= 0),
    unit_cost           NUMERIC(18,4) NOT NULL CHECK (unit_cost >= 0),
    ownership_type       VARCHAR(20) NOT NULL DEFAULT 'owned'
                             CHECK (ownership_type IN ('owned','consignment')), -- placeholder, Future Version
    owning_partner_id    BIGINT, -- soft reference: CRM.partners.id, used only when ownership_type = 'consignment'
    created_at           TIMESTAMP NOT NULL DEFAULT now()
);

CREATE INDEX ix_valuation_open_layers ON stock_valuation_layers (product_id, warehouse_id, created_at)
    WHERE remaining_quantity > 0;

CREATE TABLE stock_balances (
    id             BIGSERIAL PRIMARY KEY,
    product_id     BIGINT NOT NULL REFERENCES products(id),
    warehouse_id   BIGINT NOT NULL REFERENCES warehouses(id),
    location_id    BIGINT NOT NULL REFERENCES locations(id),
    batch_id       BIGINT REFERENCES stock_batches(id),
    qty_on_hand    NUMERIC(18,4) NOT NULL DEFAULT 0,
    avg_cost       NUMERIC(18,4) NOT NULL DEFAULT 0, -- used when product.costing_method = 'average'
    updated_at     TIMESTAMP NOT NULL DEFAULT now()
);

-- NULLs are distinct in a standard UNIQUE constraint, which would allow
-- duplicate rows for non-batch-tracked stock (batch_id IS NULL). Coalesce
-- to a sentinel via a unique index instead.
CREATE UNIQUE INDEX ux_stock_balances ON stock_balances (product_id, warehouse_id, location_id, COALESCE(batch_id, 0));

-- ============================================================================
-- 4. OPERATIONAL: reservations, picking, packing, shipping, cycle counting
-- ============================================================================

CREATE TABLE stock_reservations (
    id            BIGSERIAL PRIMARY KEY,
    product_id    BIGINT NOT NULL REFERENCES products(id),
    batch_id      BIGINT REFERENCES stock_batches(id),
    serial_id     BIGINT REFERENCES stock_serials(id),
    warehouse_id  BIGINT NOT NULL REFERENCES warehouses(id),
    location_id   BIGINT REFERENCES locations(id), -- nullable: unassigned pending pick
    quantity      NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    subject_type  VARCHAR(100), -- polymorphic link to the requesting order
    subject_id    BIGINT,
    status        VARCHAR(20) NOT NULL DEFAULT 'active'
                      CHECK (status IN ('active','fulfilled','released')),
    expires_at    TIMESTAMP,
    created_at    TIMESTAMP NOT NULL DEFAULT now(),
    updated_at    TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE pick_lists (
    id                BIGSERIAL PRIMARY KEY,
    pick_list_number  VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id      BIGINT NOT NULL REFERENCES warehouses(id),
    assigned_to       BIGINT,
    status            VARCHAR(20) NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft','picking','picked','cancelled')),
    created_at        TIMESTAMP NOT NULL DEFAULT now(),
    updated_at        TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE pick_list_lines (
    id                BIGSERIAL PRIMARY KEY,
    pick_list_id      BIGINT NOT NULL REFERENCES pick_lists(id) ON DELETE CASCADE,
    reservation_id    BIGINT REFERENCES stock_reservations(id),
    product_id        BIGINT NOT NULL REFERENCES products(id),
    batch_id          BIGINT REFERENCES stock_batches(id),
    serial_id         BIGINT REFERENCES stock_serials(id),
    location_id       BIGINT NOT NULL REFERENCES locations(id),
    quantity          NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    picked_quantity   NUMERIC(18,4) NOT NULL DEFAULT 0,
    status            VARCHAR(20) NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending','picked')),
    created_at        TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE shipments (
    id                   BIGSERIAL PRIMARY KEY,
    shipment_number      VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id         BIGINT NOT NULL REFERENCES warehouses(id),
    customer_partner_id  BIGINT, -- soft reference: CRM.partners.id
    carrier              VARCHAR(100),
    tracking_number      VARCHAR(150),
    ship_date            DATE,
    status               VARCHAR(20) NOT NULL DEFAULT 'pending'
                             CHECK (status IN ('pending','shipped','delivered')),
    goods_issue_id       BIGINT REFERENCES goods_issues(id), -- set once ship-confirm triggers the Issue
    created_at           TIMESTAMP NOT NULL DEFAULT now(),
    updated_at           TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE pack_lists (
    id                 BIGSERIAL PRIMARY KEY,
    pack_list_number   VARCHAR(50) NOT NULL UNIQUE,
    pick_list_id       BIGINT REFERENCES pick_lists(id),
    shipment_id        BIGINT REFERENCES shipments(id),
    package_code       VARCHAR(50),
    weight             NUMERIC(18,4),
    weight_uom         VARCHAR(10),
    length_cm          NUMERIC(18,2),
    width_cm           NUMERIC(18,2),
    height_cm          NUMERIC(18,2),
    status             VARCHAR(20) NOT NULL DEFAULT 'packed'
                           CHECK (status IN ('packed','shipped')),
    created_at         TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE cycle_counts (
    id                 BIGSERIAL PRIMARY KEY,
    count_number       VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id       BIGINT NOT NULL REFERENCES warehouses(id),
    location_id        BIGINT REFERENCES locations(id), -- nullable: category/ABC-driven count instead
    category_id        BIGINT REFERENCES product_categories(id),
    scheduled_date     DATE NOT NULL,
    assigned_to        BIGINT,
    status             VARCHAR(20) NOT NULL DEFAULT 'draft'
                           CHECK (status IN ('draft','in_progress','completed')),
    created_at         TIMESTAMP NOT NULL DEFAULT now(),
    updated_at         TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE cycle_count_lines (
    id                  BIGSERIAL PRIMARY KEY,
    cycle_count_id      BIGINT NOT NULL REFERENCES cycle_counts(id) ON DELETE CASCADE,
    product_id          BIGINT NOT NULL REFERENCES products(id),
    batch_id            BIGINT REFERENCES stock_batches(id),
    system_quantity     NUMERIC(18,4) NOT NULL,
    counted_quantity    NUMERIC(18,4),
    variance_quantity   NUMERIC(18,4) GENERATED ALWAYS AS (counted_quantity - system_quantity) STORED,
    counted_at          TIMESTAMP,
    created_at          TIMESTAMP NOT NULL DEFAULT now()
);

-- ============================================================================
-- 5. Helpful indexes for the Dashboard (3A) and common lookups
-- ============================================================================

CREATE INDEX ix_products_category ON products (category_id);
CREATE INDEX ix_products_active_reorder ON products (is_active, reorder_point);
CREATE INDEX ix_locations_warehouse ON locations (warehouse_id, parent_location_id);
CREATE INDEX ix_goods_receipts_status ON goods_receipts (status, warehouse_id);
CREATE INDEX ix_goods_issues_status ON goods_issues (status, warehouse_id);
CREATE INDEX ix_stock_reservations_active ON stock_reservations (product_id, warehouse_id) WHERE status = 'active';
CREATE INDEX ix_stock_batches_expiry ON stock_batches (expiry_date) WHERE expiry_date IS NOT NULL;

RESET search_path;
