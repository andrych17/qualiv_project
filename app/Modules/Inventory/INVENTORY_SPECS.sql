-- =============================================================================
-- Inventory module — schema + example seed data
-- Source spec: app/Modules/Inventory/INVENTORY_SPECS.md (see that file for full
-- rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "INVENTORY" —
--              every table lives inside one tenant's own database (CLAUDE.md
--              §4/§7A), no tenant_id column anywhere.
--
-- Scope: MVP/Core (§2 "ship first") + Operational (§2 "v1.1, immediate fast-
-- follow") are BOTH built below, since §4's storage list treats Operational
-- tables as real schema, just tagged *(Operational)* — unlike Advanced/
-- Optimization (§3S), which is explicitly "not built now." Three placeholder
-- columns the spec calls out as cheap to reserve now (§5 MVP scope boundary)
-- are included, nullable and unused: `stock_ledger.quality_status` (Quality
-- Management), `stock_valuation_layers.ownership_type`/`owning_partner_id`
-- (Consignment) — both Advanced-tier features layered on later without a
-- schema break.
--
-- Ledger-first integrity is the core design decision (§5): `stock_ledger` is
-- append-only and the ONLY source of truth for quantity; `stock_balances` is a
-- cache, rebuildable at any time by replaying the ledger. Given how central
-- this is, `stock_ledger` gets a real trigger blocking UPDATE/DELETE outright
-- — not just a "no update/delete at the app layer" comment, the convention
-- used for audit-log tables elsewhere in this platform.
--
-- Cross-schema FK, Core-to-Core (§5): `goods_receipts`/`goods_issues` FK
-- directly into "CRM".partners(id) for vendor/customer, same allowed direction
-- CRM/DMS/WNE document for each other. `INVENTORY.locations.type` is
-- deliberately plain VARCHAR with NO CHECK constraint — the spec explicitly
-- calls it "an extensible lookup, not hardcoded enum" (§3C), unlike every
-- other status/type column in this file.
--
-- This file is a reference/planning script, not a Laravel migration — port
-- each section below into `database/migrations/tenant/*.php` per CLAUDE.md §5
-- build order (Inventory comes after Schedule, before Accounting).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"AIINSIGHT"/"ACCOUNTING"/"CRM"/"DMS"/"HCM"/"CUSTOMFIELDS".
CREATE SCHEMA IF NOT EXISTS "INVENTORY";

-- -----------------------------------------------------------------------------
-- §3B / §4. Product master lookups
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "INVENTORY".product_categories (
    id                  BIGSERIAL PRIMARY KEY,
    parent_category_id  BIGINT REFERENCES "INVENTORY".product_categories (id),
    name                VARCHAR(150) NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (id <> parent_category_id)
);

CREATE TABLE IF NOT EXISTS "INVENTORY".uoms (
    id      BIGSERIAL PRIMARY KEY,
    code    VARCHAR(10) NOT NULL UNIQUE,                 -- EA, BOX, KG, ...
    name    VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS "INVENTORY".uom_conversions (
    id                  BIGSERIAL PRIMARY KEY,
    from_uom_id         BIGINT NOT NULL REFERENCES "INVENTORY".uoms (id),
    to_uom_id           BIGINT NOT NULL REFERENCES "INVENTORY".uoms (id),
    conversion_factor   NUMERIC(18, 6) NOT NULL CHECK (conversion_factor > 0), -- 1 from_uom = conversion_factor * to_uom
    UNIQUE (from_uom_id, to_uom_id),
    CHECK (from_uom_id <> to_uom_id)
);

CREATE TABLE IF NOT EXISTS "INVENTORY".products (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing (CLAUDE.md §7A)
    sku                 VARCHAR(50) NOT NULL UNIQUE,      -- unique tenant-wide (§3B rule)
    name                VARCHAR(255) NOT NULL,
    description         TEXT,
    category_id         BIGINT REFERENCES "INVENTORY".product_categories (id),
    base_uom_id         BIGINT NOT NULL REFERENCES "INVENTORY".uoms (id),
    costing_method      VARCHAR(10) NOT NULL DEFAULT 'fifo' CHECK (costing_method IN ('fifo', 'average')), -- tenant default, overridable per product
    reorder_point       NUMERIC(14, 4) NOT NULL DEFAULT 0,
    reorder_quantity    NUMERIC(14, 4) NOT NULL DEFAULT 0,
    tracking_mode       VARCHAR(10) NOT NULL DEFAULT 'none' CHECK (tracking_mode IN ('none', 'batch', 'serial')), -- present from day one, enforced once Operational ships (§3B)
    abc_class           CHAR(1) CHECK (abc_class IN ('A', 'B', 'C')), -- simple manual flag in v1, not computed (§3Q)
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_inventory_products_category ON "INVENTORY".products (category_id, is_active);

CREATE TABLE IF NOT EXISTS "INVENTORY".product_barcodes (
    id                  BIGSERIAL PRIMARY KEY,
    product_id          BIGINT NOT NULL REFERENCES "INVENTORY".products (id) ON DELETE CASCADE,
    barcode             VARCHAR(64) NOT NULL UNIQUE,      -- unique tenant-wide
    type                VARCHAR(10) NOT NULL DEFAULT 'primary' CHECK (type IN ('primary', 'case_pack', 'alternate')),
    unit_multiplier     NUMERIC(14, 4) NOT NULL DEFAULT 1 -- e.g. a case-pack barcode scans as x24 of the base UoM
);

-- -----------------------------------------------------------------------------
-- §3C / §4. Warehouse & location master
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "INVENTORY".warehouses (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    address     TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "INVENTORY".locations (
    id                  BIGSERIAL PRIMARY KEY,
    warehouse_id        BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    parent_location_id  BIGINT REFERENCES "INVENTORY".locations (id), -- zone -> aisle -> bin hierarchy (§3C)
    code                VARCHAR(50) NOT NULL,
    -- Deliberately plain VARCHAR, no CHECK — §3C calls this "an extensible
    -- lookup, not hardcoded enum" (zone/bin/staging/dock are examples, not an
    -- exhaustive list), unlike every other status/type column in this file.
    type                VARCHAR(20) NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (warehouse_id, code),
    CHECK (id <> parent_location_id)
);

CREATE INDEX IF NOT EXISTS idx_inventory_locations_warehouse ON "INVENTORY".locations (warehouse_id, parent_location_id);

CREATE TABLE IF NOT EXISTS "INVENTORY".location_barcodes (
    id              BIGSERIAL PRIMARY KEY,
    location_id     BIGINT NOT NULL REFERENCES "INVENTORY".locations (id) ON DELETE CASCADE,
    barcode         VARCHAR(64) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS "INVENTORY".adjustment_reasons (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,              -- tenant-editable lookup (§3G)
    name        VARCHAR(100) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

-- §3R / §4. Put-away rules (Operational)
CREATE TABLE IF NOT EXISTS "INVENTORY".putaway_rules (
    id                      BIGSERIAL PRIMARY KEY,
    warehouse_id            BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    product_id              BIGINT REFERENCES "INVENTORY".products (id), -- nullable = category-based rule
    product_category_id     BIGINT REFERENCES "INVENTORY".product_categories (id), -- nullable = product-specific rule
    target_location_id      BIGINT NOT NULL REFERENCES "INVENTORY".locations (id),
    priority                INTEGER NOT NULL DEFAULT 0,   -- first-matching-rule wins
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (product_id IS NOT NULL OR product_category_id IS NOT NULL)
);

-- -----------------------------------------------------------------------------
-- §3L / §3M / §4. Batch and serial masters (Operational) — created before
-- stock_ledger since the ledger optionally references a batch; stock_serials
-- is created AFTER stock_ledger instead (it references ledger rows).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "INVENTORY".stock_batches (
    id                  BIGSERIAL PRIMARY KEY,
    product_id          BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    batch_no            VARCHAR(50) NOT NULL,
    expiry_date         DATE,
    manufacture_date    DATE,
    supplier_reference  VARCHAR(100),
    UNIQUE (product_id, batch_no)
);

CREATE INDEX IF NOT EXISTS idx_inventory_stock_batches_expiry ON "INVENTORY".stock_batches (expiry_date) WHERE expiry_date IS NOT NULL;

COMMIT;

-- =============================================================================
-- §3D–§3J / §4. The ledger core — stock_ledger, stock_valuation_layers,
-- stock_balances — plus the documents that post through them.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "INVENTORY".stock_ledger (
    id              BIGSERIAL PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    warehouse_id    BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    location_id     BIGINT REFERENCES "INVENTORY".locations (id),
    batch_id        BIGINT REFERENCES "INVENTORY".stock_batches (id), -- app-enforced required when product.tracking_mode = 'batch'
    movement_type   VARCHAR(10) NOT NULL CHECK (movement_type IN ('receipt', 'issue', 'transfer', 'adjustment')),
    qty             NUMERIC(18, 4) NOT NULL,              -- signed: positive = stock in, negative = stock out
    unit_cost       NUMERIC(18, 4),                       -- cost per base-UoM unit at time of movement
    quality_status  VARCHAR(10) CHECK (quality_status IN ('pending', 'passed', 'failed')), -- placeholder for Quality Management (Advanced, §3S) — nullable, unused in MVP/Operational
    document_type   VARCHAR(40) NOT NULL,                 -- e.g. 'inventory.goods_receipts' — polymorphic, not FK-enforced (varies by type)
    document_id     BIGINT NOT NULL,
    posted_by       BIGINT REFERENCES users (id),
    posted_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (qty <> 0)
);

CREATE INDEX IF NOT EXISTS idx_inventory_stock_ledger_product ON "INVENTORY".stock_ledger (product_id, warehouse_id, posted_at);
CREATE INDEX IF NOT EXISTS idx_inventory_stock_ledger_document ON "INVENTORY".stock_ledger (document_type, document_id);

-- Ledger-first integrity is the core design decision of this module (§5):
-- enforce "append-only" for real, not just as an app-layer convention.
CREATE OR REPLACE FUNCTION "INVENTORY".prevent_stock_ledger_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'INVENTORY.stock_ledger is append-only — post a new adjustment entry, never update or delete a ledger row (INVENTORY_SPECS.md §3G/§5)';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_inventory_stock_ledger_immutable ON "INVENTORY".stock_ledger;
CREATE TRIGGER trg_inventory_stock_ledger_immutable
    BEFORE UPDATE OR DELETE ON "INVENTORY".stock_ledger
    FOR EACH ROW EXECUTE FUNCTION "INVENTORY".prevent_stock_ledger_mutation();

CREATE TABLE IF NOT EXISTS "INVENTORY".stock_valuation_layers (
    id                      BIGSERIAL PRIMARY KEY,
    product_id              BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    warehouse_id            BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    batch_id                BIGINT REFERENCES "INVENTORY".stock_batches (id),
    source_stock_ledger_id  BIGINT NOT NULL REFERENCES "INVENTORY".stock_ledger (id), -- the receipt event that created this layer
    received_qty            NUMERIC(18, 4) NOT NULL CHECK (received_qty > 0),
    remaining_qty           NUMERIC(18, 4) NOT NULL CHECK (remaining_qty >= 0),
    unit_cost               NUMERIC(18, 4) NOT NULL,
    ownership_type          VARCHAR(15) NOT NULL DEFAULT 'owned' CHECK (ownership_type IN ('owned', 'consignment')), -- placeholder for Consignment (Advanced, §3S)
    owning_partner_id       BIGINT,                       -- informational only, NOT a FK — unused until Consignment ships
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_inventory_stock_valuation_layers_open ON "INVENTORY".stock_valuation_layers (product_id, warehouse_id, created_at) WHERE remaining_qty > 0;

-- Denormalized current-quantity cache — always rebuildable from stock_ledger
-- alone (§3H); never treated as the source of truth.
CREATE TABLE IF NOT EXISTS "INVENTORY".stock_balances (
    id              BIGSERIAL PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    warehouse_id    BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    location_id     BIGINT REFERENCES "INVENTORY".locations (id),
    batch_id        BIGINT REFERENCES "INVENTORY".stock_batches (id),
    qty_on_hand     NUMERIC(18, 4) NOT NULL DEFAULT 0,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Same NULLS-NOT-DISTINCT caveat noted in the other *_SPECS.sql files:
    -- multiple rows with the same NULL location_id/batch_id would not violate
    -- this constraint. In practice the rebuild-from-ledger query (see the
    -- seed section) always GROUPs BY these columns, so it self-corrects.
    UNIQUE (product_id, warehouse_id, location_id, batch_id)
);

-- §3M / §4. Serial tracking (Operational) — created after stock_ledger since
-- each serial references the ledger rows that received/issued it, rather than
-- the ledger referencing serials (a receipt of qty N creates one ledger row
-- plus N serial rows, not the reverse).
CREATE TABLE IF NOT EXISTS "INVENTORY".stock_serials (
    id                          BIGSERIAL PRIMARY KEY,
    product_id                  BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    serial_no                   VARCHAR(100) NOT NULL UNIQUE, -- unique tenant-wide
    status                      VARCHAR(10) NOT NULL DEFAULT 'in_stock' CHECK (status IN ('in_stock', 'reserved', 'issued')),
    current_location_id         BIGINT REFERENCES "INVENTORY".locations (id),
    received_stock_ledger_id    BIGINT NOT NULL REFERENCES "INVENTORY".stock_ledger (id),
    issued_stock_ledger_id      BIGINT REFERENCES "INVENTORY".stock_ledger (id) -- nullable until issued
);

-- -----------------------------------------------------------------------------
-- §3D / §4. Goods Receipt
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "INVENTORY".goods_receipts (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    warehouse_id    BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    receipt_date    DATE NOT NULL,
    subject_type    VARCHAR(100),                         -- optional polymorphic link, e.g. 'purchase.pur_receipt_hdrs' (§3D)
    subject_id      VARCHAR(100),
    partner_id      BIGINT REFERENCES "CRM".partners (id), -- vendor; NULL = "opening balance" (§3D)
    reference_no    VARCHAR(50),
    status          VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted')), -- posting is the only action that touches the ledger (§3D rule)
    posted_at       TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "INVENTORY".goods_receipt_lines (
    id                      BIGSERIAL PRIMARY KEY,
    receipt_id              BIGINT NOT NULL REFERENCES "INVENTORY".goods_receipts (id) ON DELETE CASCADE,
    product_id              BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    batch_id                BIGINT REFERENCES "INVENTORY".stock_batches (id),
    qty                     NUMERIC(18, 4) NOT NULL CHECK (qty > 0),
    uom_id                  BIGINT NOT NULL REFERENCES "INVENTORY".uoms (id),
    unit_cost               NUMERIC(18, 4) NOT NULL,
    destination_location_id BIGINT REFERENCES "INVENTORY".locations (id), -- defaults from put-away rules (3R); manual if unset
    stock_ledger_id         BIGINT REFERENCES "INVENTORY".stock_ledger (id) -- set on posting
);

-- -----------------------------------------------------------------------------
-- §3E / §4. Goods Issue
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "INVENTORY".goods_issues (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    warehouse_id    BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    issue_date      DATE NOT NULL,
    subject_type    VARCHAR(100),                         -- optional polymorphic link, e.g. 'sales.dlv_hdrs' (§3E)
    subject_id      VARCHAR(100),
    partner_id      BIGINT REFERENCES "CRM".partners (id), -- customer; NULL = internal consumption
    reason          VARCHAR(30),                           -- unlinked-issue reason (consumption/sample/write-off-pending); free text, not a lookup table per §4
    status          VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted')),
    posted_at       TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "INVENTORY".goods_issue_lines (
    id                  BIGSERIAL PRIMARY KEY,
    issue_id            BIGINT NOT NULL REFERENCES "INVENTORY".goods_issues (id) ON DELETE CASCADE,
    product_id          BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    batch_id            BIGINT REFERENCES "INVENTORY".stock_batches (id),
    qty                 NUMERIC(18, 4) NOT NULL CHECK (qty > 0),
    uom_id              BIGINT NOT NULL REFERENCES "INVENTORY".uoms (id),
    source_location_id  BIGINT REFERENCES "INVENTORY".locations (id),
    stock_ledger_id     BIGINT REFERENCES "INVENTORY".stock_ledger (id)
);

-- -----------------------------------------------------------------------------
-- §3F / §4. Transfers — one paired issue-at-source + receipt-at-destination
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "INVENTORY".transfers (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    source_warehouse_id         BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    source_location_id          BIGINT REFERENCES "INVENTORY".locations (id),
    destination_warehouse_id    BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    destination_location_id     BIGINT REFERENCES "INVENTORY".locations (id),
    transfer_date                DATE NOT NULL,
    status                          VARCHAR(12) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'in_transit', 'completed')),
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "INVENTORY".transfer_lines (
    id                      BIGSERIAL PRIMARY KEY,
    transfer_id             BIGINT NOT NULL REFERENCES "INVENTORY".transfers (id) ON DELETE CASCADE,
    product_id              BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    batch_id                BIGINT REFERENCES "INVENTORY".stock_batches (id),
    qty                     NUMERIC(18, 4) NOT NULL CHECK (qty > 0),
    uom_id                  BIGINT NOT NULL REFERENCES "INVENTORY".uoms (id),
    source_ledger_id        BIGINT REFERENCES "INVENTORY".stock_ledger (id), -- the negative leg
    destination_ledger_id   BIGINT REFERENCES "INVENTORY".stock_ledger (id)  -- the positive leg
);

-- -----------------------------------------------------------------------------
-- §3G / §4. Adjustments
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "INVENTORY".adjustments (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    warehouse_id        BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    location_id         BIGINT REFERENCES "INVENTORY".locations (id),
    adjustment_date     DATE NOT NULL,
    reason_id           BIGINT NOT NULL REFERENCES "INVENTORY".adjustment_reasons (id),
    reference           VARCHAR(100),                     -- e.g. a linked Cycle Count (§3Q)
    status               VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted')),
    posted_at               TIMESTAMPTZ,
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "INVENTORY".adjustment_lines (
    id              BIGSERIAL PRIMARY KEY,
    adjustment_id   BIGINT NOT NULL REFERENCES "INVENTORY".adjustments (id) ON DELETE CASCADE,
    product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    batch_id        BIGINT REFERENCES "INVENTORY".stock_batches (id),
    system_qty      NUMERIC(18, 4) NOT NULL,              -- auto-filled from current balance
    counted_qty     NUMERIC(18, 4) NOT NULL,
    variance_qty    NUMERIC(18, 4) GENERATED ALWAYS AS (counted_qty - system_qty) STORED,
    unit_cost       NUMERIC(18, 4),                       -- cost basis for the variance
    stock_ledger_id BIGINT REFERENCES "INVENTORY".stock_ledger (id)
);

COMMIT;

-- =============================================================================
-- §3N–§3Q / §4. Operational — reservations, picking, packing/shipping, cycle
-- counting.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS "INVENTORY".stock_reservations (
    id              BIGSERIAL PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    batch_id        BIGINT REFERENCES "INVENTORY".stock_batches (id),
    serial_id       BIGINT REFERENCES "INVENTORY".stock_serials (id),
    warehouse_id    BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    location_id     BIGINT REFERENCES "INVENTORY".locations (id), -- NULL = unassigned-pending-pick (§3N)
    qty             NUMERIC(18, 4) NOT NULL CHECK (qty > 0),
    subject_type    VARCHAR(100),                         -- polymorphic link to the order that requested it
    subject_id      VARCHAR(100),
    status          VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'fulfilled', 'released')),
    expires_at      TIMESTAMPTZ,                          -- auto-release if not fulfilled by a configurable window
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_inventory_stock_reservations_product ON "INVENTORY".stock_reservations (product_id, warehouse_id, status);

CREATE TABLE IF NOT EXISTS "INVENTORY".pick_lists (
    id              BIGSERIAL PRIMARY KEY,
    warehouse_id    BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    assigned_to     BIGINT REFERENCES users (id),
    status          VARCHAR(15) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'picking', 'picked', 'packing_ready')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "INVENTORY".pick_list_lines (
    id              BIGSERIAL PRIMARY KEY,
    pick_list_id    BIGINT NOT NULL REFERENCES "INVENTORY".pick_lists (id) ON DELETE CASCADE,
    reservation_id  BIGINT NOT NULL REFERENCES "INVENTORY".stock_reservations (id),
    sequence_no     INTEGER NOT NULL DEFAULT 0,            -- simple location sort in v1, not true path optimization (§3O)
    status          VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'picked')),
    picked_qty      NUMERIC(18, 4),
    picked_at       TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS "INVENTORY".pack_lists (
    id              BIGSERIAL PRIMARY KEY,
    pick_list_id    BIGINT NOT NULL REFERENCES "INVENTORY".pick_lists (id),
    package_no      VARCHAR(50) NOT NULL,
    weight_kg       NUMERIC(10, 3),
    length_cm       NUMERIC(8, 2),
    width_cm        NUMERIC(8, 2),
    height_cm       NUMERIC(8, 2),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "INVENTORY".shipments (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    carrier         VARCHAR(100),
    tracking_no     VARCHAR(100),
    ship_date       DATE,
    status          VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'shipped', 'delivered')), -- delivered is manual/webhook-updated in v1 (§3P)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- A shipment headers one or more packages (§3P) — added via ALTER since
-- shipments must exist first, same "extend after the fact" technique used for
-- documents/document_versions in DMS_SPECS.sql.
ALTER TABLE "INVENTORY".pack_lists
    ADD COLUMN IF NOT EXISTS shipment_id BIGINT REFERENCES "INVENTORY".shipments (id);

CREATE TABLE IF NOT EXISTS "INVENTORY".cycle_counts (
    id                      BIGSERIAL PRIMARY KEY,
    warehouse_id            BIGINT NOT NULL REFERENCES "INVENTORY".warehouses (id),
    location_id             BIGINT REFERENCES "INVENTORY".locations (id), -- NULL = category/ABC-based count instead of location-based
    product_category_id     BIGINT REFERENCES "INVENTORY".product_categories (id),
    abc_class               CHAR(1) CHECK (abc_class IN ('A', 'B', 'C')),
    assigned_to             BIGINT REFERENCES users (id),
    scheduled_date          DATE NOT NULL,
    status                  VARCHAR(12) NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'in_progress', 'completed')),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "INVENTORY".cycle_count_lines (
    id                  BIGSERIAL PRIMARY KEY,
    cycle_count_id      BIGINT NOT NULL REFERENCES "INVENTORY".cycle_counts (id) ON DELETE CASCADE,
    product_id          BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    batch_id            BIGINT REFERENCES "INVENTORY".stock_batches (id),
    system_qty          NUMERIC(18, 4) NOT NULL,
    counted_qty         NUMERIC(18, 4),                   -- NULL until actually counted
    variance_qty        NUMERIC(18, 4) GENERATED ALWAYS AS (counted_qty - system_qty) STORED,
    adjustment_id        BIGINT REFERENCES "INVENTORY".adjustments (id) -- linked once routed to Adjustment for review/posting (§3Q rule: counting never silently changes stock)
);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Two products (a plain FIFO-costed office supply and a serial-tracked
-- laptop), one warehouse with a two-bin location tree, a full receipt ->
-- issue -> transfer -> adjustment cycle for the first product, a serialized
-- receipt/issue for the second, and a final stock_balances REBUILD FROM THE
-- LEDGER — demonstrating the exact "always reconstructable from stock_ledger
-- alone" property §3H calls out, rather than hand-maintaining the cache.
--
-- Partner references reuse the same client@example-corp.com /
-- vendor@office-rentals.example rows CRM_SPECS.sql / ACCOUNTING_SPECS.sql
-- already seed, so run CRM_SPECS.sql first if starting from a bare tenant DB.
--
-- Header-creating and dependent-update statements are kept as SEPARATE
-- top-level statements throughout (see DMS_SPECS.sql's header note) to avoid
-- PostgreSQL's same-statement, same-snapshot limitation on data-modifying
-- CTEs. *_lines.stock_ledger_id / transfer_lines' ledger-leg columns are left
-- NULL in this seed for the same reason — populating them is a "set on
-- posting" convenience the real application does, not required for the
-- ledger/valuation math itself to be correct or consistent.
-- =============================================================================

BEGIN;

INSERT INTO "INVENTORY".product_categories (name) VALUES ('Office Supplies'), ('IT Equipment');
INSERT INTO "INVENTORY".uoms (code, name) VALUES ('EA', 'Each'), ('BOX', 'Box');
INSERT INTO "INVENTORY".uom_conversions (from_uom_id, to_uom_id, conversion_factor)
SELECT b.id, e.id, 24 FROM "INVENTORY".uoms b, "INVENTORY".uoms e WHERE b.code = 'BOX' AND e.code = 'EA';

INSERT INTO "INVENTORY".products (sku, name, category_id, base_uom_id, costing_method, reorder_point, tracking_mode)
SELECT 'SKU-1001', 'Kertas A4', c.id, u.id, 'fifo', 10, 'none'
FROM "INVENTORY".product_categories c, "INVENTORY".uoms u
WHERE c.name = 'Office Supplies' AND u.code = 'BOX'
UNION ALL
SELECT 'SKU-2001', 'Laptop Dell Latitude', c.id, u.id, 'fifo', 2, 'serial'
FROM "INVENTORY".product_categories c, "INVENTORY".uoms u
WHERE c.name = 'IT Equipment' AND u.code = 'EA';

INSERT INTO "INVENTORY".product_barcodes (product_id, barcode, type)
SELECT id, '8991000100158', 'primary' FROM "INVENTORY".products WHERE sku = 'SKU-1001'
UNION ALL
SELECT id, '8991000200159', 'primary' FROM "INVENTORY".products WHERE sku = 'SKU-2001';

INSERT INTO "INVENTORY".warehouses (name, address) VALUES ('Gudang Pusat', 'Jl. Industri No. 5, Jakarta');

WITH zone_a AS (
    INSERT INTO "INVENTORY".locations (warehouse_id, code, type)
    SELECT id, 'ZONE-A', 'zone' FROM "INVENTORY".warehouses WHERE name = 'Gudang Pusat'
    RETURNING id
)
INSERT INTO "INVENTORY".locations (warehouse_id, parent_location_id, code, type)
SELECT w.id, zone_a.id, v.code, 'bin'
FROM zone_a, "INVENTORY".warehouses w, (VALUES ('A-01'), ('A-02')) AS v(code)
WHERE w.name = 'Gudang Pusat';

INSERT INTO "INVENTORY".location_barcodes (location_id, barcode)
SELECT id, 'LOC-A-01' FROM "INVENTORY".locations WHERE code = 'A-01';

INSERT INTO "INVENTORY".adjustment_reasons (code, name) VALUES
    ('count_variance', 'Count Variance'), ('damage', 'Damage'), ('expiry', 'Expiry'),
    ('theft_loss', 'Theft / Loss'), ('correction', 'Correction'), ('other', 'Other');

-- --- Receipt 1: Kertas A4 opening balance, 50 BOX @ 45,000 -------------------

INSERT INTO "INVENTORY".goods_receipts (warehouse_id, receipt_date, reference_no, status, posted_at)
SELECT id, '2026-08-01', 'GR-0001', 'posted', now() FROM "INVENTORY".warehouses WHERE name = 'Gudang Pusat';

INSERT INTO "INVENTORY".goods_receipt_lines (receipt_id, product_id, qty, uom_id, unit_cost, destination_location_id)
SELECT gr.id, p.id, 50, u.id, 45000, l.id
FROM "INVENTORY".goods_receipts gr, "INVENTORY".products p, "INVENTORY".uoms u, "INVENTORY".locations l
WHERE gr.reference_no = 'GR-0001' AND p.sku = 'SKU-1001' AND u.code = 'BOX' AND l.code = 'A-01';

WITH ledger AS (
    INSERT INTO "INVENTORY".stock_ledger (product_id, warehouse_id, location_id, movement_type, qty, unit_cost, document_type, document_id)
    SELECT p.id, w.id, l.id, 'receipt', 50, 45000, 'inventory.goods_receipts', gr.id
    FROM "INVENTORY".goods_receipts gr, "INVENTORY".products p, "INVENTORY".warehouses w, "INVENTORY".locations l
    WHERE gr.reference_no = 'GR-0001' AND p.sku = 'SKU-1001' AND w.name = 'Gudang Pusat' AND l.code = 'A-01'
    RETURNING id, product_id, warehouse_id
)
INSERT INTO "INVENTORY".stock_valuation_layers (product_id, warehouse_id, source_stock_ledger_id, received_qty, remaining_qty, unit_cost)
SELECT product_id, warehouse_id, id, 50, 50, 45000 FROM ledger;

-- --- Issue 1: 10 BOX Kertas A4, internal consumption -------------------------

INSERT INTO "INVENTORY".goods_issues (warehouse_id, issue_date, reason, status, posted_at)
SELECT id, '2026-08-05', 'consumption', 'posted', now() FROM "INVENTORY".warehouses WHERE name = 'Gudang Pusat';

INSERT INTO "INVENTORY".goods_issue_lines (issue_id, product_id, qty, uom_id, source_location_id)
SELECT gi.id, p.id, 10, u.id, l.id
FROM "INVENTORY".goods_issues gi, "INVENTORY".products p, "INVENTORY".uoms u, "INVENTORY".locations l
WHERE gi.reason = 'consumption' AND p.sku = 'SKU-1001' AND u.code = 'BOX' AND l.code = 'A-01';

INSERT INTO "INVENTORY".stock_ledger (product_id, warehouse_id, location_id, movement_type, qty, unit_cost, document_type, document_id)
SELECT p.id, w.id, l.id, 'issue', -10, 45000, 'inventory.goods_issues', gi.id
FROM "INVENTORY".goods_issues gi, "INVENTORY".products p, "INVENTORY".warehouses w, "INVENTORY".locations l
WHERE gi.reason = 'consumption' AND p.sku = 'SKU-1001' AND w.name = 'Gudang Pusat' AND l.code = 'A-01';

-- FIFO consumption: only one open layer exists for this product, so this is a
-- single-layer decrement — a multi-layer issue would loop over layers oldest-
-- first until the full quantity is consumed.
UPDATE "INVENTORY".stock_valuation_layers svl
SET remaining_qty = remaining_qty - 10
FROM "INVENTORY".products p
WHERE svl.product_id = p.id AND p.sku = 'SKU-1001';

-- --- Transfer: 5 BOX Kertas A4 from A-01 to A-02 -----------------------------

INSERT INTO "INVENTORY".transfers (source_warehouse_id, source_location_id, destination_warehouse_id, destination_location_id, transfer_date, status)
SELECT w.id, src.id, w.id, dst.id, '2026-08-08', 'completed'
FROM "INVENTORY".warehouses w, "INVENTORY".locations src, "INVENTORY".locations dst
WHERE w.name = 'Gudang Pusat' AND src.code = 'A-01' AND dst.code = 'A-02';

INSERT INTO "INVENTORY".transfer_lines (transfer_id, product_id, qty, uom_id)
SELECT t.id, p.id, 5, u.id
FROM "INVENTORY".transfers t, "INVENTORY".products p, "INVENTORY".uoms u
WHERE p.sku = 'SKU-1001' AND u.code = 'BOX'
ORDER BY t.id DESC LIMIT 1;

INSERT INTO "INVENTORY".stock_ledger (product_id, warehouse_id, location_id, movement_type, qty, unit_cost, document_type, document_id)
SELECT p.id, w.id, src.id, 'transfer', -5, 45000, 'inventory.transfers', t.id
FROM "INVENTORY".transfers t, "INVENTORY".products p, "INVENTORY".warehouses w, "INVENTORY".locations src
WHERE p.sku = 'SKU-1001' AND w.name = 'Gudang Pusat' AND src.code = 'A-01'
ORDER BY t.id DESC LIMIT 1;

INSERT INTO "INVENTORY".stock_ledger (product_id, warehouse_id, location_id, movement_type, qty, unit_cost, document_type, document_id)
SELECT p.id, w.id, dst.id, 'transfer', 5, 45000, 'inventory.transfers', t.id
FROM "INVENTORY".transfers t, "INVENTORY".products p, "INVENTORY".warehouses w, "INVENTORY".locations dst
WHERE p.sku = 'SKU-1001' AND w.name = 'Gudang Pusat' AND dst.code = 'A-02'
ORDER BY t.id DESC LIMIT 1;

-- --- Adjustment: -1 BOX Kertas A4 at A-02, damage ----------------------------

INSERT INTO "INVENTORY".adjustments (warehouse_id, location_id, adjustment_date, reason_id, status, posted_at)
SELECT w.id, l.id, '2026-08-10', r.id, 'posted', now()
FROM "INVENTORY".warehouses w, "INVENTORY".locations l, "INVENTORY".adjustment_reasons r
WHERE w.name = 'Gudang Pusat' AND l.code = 'A-02' AND r.code = 'damage';

INSERT INTO "INVENTORY".adjustment_lines (adjustment_id, product_id, system_qty, counted_qty, unit_cost)
SELECT a.id, p.id, 5, 4, 45000
FROM "INVENTORY".adjustments a, "INVENTORY".products p
WHERE p.sku = 'SKU-1001'
ORDER BY a.id DESC LIMIT 1;

INSERT INTO "INVENTORY".stock_ledger (product_id, warehouse_id, location_id, movement_type, qty, unit_cost, document_type, document_id)
SELECT p.id, w.id, l.id, 'adjustment', -1, 45000, 'inventory.adjustments', a.id
FROM "INVENTORY".adjustments a, "INVENTORY".products p, "INVENTORY".warehouses w, "INVENTORY".locations l
WHERE p.sku = 'SKU-1001' AND w.name = 'Gudang Pusat' AND l.code = 'A-02'
ORDER BY a.id DESC LIMIT 1;

UPDATE "INVENTORY".stock_valuation_layers svl
SET remaining_qty = remaining_qty - 1
FROM "INVENTORY".products p
WHERE svl.product_id = p.id AND p.sku = 'SKU-1001';

-- --- Receipt 2: 5 laptops (serial-tracked), from the vendor partner ----------

INSERT INTO "INVENTORY".goods_receipts (warehouse_id, receipt_date, reference_no, partner_id, status, posted_at)
SELECT w.id, '2026-08-12', 'GR-0002', partner.id, 'posted', now()
FROM "INVENTORY".warehouses w, "CRM".partners partner
WHERE w.name = 'Gudang Pusat' AND partner.email = 'vendor@office-rentals.example';

INSERT INTO "INVENTORY".goods_receipt_lines (receipt_id, product_id, qty, uom_id, unit_cost, destination_location_id)
SELECT gr.id, p.id, 5, u.id, 12000000, l.id
FROM "INVENTORY".goods_receipts gr, "INVENTORY".products p, "INVENTORY".uoms u, "INVENTORY".locations l
WHERE gr.reference_no = 'GR-0002' AND p.sku = 'SKU-2001' AND u.code = 'EA' AND l.code = 'A-01';

WITH ledger AS (
    INSERT INTO "INVENTORY".stock_ledger (product_id, warehouse_id, location_id, movement_type, qty, unit_cost, document_type, document_id)
    SELECT p.id, w.id, l.id, 'receipt', 5, 12000000, 'inventory.goods_receipts', gr.id
    FROM "INVENTORY".goods_receipts gr, "INVENTORY".products p, "INVENTORY".warehouses w, "INVENTORY".locations l
    WHERE gr.reference_no = 'GR-0002' AND p.sku = 'SKU-2001' AND w.name = 'Gudang Pusat' AND l.code = 'A-01'
    RETURNING id, product_id, warehouse_id
)
INSERT INTO "INVENTORY".stock_valuation_layers (product_id, warehouse_id, source_stock_ledger_id, received_qty, remaining_qty, unit_cost)
SELECT product_id, warehouse_id, id, 5, 5, 12000000 FROM ledger;

-- tracking_mode = 'serial': receipt creates one unit-row per serial (§3M)
INSERT INTO "INVENTORY".stock_serials (product_id, serial_no, status, current_location_id, received_stock_ledger_id)
SELECT p.id, v.serial_no, 'in_stock', l.id, sl.id
FROM "INVENTORY".products p, "INVENTORY".locations l,
     "INVENTORY".stock_ledger sl,
     (VALUES ('SN-0001'), ('SN-0002'), ('SN-0003'), ('SN-0004'), ('SN-0005')) AS v(serial_no)
WHERE p.sku = 'SKU-2001' AND l.code = 'A-01'
  AND sl.document_type = 'inventory.goods_receipts' AND sl.document_id = (SELECT id FROM "INVENTORY".goods_receipts WHERE reference_no = 'GR-0002');

-- --- Issue 2: 1 laptop (SN-0001), to the client partner ----------------------

INSERT INTO "INVENTORY".goods_issues (warehouse_id, issue_date, partner_id, status, posted_at)
SELECT w.id, '2026-08-14', partner.id, 'posted', now()
FROM "INVENTORY".warehouses w, "CRM".partners partner
WHERE w.name = 'Gudang Pusat' AND partner.email = 'client@example-corp.com';

INSERT INTO "INVENTORY".goods_issue_lines (issue_id, product_id, qty, uom_id, source_location_id)
SELECT gi.id, p.id, 1, u.id, l.id
FROM "INVENTORY".goods_issues gi, "INVENTORY".products p, "INVENTORY".uoms u, "INVENTORY".locations l
WHERE p.sku = 'SKU-2001' AND u.code = 'EA' AND l.code = 'A-01'
ORDER BY gi.id DESC LIMIT 1;

WITH issue_ledger AS (
    INSERT INTO "INVENTORY".stock_ledger (product_id, warehouse_id, location_id, movement_type, qty, unit_cost, document_type, document_id)
    SELECT p.id, w.id, l.id, 'issue', -1, 12000000, 'inventory.goods_issues', gi.id
    FROM "INVENTORY".products p, "INVENTORY".warehouses w, "INVENTORY".locations l,
         (SELECT id FROM "INVENTORY".goods_issues ORDER BY id DESC LIMIT 1) gi
    WHERE p.sku = 'SKU-2001' AND w.name = 'Gudang Pusat' AND l.code = 'A-01'
    RETURNING id
)
UPDATE "INVENTORY".stock_serials ss
SET status = 'issued', current_location_id = NULL, issued_stock_ledger_id = issue_ledger.id
FROM issue_ledger
WHERE ss.serial_no = 'SN-0001';

UPDATE "INVENTORY".stock_valuation_layers svl
SET remaining_qty = remaining_qty - 1
FROM "INVENTORY".products p
WHERE svl.product_id = p.id AND p.sku = 'SKU-2001';

-- --- Rebuild stock_balances from stock_ledger — exactly the integrity ---
-- --- safety-net capability §3H describes, not just a one-time seed step. ---

INSERT INTO "INVENTORY".stock_balances (product_id, warehouse_id, location_id, batch_id, qty_on_hand)
SELECT product_id, warehouse_id, location_id, batch_id, SUM(qty)
FROM "INVENTORY".stock_ledger
GROUP BY product_id, warehouse_id, location_id, batch_id
HAVING SUM(qty) <> 0;

COMMIT;
