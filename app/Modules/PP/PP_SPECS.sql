-- =============================================================================
-- PP module (Production Planning & Scheduling) — schema + example seed data
-- Source spec: app/Modules/PP/PP_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), schema "PP", no tenant_id column
--              anywhere.
--
-- Cross-module references follow the same convention already used by
-- SALES_SPECS.sql/PURCHASE_SPECS.sql for INVENTORY.products: an
-- *informational* BIGINT column, commented, never a real FK — modules are
-- meant to be toggle-able per tenant plan (CLAUDE.md §4), so PP's schema
-- must not hard-fail if MES, HCM, Sales, or Purchase happen to be absent.
-- The one exception is INVENTORY.products itself: Inventory is present on
-- every plan today (config/tenant_modules.php), and PP_SPECS.md §4 already
-- states BOM/Recipe/Item-Params "reference INVENTORY.products" as a real
-- dependency — so those FKs ARE real below, matching that spec text; every
-- other cross-schema pointer (MES work centers/machines, MES production
-- orders, Sales orders, Purchase requisitions, HCM shifts) is informational.
--
-- BOM/Recipe ownership: this schema owns pp_boms/pp_recipes directly
-- (PP_SPECS.md §3D "BOM/Recipe Master Data & MRP Engine" — moved here from
-- an earlier MES-owned draft, see PP_SPECS.md §7 and MES_SPECS.md §3B). MES's
-- own mes_process_phases (once MES is built) will FK cross-schema into
-- pp_recipes.id — nothing in that direction is created here, this script
-- only owns PP's side of the boundary.
--
-- scenario_id (PP_SPECS.md §5 "Scenario Isolation"): one nullable column on
-- every planning table, NULL = baseline. Every planning table below carries
-- it as a real FK into pp_scenarios, so "run MRP/Capacity/Scheduling inside
-- a scenario" is pure query scoping, never a second copy of the table.
--
-- Release tracking (pp_planned_orders.released_subject_type/_id) is
-- deliberately informational, same discipline as Schedule's
-- subject_type/subject_id: MES doesn't have real tables in this DB yet
-- (MES_SPECS.md is spec-only per its own §7), so a real FK there would make
-- this script depend on a module that doesn't exist on disk.
--
-- Written with plain subqueries and data-modifying CTEs only — no psql
-- \gset or other client-specific meta-commands — so it runs through any
-- Postgres client.
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for the demo INVENTORY.products rows below

CREATE SCHEMA IF NOT EXISTS "PP";

-- -----------------------------------------------------------------------------
-- §3N / §4. Scenarios — created first, every planning table below FKs into it
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_scenarios (
    id                  BIGSERIAL PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    base_scenario_id    BIGINT REFERENCES "PP".pp_scenarios (id), -- NULL = branched from baseline (§3N)
    created_by          BIGINT NOT NULL REFERENCES users (id),
    status              VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'archived')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3A / §4. Item Planning Parameters
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_item_planning_params (
    id                          BIGSERIAL PRIMARY KEY,
    product_id                  BIGINT NOT NULL UNIQUE REFERENCES "INVENTORY".products (id),
    make_type                   VARCHAR(3) NOT NULL DEFAULT 'mts' CHECK (make_type IN ('mts', 'mto')),
    min_lot_qty                 NUMERIC(18, 4),
    max_lot_qty                 NUMERIC(18, 4),
    fixed_lot_qty                NUMERIC(18, 4),
    economic_lot_qty             NUMERIC(18, 4),
    safety_stock_qty             NUMERIC(18, 4) NOT NULL DEFAULT 0,
    lead_time_days               INT NOT NULL DEFAULT 0,
    planning_lead_time_days      INT NOT NULL DEFAULT 0,
    order_multiple               NUMERIC(18, 4),
    scrap_pct                    NUMERIC(5, 2) NOT NULL DEFAULT 0,
    yield_pct_override            NUMERIC(5, 2),
    production_calendar_ref       VARCHAR(100), -- informational; SCHEDULE resource/calendar code
    preferred_line_type           VARCHAR(20) CHECK (preferred_line_type IN ('mes_work_center')), -- informational (§3A)
    preferred_line_ref_id          BIGINT,       -- informational; MES.mes_work_centers.id when MES is built
    alternate_line_ref_id          BIGINT,       -- informational; same target as above
    planning_fence_days            INT NOT NULL DEFAULT 0,
    created_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3D / §4. BOM / Recipe Master Data — owned by PP, not MES (§3B boundary note)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_boms (
    id              BIGSERIAL PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    version         INT NOT NULL DEFAULT 1,
    effective_from  DATE NOT NULL DEFAULT CURRENT_DATE,
    effective_to    DATE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (product_id, version)
);

-- Only one active BOM version per product (§3D rule) — partial unique index,
-- same technique CRM/Inventory already use for a single-primary-row rule.
CREATE UNIQUE INDEX IF NOT EXISTS uq_pp_boms_one_active_per_product ON "PP".pp_boms (product_id) WHERE is_active;

CREATE TABLE IF NOT EXISTS "PP".pp_bom_lines (
    id                      BIGSERIAL PRIMARY KEY,
    bom_id                  BIGINT NOT NULL REFERENCES "PP".pp_boms (id) ON DELETE CASCADE,
    component_product_id    BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    qty_per_parent_unit      NUMERIC(18, 6) NOT NULL,
    uom_code                 VARCHAR(10),  -- informational; INVENTORY.uoms.code — component's issuing UoM
    scrap_pct                 NUMERIC(5, 2) NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_pp_bom_lines_bom ON "PP".pp_bom_lines (bom_id);

CREATE TABLE IF NOT EXISTS "PP".pp_recipes (
    id                    BIGSERIAL PRIMARY KEY,
    product_id            BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    version               INT NOT NULL DEFAULT 1,
    batch_size            NUMERIC(18, 4) NOT NULL,
    uom_code              VARCHAR(10),  -- informational; INVENTORY.uoms.code
    expected_yield_pct     NUMERIC(5, 2) NOT NULL DEFAULT 100,
    expected_waste_pct     NUMERIC(5, 2) NOT NULL DEFAULT 0,
    effective_from         DATE NOT NULL DEFAULT CURRENT_DATE,
    effective_to           DATE,
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (product_id, version)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_pp_recipes_one_active_per_product ON "PP".pp_recipes (product_id) WHERE is_active;

CREATE TABLE IF NOT EXISTS "PP".pp_recipe_ingredients (
    id                        BIGSERIAL PRIMARY KEY,
    recipe_id                 BIGINT NOT NULL REFERENCES "PP".pp_recipes (id) ON DELETE CASCADE,
    raw_material_product_id    BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    qty_per_batch               NUMERIC(18, 6) NOT NULL,
    uom_code                     VARCHAR(10)
);

CREATE INDEX IF NOT EXISTS idx_pp_recipe_ingredients_recipe ON "PP".pp_recipe_ingredients (recipe_id);

-- -----------------------------------------------------------------------------
-- §3E / §4. Resource & Resource Group Reference
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_resources (
    id              BIGSERIAL PRIMARY KEY,
    type            VARCHAR(15) NOT NULL CHECK (type IN ('tool', 'tank', 'utility', 'warehouse')),
    code            VARCHAR(30) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    capacity        NUMERIC(18, 4),
    uom_code        VARCHAR(10), -- native unit for this dimension (hours/kg/L/units) — §3G
    external_type   VARCHAR(20), -- informational; set when this row aliases an MES/HCM resource (§3E)
    external_id     BIGINT,      -- informational
    is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PP".pp_resource_groups (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "PP".pp_resource_group_members (
    id                  BIGSERIAL PRIMARY KEY,
    resource_group_id   BIGINT NOT NULL REFERENCES "PP".pp_resource_groups (id) ON DELETE CASCADE,
    resource_type       VARCHAR(20) NOT NULL CHECK (resource_type IN ('mes_work_center', 'mes_machine', 'mes_station', 'pp_resource')),
    resource_ref_id      BIGINT NOT NULL, -- informational for mes_* types; real PP.pp_resources.id for 'pp_resource' (app-resolved, §5 discipline)
    UNIQUE (resource_group_id, resource_type, resource_ref_id)
);

-- -----------------------------------------------------------------------------
-- §3J / §4. Setup & Changeover Matrix
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_changeover_matrix (
    id                  BIGSERIAL PRIMARY KEY,
    from_product_id      BIGINT REFERENCES "INVENTORY".products (id),
    from_family           VARCHAR(100), -- alternative to from_product_id — family-level rule
    to_product_id          BIGINT REFERENCES "INVENTORY".products (id),
    to_family               VARCHAR(100),
    resource_group_id       BIGINT REFERENCES "PP".pp_resource_groups (id),
    changeover_minutes       INT NOT NULL DEFAULT 0,
    cleaning_minutes          INT NOT NULL DEFAULT 0,
    CHECK (from_product_id IS NOT NULL OR from_family IS NOT NULL),
    CHECK (to_product_id IS NOT NULL OR to_family IS NOT NULL)
);

-- -----------------------------------------------------------------------------
-- §3B / §4. Demand Aggregation
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_demand_forecasts (
    id              BIGSERIAL PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,
    forecast_qty    NUMERIC(18, 4) NOT NULL,
    scenario_id     BIGINT REFERENCES "PP".pp_scenarios (id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (period_end >= period_start)
);

CREATE TABLE IF NOT EXISTS "PP".pp_demand_hdrs (
    id          BIGSERIAL PRIMARY KEY,
    run_date    DATE NOT NULL DEFAULT CURRENT_DATE,
    scenario_id BIGINT REFERENCES "PP".pp_scenarios (id),
    note        VARCHAR(255),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "PP".pp_demand_lines (
    id              BIGSERIAL PRIMARY KEY,
    demand_hdr_id    BIGINT NOT NULL REFERENCES "PP".pp_demand_hdrs (id) ON DELETE CASCADE,
    product_id        BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    period_start       DATE NOT NULL,
    period_end          DATE NOT NULL,
    qty                  NUMERIC(18, 4) NOT NULL,
    source_type           VARCHAR(15) NOT NULL CHECK (source_type IN ('sales_order', 'forecast', 'safety_stock', 'manual', 'dependent', 'transfer')),
    source_ref_type        VARCHAR(50), -- informational polymorphic pointer, e.g. 'sales.so_hdrs' (§3B)
    source_ref_id            BIGINT,
    scenario_id               BIGINT REFERENCES "PP".pp_scenarios (id),
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (period_end >= period_start)
);

CREATE INDEX IF NOT EXISTS idx_pp_demand_lines_product_period ON "PP".pp_demand_lines (product_id, period_start, scenario_id);

-- -----------------------------------------------------------------------------
-- §3C / §4. Master Production Schedule — MPS
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_mps_hdrs (
    id          BIGSERIAL PRIMARY KEY,
    product_id  BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    scenario_id BIGINT REFERENCES "PP".pp_scenarios (id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (product_id, scenario_id)
);

CREATE TABLE IF NOT EXISTS "PP".pp_mps_lines (
    id                          BIGSERIAL PRIMARY KEY,
    mps_hdr_id                   BIGINT NOT NULL REFERENCES "PP".pp_mps_hdrs (id) ON DELETE CASCADE,
    period_start                  DATE NOT NULL,
    period_end                     DATE NOT NULL,
    planned_qty                     NUMERIC(18, 4) NOT NULL,
    is_frozen                        BOOLEAN NOT NULL DEFAULT FALSE, -- freeze fence control (§3C)
    released_planned_order_id         BIGINT, -- informational; set to pp_planned_orders.id once released (§3C release action)
    scenario_id                        BIGINT REFERENCES "PP".pp_scenarios (id),
    created_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                           TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (mps_hdr_id, period_start),
    CHECK (period_end >= period_start)
);

-- -----------------------------------------------------------------------------
-- §3D / §4. MRP Engine & Planned Orders
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_mrp_runs (
    id              BIGSERIAL PRIMARY KEY,
    run_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    scenario_id     BIGINT REFERENCES "PP".pp_scenarios (id),
    triggered_by    BIGINT REFERENCES users (id),
    status          VARCHAR(10) NOT NULL DEFAULT 'completed' CHECK (status IN ('running', 'completed', 'failed'))
);

CREATE TABLE IF NOT EXISTS "PP".pp_planned_orders (
    id                      BIGSERIAL PRIMARY KEY,
    mrp_run_id               BIGINT REFERENCES "PP".pp_mrp_runs (id),
    plan_number                VARCHAR(30) NOT NULL UNIQUE, -- SYSCONFIG.config_snums PP_PLAN_LASTID in production (§5); plain value here
    order_type                  VARCHAR(10) NOT NULL CHECK (order_type IN ('production', 'purchase', 'transfer')),
    product_id                    BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    qty                              NUMERIC(18, 4) NOT NULL,
    need_by_date                      DATE NOT NULL,
    source_type                        VARCHAR(10) CHECK (source_type IN ('demand_line', 'mps_line')), -- informational
    source_id                           BIGINT,
    bom_id                                BIGINT REFERENCES "PP".pp_boms (id),     -- set when order_type = 'production' (discrete)
    recipe_id                             BIGINT REFERENCES "PP".pp_recipes (id), -- set when order_type = 'production' (process)
    status                                 VARCHAR(10) NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'firmed', 'released', 'cancelled')),
    scenario_id                             BIGINT REFERENCES "PP".pp_scenarios (id),
    released_subject_type                    VARCHAR(50), -- informational; 'mes.mes_prod_order_hdrs' | 'purchase.pur_req_hdrs' | 'inventory.transfer_requests'
    released_subject_id                       BIGINT,
    released_at                                TIMESTAMPTZ,
    created_at                                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (order_type <> 'production' OR bom_id IS NOT NULL OR recipe_id IS NOT NULL), -- §3D rule
    CHECK (status <> 'released' OR scenario_id IS NULL) -- §3D rule: release is baseline-only, enforced at DB level too
);

CREATE INDEX IF NOT EXISTS idx_pp_planned_orders_product ON "PP".pp_planned_orders (product_id, status, scenario_id);

-- -----------------------------------------------------------------------------
-- §3F / §4. Capacity Planning — RCCP
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_capacity_plans (
    id                  BIGSERIAL PRIMARY KEY,
    resource_group_id    BIGINT REFERENCES "PP".pp_resource_groups (id),
    resource_type          VARCHAR(20) CHECK (resource_type IN ('mes_work_center', 'mes_machine', 'pp_resource')), -- single-resource alternative to resource_group_id
    resource_ref_id          BIGINT, -- informational (mes_*) or PP.pp_resources.id (pp_resource)
    period_start               DATE NOT NULL,
    period_end                  DATE NOT NULL,
    required_hours                NUMERIC(10, 2) NOT NULL,
    available_hours                 NUMERIC(10, 2) NOT NULL,
    scenario_id                       BIGINT REFERENCES "PP".pp_scenarios (id),
    created_at                         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (resource_group_id IS NOT NULL OR resource_ref_id IS NOT NULL),
    CHECK (period_end >= period_start)
);

CREATE INDEX IF NOT EXISTS idx_pp_capacity_plans_period ON "PP".pp_capacity_plans (period_start, period_end, scenario_id);

-- -----------------------------------------------------------------------------
-- §3H / §4. Detailed Scheduling
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_schedule_ops (
    id                  BIGSERIAL PRIMARY KEY,
    planned_order_id     BIGINT NOT NULL REFERENCES "PP".pp_planned_orders (id) ON DELETE CASCADE,
    seq                    INT NOT NULL DEFAULT 1,
    resource_type            VARCHAR(20) CHECK (resource_type IN ('mes_work_center', 'mes_machine', 'mes_station')), -- informational
    resource_ref_id            BIGINT,
    planned_start                TIMESTAMPTZ NOT NULL,
    planned_end                    TIMESTAMPTZ NOT NULL,
    status                          VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'committed', 'released')),
    scenario_id                      BIGINT REFERENCES "PP".pp_scenarios (id),
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (planned_end > planned_start)
);

CREATE INDEX IF NOT EXISTS idx_pp_schedule_ops_order ON "PP".pp_schedule_ops (planned_order_id, seq);

-- -----------------------------------------------------------------------------
-- §3M / §4. Planning Exception Center
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_exceptions (
    id              BIGSERIAL PRIMARY KEY,
    exception_type  VARCHAR(25) NOT NULL CHECK (exception_type IN (
                        'material_shortage', 'capacity_overload', 'late_order',
                        'missing_routing', 'resource_unavailable', 'maintenance_conflict',
                        'late_purchase'
                    )),
    severity        VARCHAR(10) NOT NULL DEFAULT 'medium' CHECK (severity IN ('low', 'medium', 'high', 'critical')),
    subject_type    VARCHAR(50) NOT NULL, -- informational, e.g. 'pp.planned_orders', 'pp.capacity_plans'
    subject_id      BIGINT NOT NULL,
    detail          TEXT,
    detected_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    status          VARCHAR(15) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'acknowledged', 'resolved')),
    resolved_at     TIMESTAMPTZ,
    resolved_by     BIGINT REFERENCES users (id)
);

CREATE INDEX IF NOT EXISTS idx_pp_exceptions_open ON "PP".pp_exceptions (status, severity) WHERE status <> 'resolved';

-- -----------------------------------------------------------------------------
-- §3U-equivalent / §4. Audit Trail — append-only, same convention as
-- CUSTOMFIELDS.field_def_audit_logs / MES.mes_audit_logs
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "PP".pp_audit_logs (
    id                  BIGSERIAL PRIMARY KEY,
    subject_type          VARCHAR(50) NOT NULL,
    subject_id              BIGINT NOT NULL,
    action                    VARCHAR(20) NOT NULL,
    actor_id                    BIGINT NOT NULL REFERENCES users (id),
    before_snapshot                JSONB,
    after_snapshot                   JSONB,
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- NOTE — deliberately not created here: MES's own tables (MES_SPECS.md is
-- spec-only, no migrations exist yet — every PP↔MES pointer above is
-- informational for exactly that reason); SYSCONFIG.config_snums (real
-- serial numbering lives in the already-built SysConfig module; this script
-- fakes plan_number values directly); CUSTOMFIELDS.field_defs rows for
-- pp_item_planning_params/pp_boms/pp_recipes/pp_planned_orders/pp_mps_lines
-- (PP_SPECS.md §4 custom-fields registration — a CustomFields-module concern,
-- not this schema's own DDL).
-- =============================================================================

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
--
-- Scenario: one discrete finished good (Steel Bracket Assembly, BOM of two
-- components) and one process finished good (Industrial Solvent, recipe of
-- two chemical ingredients) — deliberately covers both production models per
-- PP_SPECS.md §1 "one planning engine, two production models". Walks the
-- full pipeline: demand → MPS → MRP run → planned orders (one released
-- production order, one purchase-type shortage) → resource groups →
-- capacity plan (a Welding-style overload, callback to the source brief's
-- own RCCP example) → one exception → one detailed-scheduling op → one
-- "+15% demand" scenario proving baseline isolation → one audit log entry.
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- Also seeds a handful of INVENTORY.products/product_categories/uoms rows
-- so the BOM/Recipe demo data is coherent end-to-end — INVENTORY is a real
-- dependency here (see header note), not an informational one.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 0. INVENTORY dependency data (uoms, categories, 6 demo products)
-- ---------------------------------------------------------------------------

INSERT INTO "INVENTORY".uoms (code, name) VALUES ('EA', 'Each'), ('KG', 'Kilogram');

INSERT INTO "INVENTORY".product_categories (name) VALUES ('Finished Goods'), ('Raw Materials');

INSERT INTO "INVENTORY".products (uuid, sku, name, category_id, base_uom_id, tracking_mode)
SELECT gen_random_uuid(), v.sku, v.name, c.id, u.id, v.tracking_mode
FROM (VALUES
    ('ASM-100',       'Steel Bracket Assembly',   'Finished Goods', 'EA', 'batch'),
    ('SOLV-200',      'Industrial Solvent',       'Finished Goods', 'KG', 'batch'),
    ('RM-STEEL-ROD',  'Steel Rod 10mm',           'Raw Materials',  'EA', 'none'),
    ('RM-FASTENER',   'Fastener Kit',              'Raw Materials',  'EA', 'none'),
    ('CHEM-A',        'Base Chemical A',           'Raw Materials',  'KG', 'batch'),
    ('CHEM-B',        'Base Chemical B',           'Raw Materials',  'KG', 'batch')
) AS v (sku, name, category_name, uom_code, tracking_mode)
JOIN "INVENTORY".product_categories c ON c.name = v.category_name
JOIN "INVENTORY".uoms u ON u.code = v.uom_code;

-- ---------------------------------------------------------------------------
-- 1. Item Planning Parameters
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_item_planning_params (product_id, make_type, min_lot_qty, safety_stock_qty, lead_time_days, planning_fence_days)
SELECT p.id, 'mts', 100, 50, 5, 7 FROM "INVENTORY".products p WHERE p.sku = 'ASM-100'
UNION ALL
SELECT p.id, 'mts', 500, 200, 3, 5 FROM "INVENTORY".products p WHERE p.sku = 'SOLV-200';

-- ---------------------------------------------------------------------------
-- 2. Discrete BOM: Steel Bracket Assembly = 2x Steel Rod + 1x Fastener Kit
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_boms (product_id, version, is_active)
SELECT p.id, 1, TRUE FROM "INVENTORY".products p WHERE p.sku = 'ASM-100';

INSERT INTO "PP".pp_bom_lines (bom_id, component_product_id, qty_per_parent_unit, uom_code, scrap_pct)
SELECT b.id, comp.id, v.qty, 'EA', v.scrap_pct
FROM "PP".pp_boms b
JOIN "INVENTORY".products parent ON parent.id = b.product_id AND parent.sku = 'ASM-100'
JOIN (VALUES ('RM-STEEL-ROD', 2, 1.0), ('RM-FASTENER', 1, 0.0)) AS v (sku, qty, scrap_pct) ON TRUE
JOIN "INVENTORY".products comp ON comp.sku = v.sku;

-- ---------------------------------------------------------------------------
-- 3. Process Recipe: 1,000 kg Industrial Solvent batch = 600kg Chem A +
--    380kg Chem B, 98% expected yield, 2% expected waste
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_recipes (product_id, version, batch_size, uom_code, expected_yield_pct, expected_waste_pct, is_active)
SELECT p.id, 1, 1000, 'KG', 98, 2, TRUE FROM "INVENTORY".products p WHERE p.sku = 'SOLV-200';

INSERT INTO "PP".pp_recipe_ingredients (recipe_id, raw_material_product_id, qty_per_batch, uom_code)
SELECT r.id, ing.id, v.qty, 'KG'
FROM "PP".pp_recipes r
JOIN "INVENTORY".products parent ON parent.id = r.product_id AND parent.sku = 'SOLV-200'
JOIN (VALUES ('CHEM-A', 600), ('CHEM-B', 380)) AS v (sku, qty) ON TRUE
JOIN "INVENTORY".products ing ON ing.sku = v.sku;

-- ---------------------------------------------------------------------------
-- 4. Resources & Resource Groups — MIXING group aliases 3 (still-hypothetical)
--    MES mixers; ASSEMBLY-LINE aliases 1 MES work center; one PP-owned tank
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_resources (type, code, name, capacity, uom_code, is_active) VALUES
    ('tank', 'TANK-01', 'Solvent Mixing Tank 01', 10000, 'L', TRUE);

INSERT INTO "PP".pp_resource_groups (code, name) VALUES
    ('MIXING', 'Mixing (Mixer 01/02/03)'),
    ('ASSEMBLY-LINE', 'Assembly Line A');

INSERT INTO "PP".pp_resource_group_members (resource_group_id, resource_type, resource_ref_id)
SELECT rg.id, 'mes_machine', v.ref_id
FROM "PP".pp_resource_groups rg
JOIN (VALUES ('MIXING', 101), ('MIXING', 102), ('MIXING', 103)) AS v (code, ref_id) ON v.code = rg.code
UNION ALL
SELECT rg.id, 'mes_work_center', 201
FROM "PP".pp_resource_groups rg WHERE rg.code = 'ASSEMBLY-LINE';

-- ---------------------------------------------------------------------------
-- 5. Changeover matrix: switching Assembly Line from ASM-100 to any other
--    product family costs 30 minutes setup, 10 minutes cleaning
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_changeover_matrix (from_product_id, to_family, resource_group_id, changeover_minutes, cleaning_minutes)
SELECT p.id, 'other', rg.id, 30, 10
FROM "INVENTORY".products p, "PP".pp_resource_groups rg
WHERE p.sku = 'ASM-100' AND rg.code = 'ASSEMBLY-LINE';

-- ---------------------------------------------------------------------------
-- 6. Demand: one Sales-order-sourced line for each finished good, September 2026
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_demand_hdrs (run_date, note) VALUES (DATE '2026-08-30', 'Weekly MRP demand aggregation run');

INSERT INTO "PP".pp_demand_lines (demand_hdr_id, product_id, period_start, period_end, qty, source_type, source_ref_type, source_ref_id)
SELECT dh.id, p.id, DATE '2026-09-01', DATE '2026-09-30', v.qty, 'sales_order', 'sales.so_hdrs', v.so_id
FROM "PP".pp_demand_hdrs dh, (VALUES ('ASM-100', 1200, 5501), ('SOLV-200', 950, 5502)) AS v (sku, qty, so_id)
JOIN "INVENTORY".products p ON p.sku = v.sku
WHERE dh.note = 'Weekly MRP demand aggregation run';

-- ---------------------------------------------------------------------------
-- 7. MPS: same two products, one September period each, not yet frozen
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_mps_hdrs (product_id)
SELECT p.id FROM "INVENTORY".products p WHERE p.sku IN ('ASM-100', 'SOLV-200');

INSERT INTO "PP".pp_mps_lines (mps_hdr_id, period_start, period_end, planned_qty, is_frozen)
SELECT mh.id, DATE '2026-09-01', DATE '2026-09-30', dl.qty, FALSE
FROM "PP".pp_mps_hdrs mh
JOIN "INVENTORY".products p ON p.id = mh.product_id
JOIN "PP".pp_demand_lines dl ON dl.product_id = p.id;

-- ---------------------------------------------------------------------------
-- 8. MRP run → 3 planned orders: 2 production (one per finished good, one
--    released into MES), 1 purchase (Steel Rod net-short after netting)
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_mrp_runs (triggered_by, status)
SELECT u.id, 'completed' FROM users u WHERE u.email = 'staff@nusaevo.com';

INSERT INTO "PP".pp_planned_orders (mrp_run_id, plan_number, order_type, product_id, qty, need_by_date, source_type, source_id, bom_id, status, released_subject_type, released_subject_id, released_at)
SELECT mr.id, 'PLN-2026-000001', 'production', p.id, 1200, DATE '2026-09-25', 'mps_line', ml.id, b.id, 'released', 'mes.mes_prod_order_hdrs', 9001, now()
FROM "PP".pp_mrp_runs mr
JOIN "INVENTORY".products p ON p.sku = 'ASM-100'
JOIN "PP".pp_mps_hdrs mh ON mh.product_id = p.id
JOIN "PP".pp_mps_lines ml ON ml.mps_hdr_id = mh.id
JOIN "PP".pp_boms b ON b.product_id = p.id AND b.is_active
ORDER BY mr.id DESC LIMIT 1;

INSERT INTO "PP".pp_planned_orders (mrp_run_id, plan_number, order_type, product_id, qty, need_by_date, source_type, source_id, recipe_id, status)
SELECT mr.id, 'PLN-2026-000002', 'production', p.id, 950, DATE '2026-09-22', 'mps_line', ml.id, r.id, 'planned'
FROM "PP".pp_mrp_runs mr
JOIN "INVENTORY".products p ON p.sku = 'SOLV-200'
JOIN "PP".pp_mps_hdrs mh ON mh.product_id = p.id
JOIN "PP".pp_mps_lines ml ON ml.mps_hdr_id = mh.id
JOIN "PP".pp_recipes r ON r.product_id = p.id AND r.is_active
ORDER BY mr.id DESC LIMIT 1;

INSERT INTO "PP".pp_planned_orders (mrp_run_id, plan_number, order_type, product_id, qty, need_by_date, status)
SELECT mr.id, 'PLN-2026-000003', 'purchase', p.id, 800, DATE '2026-09-18', 'planned'
FROM "PP".pp_mrp_runs mr, "INVENTORY".products p
WHERE p.sku = 'RM-STEEL-ROD'
ORDER BY mr.id DESC LIMIT 1;

-- ---------------------------------------------------------------------------
-- 9. Capacity plan: Assembly Line overloaded at 124% for the same period
--    (deliberate callback to the source brief's own Welding example)
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_capacity_plans (resource_group_id, period_start, period_end, required_hours, available_hours)
SELECT rg.id, DATE '2026-09-01', DATE '2026-09-30', 620, 500
FROM "PP".pp_resource_groups rg WHERE rg.code = 'ASSEMBLY-LINE';

-- ---------------------------------------------------------------------------
-- 10. Exception generated from the overload above
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_exceptions (exception_type, severity, subject_type, subject_id, detail)
SELECT 'capacity_overload', 'high', 'pp.pp_capacity_plans', cp.id,
       'Assembly Line A loaded at 124% (620hr required vs 500hr available) for Sep 2026.'
FROM "PP".pp_capacity_plans cp
JOIN "PP".pp_resource_groups rg ON rg.id = cp.resource_group_id AND rg.code = 'ASSEMBLY-LINE';

INSERT INTO "PP".pp_exceptions (exception_type, severity, subject_type, subject_id, detail)
SELECT 'material_shortage', 'medium', 'pp.pp_planned_orders', po.id,
       'Steel Rod 10mm short 800 EA against September demand.'
FROM "PP".pp_planned_orders po WHERE po.plan_number = 'PLN-2026-000003';

-- ---------------------------------------------------------------------------
-- 11. Detailed schedule op for the released Assembly production order
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_schedule_ops (planned_order_id, seq, resource_type, resource_ref_id, planned_start, planned_end, status)
SELECT po.id, 1, 'mes_work_center', 201, TIMESTAMPTZ '2026-09-22 08:00:00+07', TIMESTAMPTZ '2026-09-24 17:00:00+07', 'committed'
FROM "PP".pp_planned_orders po WHERE po.plan_number = 'PLN-2026-000001';

-- ---------------------------------------------------------------------------
-- 12. Scenario: "What if demand +15%?" — proves baseline isolation (§3N)
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_scenarios (name, created_by, status)
SELECT 'What if: September demand +15%', u.id, 'active'
FROM users u WHERE u.email = 'staff@nusaevo.com';

INSERT INTO "PP".pp_demand_hdrs (run_date, scenario_id, note)
SELECT DATE '2026-08-30', sc.id, 'Scenario demand run'
FROM "PP".pp_scenarios sc WHERE sc.name = 'What if: September demand +15%';

INSERT INTO "PP".pp_demand_lines (demand_hdr_id, product_id, period_start, period_end, qty, source_type, scenario_id)
SELECT dh.id, p.id, DATE '2026-09-01', DATE '2026-09-30', ROUND(dl.qty * 1.15), 'manual', dh.scenario_id
FROM "PP".pp_demand_hdrs dh
JOIN "PP".pp_scenarios sc ON sc.id = dh.scenario_id
JOIN "INVENTORY".products p ON p.sku = 'ASM-100'
JOIN "PP".pp_demand_lines dl ON dl.product_id = p.id AND dl.scenario_id IS NULL
WHERE dh.note = 'Scenario demand run';

INSERT INTO "PP".pp_capacity_plans (resource_group_id, period_start, period_end, required_hours, available_hours, scenario_id)
SELECT rg.id, DATE '2026-09-01', DATE '2026-09-30', 713, 500, sc.id -- 620 * 1.15, same available capacity
FROM "PP".pp_resource_groups rg, "PP".pp_scenarios sc
WHERE rg.code = 'ASSEMBLY-LINE' AND sc.name = 'What if: September demand +15%';

-- ---------------------------------------------------------------------------
-- 13. Audit log: MPS cell for ASM-100 was manually reduced from 1300 to 1200
--     before this seed ran (illustrative before/after snapshot)
-- ---------------------------------------------------------------------------

INSERT INTO "PP".pp_audit_logs (subject_type, subject_id, action, actor_id, before_snapshot, after_snapshot)
SELECT 'pp.pp_mps_lines', ml.id, 'update', u.id,
       jsonb_build_object('planned_qty', 1300), jsonb_build_object('planned_qty', 1200)
FROM "PP".pp_mps_lines ml
JOIN "PP".pp_mps_hdrs mh ON mh.id = ml.mps_hdr_id
JOIN "INVENTORY".products p ON p.id = mh.product_id AND p.sku = 'ASM-100'
JOIN users u ON u.email = 'admin@nusaevo.com';

COMMIT;

-- =============================================================================
-- Sanity check counts
-- =============================================================================
SELECT 'pp_item_planning_params' AS table_name, COUNT(*) FROM "PP".pp_item_planning_params
UNION ALL SELECT 'pp_boms', COUNT(*) FROM "PP".pp_boms
UNION ALL SELECT 'pp_bom_lines', COUNT(*) FROM "PP".pp_bom_lines
UNION ALL SELECT 'pp_recipes', COUNT(*) FROM "PP".pp_recipes
UNION ALL SELECT 'pp_recipe_ingredients', COUNT(*) FROM "PP".pp_recipe_ingredients
UNION ALL SELECT 'pp_resources', COUNT(*) FROM "PP".pp_resources
UNION ALL SELECT 'pp_resource_groups', COUNT(*) FROM "PP".pp_resource_groups
UNION ALL SELECT 'pp_resource_group_members', COUNT(*) FROM "PP".pp_resource_group_members
UNION ALL SELECT 'pp_changeover_matrix', COUNT(*) FROM "PP".pp_changeover_matrix
UNION ALL SELECT 'pp_demand_hdrs', COUNT(*) FROM "PP".pp_demand_hdrs
UNION ALL SELECT 'pp_demand_lines', COUNT(*) FROM "PP".pp_demand_lines
UNION ALL SELECT 'pp_mps_hdrs', COUNT(*) FROM "PP".pp_mps_hdrs
UNION ALL SELECT 'pp_mps_lines', COUNT(*) FROM "PP".pp_mps_lines
UNION ALL SELECT 'pp_mrp_runs', COUNT(*) FROM "PP".pp_mrp_runs
UNION ALL SELECT 'pp_planned_orders', COUNT(*) FROM "PP".pp_planned_orders
UNION ALL SELECT 'pp_capacity_plans', COUNT(*) FROM "PP".pp_capacity_plans
UNION ALL SELECT 'pp_schedule_ops', COUNT(*) FROM "PP".pp_schedule_ops
UNION ALL SELECT 'pp_exceptions', COUNT(*) FROM "PP".pp_exceptions
UNION ALL SELECT 'pp_scenarios', COUNT(*) FROM "PP".pp_scenarios
UNION ALL SELECT 'pp_audit_logs', COUNT(*) FROM "PP".pp_audit_logs;

-- Demonstrates the net-requirement calculation §3D describes, for ASM-100,
-- Sep 2026 (Available Inventory/Scheduled Receipts assumed 0 for this demo —
-- a real run reads those from INVENTORY.stock_ledger via InventoryService):
--   Gross Requirement - Available - Scheduled Receipts + Safety Stock = Net
SELECT p.sku,
       dl.qty                                    AS gross_requirement,
       ipp.safety_stock_qty                       AS safety_stock,
       dl.qty + ipp.safety_stock_qty               AS net_requirement
FROM "PP".pp_demand_lines dl
JOIN "INVENTORY".products p ON p.id = dl.product_id
JOIN "PP".pp_item_planning_params ipp ON ipp.product_id = p.id
WHERE p.sku = 'ASM-100' AND dl.scenario_id IS NULL;

-- Demonstrates the RCCP overload check (§3F) that produced exception #1 above
SELECT rg.name, cp.required_hours, cp.available_hours,
       ROUND(cp.required_hours / cp.available_hours * 100, 1) AS load_pct
FROM "PP".pp_capacity_plans cp
JOIN "PP".pp_resource_groups rg ON rg.id = cp.resource_group_id
WHERE cp.scenario_id IS NULL;

-- Demonstrates scenario isolation (§3N/§5): baseline vs. "+15%" scenario
-- side by side — the scenario row never touched the baseline's own line
SELECT COALESCE(sc.name, 'BASELINE') AS scenario, p.sku, dl.qty
FROM "PP".pp_demand_lines dl
JOIN "INVENTORY".products p ON p.id = dl.product_id AND p.sku = 'ASM-100'
LEFT JOIN "PP".pp_scenarios sc ON sc.id = dl.scenario_id
ORDER BY scenario;
