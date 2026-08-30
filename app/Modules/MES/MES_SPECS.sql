-- =============================================================================
-- MES module (Manufacturing Execution System) — schema + example seed data
-- Source spec: app/Modules/MES/MES_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), schema "MES", no tenant_id column
--              anywhere.
--
-- Cross-module references follow the same convention PP_SPECS.sql already
-- established: a real FK only where the referenced module is a hard,
-- always-present dependency; an *informational* BIGINT column (commented,
-- never a real FK) everywhere the referenced module is plan-optional
-- relative to MES (CLAUDE.md §4 — modules are meant to be toggle-able per
-- tenant plan).
--   - INVENTORY.products / INVENTORY.warehouses / INVENTORY.stock_batches /
--     INVENTORY.stock_serials  → REAL FK (Inventory is on every plan today,
--     config/tenant_modules.php).
--   - users (Laravel's own core table)                        → REAL FK.
--   - PP.pp_boms / PP.pp_recipes                                → REAL FK.
--     PP is sequenced immediately before MES in CLAUDE.md §5 and is a hard
--     (not optional) dependency for MES's own material composition — the
--     load-bearing boundary decision recorded in MES_SPECS.md §3B/§5 and
--     PP_SPECS.md §5. Application code still never raw-joins these tables:
--     it calls PpService::getActiveBom/getActiveRecipe/scaleRecipe
--     (MES_SPECS.md §3B) — the real FK is a data-integrity guarantee, not a
--     query path.
--   - HCM.shift_assignments (mes_batch_phases.operator_id,
--     mes_shift_handover_notes.shift_assignment_id)             → informational
--     only — HCM is plan-optional relative to MES (MES_SPECS.md §3P).
--
-- mes_batch_ingredients is one table beyond MES_SPECS.md §4's own table list:
-- §3I's prose ("mes_batches ... stores the *resolved* scaled quantities via
-- PpService::scaleRecipe()") implies per-ingredient rows once a recipe has
-- more than one ingredient, so this script materializes that as its own
-- line table rather than cramming a JSON blob onto mes_batches. Everything
-- else below matches MES_SPECS.md §4's table list 1:1.
--
-- Written with plain subqueries and data-modifying CTEs only — no psql
-- \gset or other client-specific meta-commands — so it runs through any
-- Postgres client.
-- =============================================================================

BEGIN;

CREATE SCHEMA IF NOT EXISTS "MES";

-- -----------------------------------------------------------------------------
-- §3D / §4. Equipment Master Data
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_work_centers (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    area_line   VARCHAR(100),
    type        VARCHAR(20) NOT NULL DEFAULT 'discrete' CHECK (type IN ('discrete', 'process')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "MES".mes_machines (
    id              BIGSERIAL PRIMARY KEY,
    work_center_id  BIGINT NOT NULL REFERENCES "MES".mes_work_centers (id),
    code            VARCHAR(30) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'idle' CHECK (status IN (
                        'running', 'idle', 'down', 'maintenance', 'setup',
                        'waiting_material', 'waiting_operator', 'waiting_qc'
                    )),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_mes_machines_work_center ON "MES".mes_machines (work_center_id);

CREATE TABLE IF NOT EXISTS "MES".mes_stations (
    id              BIGSERIAL PRIMARY KEY,
    work_center_id  BIGINT REFERENCES "MES".mes_work_centers (id),
    machine_id      BIGINT REFERENCES "MES".mes_machines (id),
    code            VARCHAR(30) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    CHECK (work_center_id IS NOT NULL OR machine_id IS NOT NULL)
);

-- -----------------------------------------------------------------------------
-- §3E / §4. Routing / Operations (discrete)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_routings (
    id          BIGSERIAL PRIMARY KEY,
    product_id  BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    version     INT NOT NULL DEFAULT 1,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (product_id, version)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_mes_routings_one_active_per_product ON "MES".mes_routings (product_id) WHERE is_active;

CREATE TABLE IF NOT EXISTS "MES".mes_routing_ops (
    id                  BIGSERIAL PRIMARY KEY,
    routing_id          BIGINT NOT NULL REFERENCES "MES".mes_routings (id) ON DELETE CASCADE,
    seq                 INT NOT NULL,
    op_code             VARCHAR(30) NOT NULL,
    op_name             VARCHAR(150) NOT NULL,
    work_center_id      BIGINT NOT NULL REFERENCES "MES".mes_work_centers (id),
    setup_time_minutes  INT NOT NULL DEFAULT 0,
    run_time_minutes    INT NOT NULL DEFAULT 0,
    queue_time_minutes  INT NOT NULL DEFAULT 0,
    standard_output_qty NUMERIC(18, 4),
    instructions        TEXT,
    UNIQUE (routing_id, seq)
);

CREATE INDEX IF NOT EXISTS idx_mes_routing_ops_routing ON "MES".mes_routing_ops (routing_id, seq);

-- -----------------------------------------------------------------------------
-- §3F / §4. Process Phases & Parameters (process) — recipe_id is a REAL
-- cross-schema FK into PP.pp_recipes (§3B boundary note; BOM/Recipe itself
-- lives in PP, not here — see header note above)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_process_phases (
    id                          BIGSERIAL PRIMARY KEY,
    recipe_id                   BIGINT NOT NULL REFERENCES "PP".pp_recipes (id),
    seq                         INT NOT NULL,
    phase_name                  VARCHAR(150) NOT NULL,
    work_center_id              BIGINT REFERENCES "MES".mes_work_centers (id),
    standard_duration_minutes   INT,
    UNIQUE (recipe_id, seq)
);

CREATE INDEX IF NOT EXISTS idx_mes_process_phases_recipe ON "MES".mes_process_phases (recipe_id, seq);

CREATE TABLE IF NOT EXISTS "MES".mes_process_parameters (
    id                  BIGSERIAL PRIMARY KEY,
    process_phase_id    BIGINT NOT NULL REFERENCES "MES".mes_process_phases (id) ON DELETE CASCADE,
    parameter_code      VARCHAR(50) NOT NULL,
    target_value        NUMERIC(18, 4),
    min_value           NUMERIC(18, 4),
    max_value           NUMERIC(18, 4),
    uom_code            VARCHAR(10)
);

CREATE INDEX IF NOT EXISTS idx_mes_process_parameters_phase ON "MES".mes_process_parameters (process_phase_id);

-- -----------------------------------------------------------------------------
-- §3L / §4. Quality — inspection plans & characteristics (master data)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_qc_inspection_plans (
    id          BIGSERIAL PRIMARY KEY,
    product_id  BIGINT REFERENCES "INVENTORY".products (id),
    name        VARCHAR(150) NOT NULL
);

CREATE TABLE IF NOT EXISTS "MES".mes_qc_characteristics (
    id                  BIGSERIAL PRIMARY KEY,
    plan_id             BIGINT NOT NULL REFERENCES "MES".mes_qc_inspection_plans (id) ON DELETE CASCADE,
    characteristic_name VARCHAR(150) NOT NULL,
    spec_type           VARCHAR(15) NOT NULL DEFAULT 'numeric' CHECK (spec_type IN ('numeric', 'pass_fail')),
    target_value        NUMERIC(18, 4),
    min_value           NUMERIC(18, 4),
    max_value            NUMERIC(18, 4),
    uom_code             VARCHAR(10)
);

CREATE INDEX IF NOT EXISTS idx_mes_qc_characteristics_plan ON "MES".mes_qc_characteristics (plan_id);

-- -----------------------------------------------------------------------------
-- §3A / §4. Production Order — bom_id/recipe_id are REAL cross-schema FKs
-- into PP.pp_boms/PP.pp_recipes (§3B boundary note); routing_id stays MES's
-- own (§3E)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_prod_order_hdrs (
    id                  BIGSERIAL PRIMARY KEY,
    order_number        VARCHAR(30) NOT NULL UNIQUE, -- SYSCONFIG.config_snums MES_MO_LASTID in production (§5); plain value here
    product_id          BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    production_model    VARCHAR(10) NOT NULL CHECK (production_model IN ('assembly', 'process')),
    bom_id               BIGINT REFERENCES "PP".pp_boms (id),      -- set when production_model = 'assembly'
    recipe_id             BIGINT REFERENCES "PP".pp_recipes (id), -- set when production_model = 'process'
    routing_id             BIGINT REFERENCES "MES".mes_routings (id), -- discrete only (§3E)
    qty                      NUMERIC(18, 4) NOT NULL,
    uom_code                  VARCHAR(10),
    planned_start               TIMESTAMPTZ,
    planned_end                   TIMESTAMPTZ,
    actual_start                    TIMESTAMPTZ,
    actual_end                        TIMESTAMPTZ,
    priority                            VARCHAR(10) NOT NULL DEFAULT 'normal',
    warehouse_id                          BIGINT REFERENCES "INVENTORY".warehouses (id),
    line_area                               VARCHAR(100),
    status                                    VARCHAR(15) NOT NULL DEFAULT 'draft' CHECK (status IN (
                                                'draft', 'released', 'in_progress', 'paused', 'completed', 'cancelled'
                                            )),
    parent_order_id                             BIGINT REFERENCES "MES".mes_prod_order_hdrs (id),
    source_type                                   VARCHAR(50), -- informational polymorphic pointer, e.g. 'pp.pp_planned_orders'
    source_id                                       BIGINT,
    created_at                                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (production_model <> 'assembly' OR bom_id IS NOT NULL),
    CHECK (production_model <> 'process' OR recipe_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_mes_prod_order_hdrs_status ON "MES".mes_prod_order_hdrs (status, production_model);
CREATE INDEX IF NOT EXISTS idx_mes_prod_order_hdrs_product ON "MES".mes_prod_order_hdrs (product_id);

-- -----------------------------------------------------------------------------
-- §3C / §4. Production Event Ledger — append-only
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_prod_events (
    id              BIGSERIAL PRIMARY KEY,
    order_id        BIGINT NOT NULL REFERENCES "MES".mes_prod_order_hdrs (id),
    batch_id        BIGINT, -- FK added after mes_batches exists, below
    operation_ref   BIGINT, -- mes_routing_ops.id or mes_batch_phases.id, depending on event
    event_type      VARCHAR(30) NOT NULL CHECK (event_type IN (
                        'order_released', 'material_issued', 'material_returned',
                        'operation_started', 'operation_paused', 'operation_completed',
                        'machine_started', 'machine_stopped', 'parameter_recorded',
                        'qc_sample_taken', 'scrap_recorded', 'output_produced',
                        'downtime_started', 'downtime_ended', 'batch_split', 'batch_merged'
                    )),
    payload         JSONB,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    user_id         BIGINT NOT NULL REFERENCES users (id),
    machine_id      BIGINT REFERENCES "MES".mes_machines (id)
);

CREATE INDEX IF NOT EXISTS idx_mes_prod_events_order ON "MES".mes_prod_events (order_id, occurred_at);

-- -----------------------------------------------------------------------------
-- §3I / §4. Batch / Process Execution — recipe_id is a REAL cross-schema FK
-- into PP.pp_recipes
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_batches (
    id                  BIGSERIAL PRIMARY KEY,
    order_id            BIGINT NOT NULL REFERENCES "MES".mes_prod_order_hdrs (id),
    batch_number        VARCHAR(30) NOT NULL UNIQUE,
    recipe_id           BIGINT NOT NULL REFERENCES "PP".pp_recipes (id),
    status              VARCHAR(15) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'running', 'paused', 'completed', 'cancelled')),
    planned_qty         NUMERIC(18, 4) NOT NULL,
    actual_yield_pct    NUMERIC(5, 2),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_mes_batches_order ON "MES".mes_batches (order_id);

ALTER TABLE "MES".mes_prod_events
    ADD CONSTRAINT fk_mes_prod_events_batch FOREIGN KEY (batch_id) REFERENCES "MES".mes_batches (id);

CREATE TABLE IF NOT EXISTS "MES".mes_batch_ingredients (
    id                          BIGSERIAL PRIMARY KEY,
    batch_id                    BIGINT NOT NULL REFERENCES "MES".mes_batches (id) ON DELETE CASCADE,
    raw_material_product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    resolved_qty                   NUMERIC(18, 6) NOT NULL, -- PpService::scaleRecipe() output, resolved at batch creation (§3I)
    uom_code                         VARCHAR(10)
);

CREATE INDEX IF NOT EXISTS idx_mes_batch_ingredients_batch ON "MES".mes_batch_ingredients (batch_id);

CREATE TABLE IF NOT EXISTS "MES".mes_batch_phases (
    id                  BIGSERIAL PRIMARY KEY,
    batch_id            BIGINT NOT NULL REFERENCES "MES".mes_batches (id) ON DELETE CASCADE,
    process_phase_id    BIGINT NOT NULL REFERENCES "MES".mes_process_phases (id),
    seq                 INT NOT NULL,
    status              VARCHAR(15) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'paused', 'completed')),
    start_at            TIMESTAMPTZ,
    end_at              TIMESTAMPTZ,
    operator_id         BIGINT, -- informational; HCM.employees.id (HCM plan-optional relative to MES, §3P)
    machine_id          BIGINT REFERENCES "MES".mes_machines (id),
    UNIQUE (batch_id, seq)
);

CREATE INDEX IF NOT EXISTS idx_mes_batch_phases_batch ON "MES".mes_batch_phases (batch_id, seq);

CREATE TABLE IF NOT EXISTS "MES".mes_batch_parameter_readings (
    id                      BIGSERIAL PRIMARY KEY,
    batch_phase_id          BIGINT NOT NULL REFERENCES "MES".mes_batch_phases (id) ON DELETE CASCADE,
    process_parameter_id    BIGINT NOT NULL REFERENCES "MES".mes_process_parameters (id),
    value                   NUMERIC(18, 4) NOT NULL,
    recorded_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    recorded_by             BIGINT REFERENCES users (id),
    machine_id              BIGINT REFERENCES "MES".mes_machines (id) -- nullable; set when IoT-sourced (§3S, Phase 3)
);

CREATE INDEX IF NOT EXISTS idx_mes_batch_parameter_readings_phase ON "MES".mes_batch_parameter_readings (batch_phase_id);

CREATE TABLE IF NOT EXISTS "MES".mes_batch_relations (
    id                  BIGSERIAL PRIMARY KEY,
    parent_batch_id     BIGINT NOT NULL REFERENCES "MES".mes_batches (id),
    child_batch_id      BIGINT NOT NULL REFERENCES "MES".mes_batches (id),
    relation_type       VARCHAR(10) NOT NULL CHECK (relation_type IN ('split', 'merge')),
    qty                 NUMERIC(18, 4) NOT NULL,
    CHECK (parent_batch_id <> child_batch_id)
);

-- -----------------------------------------------------------------------------
-- §3H / §4. Serial Genealogy (assembly) — serial identity stays Inventory's
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_serial_links (
    id                      BIGSERIAL PRIMARY KEY,
    serial_id               BIGINT REFERENCES "INVENTORY".stock_serials (id),
    component_serial_id      BIGINT REFERENCES "INVENTORY".stock_serials (id),
    component_lot_id          BIGINT REFERENCES "INVENTORY".stock_batches (id),
    material_product_id         BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    order_id                      BIGINT NOT NULL REFERENCES "MES".mes_prod_order_hdrs (id),
    operation_ref                   BIGINT, -- mes_routing_ops.id
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (component_serial_id IS NOT NULL OR component_lot_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_mes_serial_links_order ON "MES".mes_serial_links (order_id);

-- -----------------------------------------------------------------------------
-- §3J / §4. Material Consumption & Production Output (common)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_material_consumptions (
    id                      BIGSERIAL PRIMARY KEY,
    order_id                BIGINT NOT NULL REFERENCES "MES".mes_prod_order_hdrs (id),
    operation_ref           BIGINT, -- mes_routing_ops.id or mes_batch_phases.id, depending on production_model
    material_product_id      BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    lot_id                     BIGINT REFERENCES "INVENTORY".stock_batches (id),
    serial_id                    BIGINT REFERENCES "INVENTORY".stock_serials (id),
    qty                             NUMERIC(18, 4) NOT NULL,
    uom_code                          VARCHAR(10),
    type                                VARCHAR(10) NOT NULL CHECK (type IN ('issue', 'return')),
    created_at                           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_mes_material_consumptions_order ON "MES".mes_material_consumptions (order_id);

CREATE TABLE IF NOT EXISTS "MES".mes_production_outputs (
    id                  BIGSERIAL PRIMARY KEY,
    order_id            BIGINT NOT NULL REFERENCES "MES".mes_prod_order_hdrs (id),
    operation_ref        BIGINT, -- mes_routing_ops.id or mes_batch_phases.id
    output_type            VARCHAR(15) NOT NULL CHECK (output_type IN ('finished', 'co_product', 'by_product', 'waste')),
    product_id                BIGINT NOT NULL REFERENCES "INVENTORY".products (id),
    qty                          NUMERIC(18, 4) NOT NULL,
    uom_code                       VARCHAR(10),
    lot_id                           BIGINT REFERENCES "INVENTORY".stock_batches (id),
    serial_id                         BIGINT REFERENCES "INVENTORY".stock_serials (id),
    reason_code                         VARCHAR(30), -- set when output_type = 'waste' (§3N)
    disposition                           VARCHAR(10) CHECK (disposition IN ('scrap', 'rework')),
    created_at                              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_mes_production_outputs_order ON "MES".mes_production_outputs (order_id);

-- -----------------------------------------------------------------------------
-- §3L / §4. Quality — samples, results, holds
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_qc_samples (
    id                  BIGSERIAL PRIMARY KEY,
    order_id            BIGINT REFERENCES "MES".mes_prod_order_hdrs (id),
    batch_phase_id      BIGINT REFERENCES "MES".mes_batch_phases (id),
    sample_number       VARCHAR(30) NOT NULL UNIQUE,
    taken_by            BIGINT NOT NULL REFERENCES users (id),
    taken_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (order_id IS NOT NULL OR batch_phase_id IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS "MES".mes_qc_results (
    id                  BIGSERIAL PRIMARY KEY,
    sample_id           BIGINT NOT NULL REFERENCES "MES".mes_qc_samples (id) ON DELETE CASCADE,
    characteristic_id   BIGINT NOT NULL REFERENCES "MES".mes_qc_characteristics (id),
    actual_value        NUMERIC(18, 4),
    result              VARCHAR(10) NOT NULL CHECK (result IN ('pass', 'fail', 'hold'))
);

CREATE TABLE IF NOT EXISTS "MES".mes_qc_holds (
    id              BIGSERIAL PRIMARY KEY,
    subject_type    VARCHAR(50) NOT NULL, -- informational polymorphic pointer, e.g. 'mes.mes_production_outputs'
    subject_id      BIGINT NOT NULL,
    reason          TEXT,
    status          VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'released')),
    released_by     BIGINT REFERENCES users (id),
    released_at     TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_mes_qc_holds_open ON "MES".mes_qc_holds (status) WHERE status = 'open';

-- -----------------------------------------------------------------------------
-- §3M / §4. Equipment Status & Downtime (Phase 2)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_equipment_status_logs (
    id          BIGSERIAL PRIMARY KEY,
    machine_id  BIGINT NOT NULL REFERENCES "MES".mes_machines (id),
    status      VARCHAR(20) NOT NULL,
    started_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    ended_at    TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_mes_equipment_status_logs_machine ON "MES".mes_equipment_status_logs (machine_id, started_at);

CREATE TABLE IF NOT EXISTS "MES".mes_downtime_events (
    id              BIGSERIAL PRIMARY KEY,
    machine_id      BIGINT REFERENCES "MES".mes_machines (id),
    work_center_id  BIGINT REFERENCES "MES".mes_work_centers (id),
    order_id        BIGINT REFERENCES "MES".mes_prod_order_hdrs (id),
    category        VARCHAR(10) NOT NULL CHECK (category IN ('planned', 'unplanned')),
    reason_code     VARCHAR(30) NOT NULL,
    started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    ended_at        TIMESTAMPTZ,
    CHECK (machine_id IS NOT NULL OR work_center_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_mes_downtime_events_open ON "MES".mes_downtime_events (machine_id) WHERE ended_at IS NULL;

-- -----------------------------------------------------------------------------
-- §3P / §4. Shift Handover (Phase 2) — no MES-owned shift model, HCM.shifts/
-- HCM.shift_assignments referenced informationally only
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_shift_handover_notes (
    id                      BIGSERIAL PRIMARY KEY,
    shift_assignment_id     BIGINT, -- informational; HCM.shift_assignments.id (HCM plan-optional relative to MES)
    summary                 VARCHAR(255),
    notes                   TEXT,
    created_by              BIGINT NOT NULL REFERENCES users (id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3U / §4. Digital Audit Trail — append-only, same convention as
-- SYSCONFIG.config_audit_logs / WNE.wrkflow_audit_logs / PP.pp_audit_logs
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "MES".mes_audit_logs (
    id                  BIGSERIAL PRIMARY KEY,
    subject_type        VARCHAR(50) NOT NULL,
    subject_id          BIGINT NOT NULL,
    action               VARCHAR(20) NOT NULL,
    actor_id               BIGINT NOT NULL REFERENCES users (id),
    before_snapshot          JSONB,
    after_snapshot             JSONB,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- NOTE — deliberately not created here: SYSCONFIG.config_snums (real serial
-- numbering for MES_MO_LASTID lives in the already-built SysConfig module;
-- this script fakes order_number values directly); CUSTOMFIELDS.field_defs
-- rows for mes_prod_order_hdrs/mes_batches (MES_SPECS.md §4 custom-fields
-- registration — a CustomFields-module concern, not this schema's own DDL).
-- =============================================================================

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
--
-- ASSUMES PP_SPECS.sql has already been run against the same tenant DB
-- (CLAUDE.md §5 sequences PP immediately before MES, exactly so this can be
-- true) — this seed reuses PP's INVENTORY.products (ASM-100 / SOLV-200 /
-- RM-STEEL-ROD / RM-FASTENER / CHEM-A / CHEM-B), PP.pp_boms, and
-- PP.pp_recipes rows by natural key rather than re-inserting them. If
-- PP_SPECS.sql has not been run, the INSERT...SELECT statements below that
-- join against those rows will simply insert nothing — run PP_SPECS.sql
-- first.
--
-- Scenario: the same two finished goods PP already planned — ASM-100 (Steel
-- Bracket Assembly, discrete) on Assembly Line A, and SOLV-200 (Industrial
-- Solvent, process) in the Refinery Mixing Area — deliberately covers both
-- production models per MES_SPECS.md §1 "one execution engine, two
-- production models". Order WO-2026-00125 (id 9001) is the same production
-- order PP's own seed already released into (PP_SPECS.sql's
-- pp_planned_orders.released_subject_id = 9001) — the two scripts' demo
-- data joins into one connected story. Field values (station code
-- "ASSEMBLY-03", batch number "B-2026-0031", temperature/pressure/RPM
-- readings, the shift-handover "Station 3 vibration" note) are pulled
-- straight from MES_SPECS.md's own source brief mockups (§3G/§3I/§3P) so
-- the seed reads as the same worked example the spec describes.
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com)
-- and that INVENTORY.warehouses has at least one row (inserted below if the
-- tenant hasn't already created one via Inventory's own seed/UI).
--
-- mes_serial_links and mes_batch_relations are intentionally seeded with
-- ZERO rows, not an oversight: ASM-100 is `tracking_mode = 'batch'`, not
-- `'serial'`, in PP's own product seed (INVENTORY_SPECS.md §3M — serial
-- links only apply to serial-tracked products), and this seed's one batch
-- (B-2026-0031) is neither split nor merged, so mes_batch_relations has
-- nothing to record. The sanity-check counts below will correctly show 0
-- for both.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 0. INVENTORY dependency: ensure one warehouse exists (PP's seed didn't
--    create one — Production Order needs a warehouse_id)
-- ---------------------------------------------------------------------------

INSERT INTO "INVENTORY".warehouses (code, name)
SELECT 'MAIN', 'Main Plant Warehouse'
WHERE NOT EXISTS (SELECT 1 FROM "INVENTORY".warehouses WHERE code = 'MAIN');

-- ---------------------------------------------------------------------------
-- 1. Equipment — Assembly Line A (id 201) mirrors the informational
--    resource_ref_id = 201 PP's own seed already used for its
--    'ASSEMBLY-LINE' resource group (PP_SPECS.sql §4); Mixers 101/102/103
--    likewise mirror PP's 'MIXING' resource group members — if those
--    informational pointers are ever made real, they already resolve.
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_work_centers (id, code, name, area_line, type) VALUES
    (201, 'ASSEMBLY-A', 'Assembly Line A', 'Assembly', 'discrete'),
    (301, 'REFINERY-MIX', 'Refinery Mixing Area', 'Refinery', 'process');
SELECT setval(pg_get_serial_sequence('"MES".mes_work_centers', 'id'), 301, true);

INSERT INTO "MES".mes_machines (id, work_center_id, code, name, status) VALUES
    (101, 301, 'MIXER-01', 'Mixer 01', 'running'),
    (102, 301, 'MIXER-02', 'Mixer 02', 'idle'),
    (103, 301, 'MIXER-03', 'Mixer 03', 'idle'),
    (401, 201, 'PRESS-01', 'Assembly Press 01', 'running');
SELECT setval(pg_get_serial_sequence('"MES".mes_machines', 'id'), 401, true);

INSERT INTO "MES".mes_stations (work_center_id, machine_id, code, name) VALUES
    (201, 401, 'ASSEMBLY-03', 'Assembly Station 3');

-- ---------------------------------------------------------------------------
-- 2. Routing for ASM-100: Cutting → Assembly → Testing → Packaging
--    (straight from MES_SPECS.md §3's own source-brief routing example)
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_routings (product_id, version, is_active)
SELECT p.id, 1, TRUE FROM "INVENTORY".products p WHERE p.sku = 'ASM-100';

INSERT INTO "MES".mes_routing_ops (routing_id, seq, op_code, op_name, work_center_id, setup_time_minutes, run_time_minutes, standard_output_qty)
SELECT r.id, v.seq, v.op_code, v.op_name, 201, v.setup, v.run, 100
FROM "MES".mes_routings r
JOIN "INVENTORY".products p ON p.id = r.product_id AND p.sku = 'ASM-100'
JOIN (VALUES
    (10, 'OP-10', 'Cutting', 15, 20),
    (20, 'OP-20', 'Assembly', 10, 25),
    (30, 'OP-30', 'Testing', 5, 10),
    (40, 'OP-40', 'Packaging', 5, 8)
) AS v (seq, op_code, op_name, setup, run) ON TRUE;

-- ---------------------------------------------------------------------------
-- 3. Process phases for SOLV-200's recipe: Mixing → Heating → Cooling
--    (straight from MES_SPECS.md §3's own source-brief batch example), with
--    Mixing's parameters matching the source brief's own parameter table
--    (Temperature 80/78/82°C, Pressure 5/4.5/5.5 bar, RPM 1200/1100/1300)
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_process_phases (recipe_id, seq, phase_name, work_center_id, standard_duration_minutes)
SELECT r.id, v.seq, v.phase_name, 301, v.duration
FROM "PP".pp_recipes r
JOIN "INVENTORY".products p ON p.id = r.product_id AND p.sku = 'SOLV-200' AND r.is_active
JOIN (VALUES (10, 'Mixing', 45), (20, 'Heating', 60), (30, 'Cooling', 30)) AS v (seq, phase_name, duration) ON TRUE;

INSERT INTO "MES".mes_process_parameters (process_phase_id, parameter_code, target_value, min_value, max_value, uom_code)
SELECT ph.id, v.code, v.target, v.min, v.max, v.uom
FROM "MES".mes_process_phases ph
JOIN "PP".pp_recipes r ON r.id = ph.recipe_id
JOIN "INVENTORY".products p ON p.id = r.product_id AND p.sku = 'SOLV-200'
JOIN (VALUES ('TEMPERATURE', 80, 78, 82, '°C'), ('PRESSURE', 5, 4.5, 5.5, 'bar'), ('RPM', 1200, 1100, 1300, 'rpm')) AS v (code, target, min, max, uom) ON TRUE
WHERE ph.phase_name = 'Mixing';

-- ---------------------------------------------------------------------------
-- 4. QC inspection plan for ASM-100 finished goods — Bolt Torque
--    characteristic matches the source brief's own work-instruction example
--    ("Torque bolts to 12 ± 1 Nm")
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_qc_inspection_plans (product_id, name)
SELECT p.id, 'Bracket Assembly Final QC' FROM "INVENTORY".products p WHERE p.sku = 'ASM-100';

INSERT INTO "MES".mes_qc_characteristics (plan_id, characteristic_name, spec_type, target_value, min_value, max_value, uom_code)
SELECT plan.id, 'Bolt Torque', 'numeric', 12, 11, 13, 'Nm'
FROM "MES".mes_qc_inspection_plans plan
JOIN "INVENTORY".products p ON p.id = plan.product_id AND p.sku = 'ASM-100';

-- ---------------------------------------------------------------------------
-- 5. Production Order WO-2026-00125 (id 9001) — Assembly, ASM-100, qty 500.
--    Reuses PP's active BOM and this exact order routing; source_id links
--    back to PP's own released planned order (PLN-2026-000001), and this
--    id (9001) is the same id PP's pp_planned_orders.released_subject_id
--    already points at — the two seed scripts describe the same order.
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_prod_order_hdrs (
    id, order_number, product_id, production_model, bom_id, routing_id, qty, uom_code,
    planned_start, planned_end, actual_start, priority, warehouse_id, line_area, status, source_type, source_id
)
SELECT
    9001, 'WO-2026-00125', p.id, 'assembly', b.id, rt.id, 500, 'EA',
    TIMESTAMPTZ '2026-09-22 08:00:00+07', TIMESTAMPTZ '2026-09-24 17:00:00+07',
    TIMESTAMPTZ '2026-09-22 08:15:00+07', 'normal', wh.id, 'Assembly Line A', 'in_progress',
    'pp.pp_planned_orders', po.id
FROM "INVENTORY".products p
JOIN "PP".pp_boms b ON b.product_id = p.id AND b.is_active
JOIN "MES".mes_routings rt ON rt.product_id = p.id AND rt.is_active
JOIN "INVENTORY".warehouses wh ON wh.code = 'MAIN'
LEFT JOIN "PP".pp_planned_orders po ON po.plan_number = 'PLN-2026-000001'
WHERE p.sku = 'ASM-100';
SELECT setval(pg_get_serial_sequence('"MES".mes_prod_order_hdrs', 'id'), GREATEST(9001, (SELECT COALESCE(MAX(id), 1) FROM "MES".mes_prod_order_hdrs)), true);

-- Second order: WO-2026-00126 — Process, SOLV-200, qty 10,000 kg (source
-- brief's own second production-order example, qty/uom lifted directly)
INSERT INTO "MES".mes_prod_order_hdrs (
    order_number, product_id, production_model, recipe_id, qty, uom_code,
    planned_start, actual_start, priority, warehouse_id, line_area, status, source_type, source_id
)
SELECT
    'WO-2026-00126', p.id, 'process', r.id, 10000, 'KG',
    TIMESTAMPTZ '2026-09-20 06:00:00+07', TIMESTAMPTZ '2026-09-20 06:10:00+07',
    'normal', wh.id, 'Refinery Mixing Area', 'in_progress', 'pp.pp_planned_orders', po.id
FROM "INVENTORY".products p
JOIN "PP".pp_recipes r ON r.product_id = p.id AND r.is_active
JOIN "INVENTORY".warehouses wh ON wh.code = 'MAIN'
LEFT JOIN "PP".pp_planned_orders po ON po.plan_number = 'PLN-2026-000002'
WHERE p.sku = 'SOLV-200';

-- ---------------------------------------------------------------------------
-- 6. Assembly execution: Cutting/Assembly ops completed, 74 units done, 2
--    rejected (source brief's own Shop Floor UI mockup: "Target 100 / Done
--    74 / Reject 2") — components consumed in BOM ratio (2 rod + 1 fastener
--    per unit) for the 74 completed units
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_material_consumptions (order_id, operation_ref, material_product_id, qty, uom_code, type)
SELECT wo.id, ro.id, comp.id, v.qty, 'EA', 'issue'
FROM "MES".mes_prod_order_hdrs wo
JOIN "MES".mes_routing_ops ro ON ro.routing_id = wo.routing_id AND ro.op_code = 'OP-20'
JOIN (VALUES ('RM-STEEL-ROD', 148), ('RM-FASTENER', 74)) AS v (sku, qty) ON TRUE
JOIN "INVENTORY".products comp ON comp.sku = v.sku
WHERE wo.id = 9001;

INSERT INTO "MES".mes_production_outputs (order_id, operation_ref, output_type, product_id, qty, uom_code)
SELECT wo.id, ro.id, 'finished', wo.product_id, 74, 'EA'
FROM "MES".mes_prod_order_hdrs wo
JOIN "MES".mes_routing_ops ro ON ro.routing_id = wo.routing_id AND ro.op_code = 'OP-30'
WHERE wo.id = 9001;

INSERT INTO "MES".mes_production_outputs (order_id, operation_ref, output_type, product_id, qty, uom_code, reason_code, disposition)
SELECT wo.id, ro.id, 'waste', wo.product_id, 2, 'EA', 'bolt_torque_out_of_spec', 'scrap'
FROM "MES".mes_prod_order_hdrs wo
JOIN "MES".mes_routing_ops ro ON ro.routing_id = wo.routing_id AND ro.op_code = 'OP-30'
WHERE wo.id = 9001;

-- QC sample on the rejected pieces: Bolt Torque 9.5 Nm, below the 11 Nm min
INSERT INTO "MES".mes_qc_samples (order_id, sample_number, taken_by, taken_at)
SELECT 9001, 'QC-2026-0007', u.id, TIMESTAMPTZ '2026-09-23 13:42:00+07'
FROM users u WHERE u.email = 'staff@nusaevo.com';

INSERT INTO "MES".mes_qc_results (sample_id, characteristic_id, actual_value, result)
SELECT s.id, c.id, 9.5, 'fail'
FROM "MES".mes_qc_samples s
JOIN "MES".mes_qc_inspection_plans plan ON TRUE
JOIN "MES".mes_qc_characteristics c ON c.plan_id = plan.id AND c.characteristic_name = 'Bolt Torque'
JOIN "INVENTORY".products p ON p.id = plan.product_id AND p.sku = 'ASM-100'
WHERE s.sample_number = 'QC-2026-0007';

INSERT INTO "MES".mes_qc_holds (subject_type, subject_id, reason, status, released_by, released_at)
SELECT 'mes.mes_production_outputs', o.id, 'Bolt torque 9.5 Nm below spec (11 Nm min) on 2 units.', 'released', u.id, TIMESTAMPTZ '2026-09-23 14:05:00+07'
FROM "MES".mes_production_outputs o
JOIN users u ON u.email = 'admin@nusaevo.com'
WHERE o.order_id = 9001 AND o.output_type = 'waste';

-- ---------------------------------------------------------------------------
-- 7. Process execution: Batch B-2026-0031 (source brief's own Batch UI
--    mockup name), Mixing phase running with the exact readings the
--    mockup shows — Temperature 79.8°C, Pressure 5.1 bar, RPM 1,198,
--    elapsed ~32 minutes
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_batches (order_id, batch_number, recipe_id, status, planned_qty)
SELECT wo.id, 'B-2026-0031', r.id, 'running', r.batch_size
FROM "MES".mes_prod_order_hdrs wo
JOIN "PP".pp_recipes r ON r.id = wo.recipe_id
WHERE wo.order_number = 'WO-2026-00126';

-- Resolved ingredients: planned_qty (1000) = recipe.batch_size (1000), so
-- PpService::scaleRecipe() scale factor is 1 — resolved qty equals the
-- recipe's own ingredient qty (PP_SPECS.sql seed §3)
INSERT INTO "MES".mes_batch_ingredients (batch_id, raw_material_product_id, resolved_qty, uom_code)
SELECT b.id, ing.raw_material_product_id, ing.qty_per_batch, ing.uom_code
FROM "MES".mes_batches b
JOIN "PP".pp_recipe_ingredients ing ON ing.recipe_id = b.recipe_id
WHERE b.batch_number = 'B-2026-0031';

INSERT INTO "MES".mes_batch_phases (batch_id, process_phase_id, seq, status, start_at, machine_id)
SELECT b.id, ph.id, ph.seq, 'running', now() - interval '32 minutes 14 seconds', 101
FROM "MES".mes_batches b
JOIN "MES".mes_process_phases ph ON ph.recipe_id = b.recipe_id AND ph.phase_name = 'Mixing'
WHERE b.batch_number = 'B-2026-0031';

INSERT INTO "MES".mes_batch_parameter_readings (batch_phase_id, process_parameter_id, value, recorded_at, recorded_by, machine_id)
SELECT bp.id, param.id, v.value, now(), u.id, 101
FROM "MES".mes_batch_phases bp
JOIN "MES".mes_process_parameters param ON param.process_phase_id = bp.process_phase_id
JOIN (VALUES ('TEMPERATURE', 79.8), ('PRESSURE', 5.1), ('RPM', 1198)) AS v (code, value) ON v.code = param.parameter_code
JOIN users u ON u.email = 'staff@nusaevo.com'
JOIN "MES".mes_batches b ON b.id = bp.batch_id AND b.batch_number = 'B-2026-0031';

-- ---------------------------------------------------------------------------
-- 8. Production Event Ledger — representative append-only trail for both
--    orders (a real system would have one row per action; this seed shows
--    the shape, not an exhaustive replay)
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_prod_events (order_id, event_type, payload, occurred_at, user_id)
SELECT wo.id, v.event_type, v.payload::jsonb, v.occurred_at, u.id
FROM "MES".mes_prod_order_hdrs wo, users u,
(VALUES
    (9001, 'order_released', '{"qty": 500}', TIMESTAMPTZ '2026-09-22 08:00:00+07'),
    (9001, 'material_issued', '{"component": "RM-STEEL-ROD", "qty": 148}', TIMESTAMPTZ '2026-09-23 09:00:00+07'),
    (9001, 'material_issued', '{"component": "RM-FASTENER", "qty": 74}', TIMESTAMPTZ '2026-09-23 09:00:00+07'),
    (9001, 'operation_completed', '{"op_code": "OP-20", "qty": 74}', TIMESTAMPTZ '2026-09-23 13:30:00+07'),
    (9001, 'qc_sample_taken', '{"sample_number": "QC-2026-0007"}', TIMESTAMPTZ '2026-09-23 13:42:00+07'),
    (9001, 'scrap_recorded', '{"qty": 2, "reason_code": "bolt_torque_out_of_spec"}', TIMESTAMPTZ '2026-09-23 14:00:00+07'),
    (9001, 'output_produced', '{"output_type": "finished", "qty": 74}', TIMESTAMPTZ '2026-09-23 14:10:00+07')
) AS v (order_num, event_type, payload, occurred_at)
WHERE wo.id = v.order_num AND u.email = 'staff@nusaevo.com';

INSERT INTO "MES".mes_prod_events (order_id, batch_id, event_type, payload, occurred_at, user_id)
SELECT wo.id, b.id, v.event_type, v.payload::jsonb, v.occurred_at, u.id
FROM "MES".mes_prod_order_hdrs wo
JOIN "MES".mes_batches b ON b.order_id = wo.id
JOIN users u ON u.email = 'staff@nusaevo.com',
(VALUES
    ('order_released', '{"qty": 10000}', TIMESTAMPTZ '2026-09-20 06:00:00+07'),
    ('operation_started', '{"phase": "Mixing"}', TIMESTAMPTZ '2026-09-20 06:10:00+07'),
    ('parameter_recorded', '{"phase": "Mixing", "temperature": 79.8}', now())
) AS v (event_type, payload, occurred_at)
WHERE wo.order_number = 'WO-2026-00126';

-- ---------------------------------------------------------------------------
-- 9. Equipment status & downtime — PRESS-01's vibration issue, the same
--    incident the shift-handover note below refers back to
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_equipment_status_logs (machine_id, status, started_at, ended_at)
VALUES (401, 'running', TIMESTAMPTZ '2026-09-22 08:00:00+07', NULL),
       (101, 'running', TIMESTAMPTZ '2026-09-20 06:00:00+07', NULL);

INSERT INTO "MES".mes_downtime_events (machine_id, order_id, category, reason_code, started_at, ended_at)
SELECT 401, 9001, 'unplanned', 'mechanical', TIMESTAMPTZ '2026-09-23 11:20:00+07', TIMESTAMPTZ '2026-09-23 11:35:00+07';

-- ---------------------------------------------------------------------------
-- 10. Shift handover — text lifted directly from the source brief's own
--     Shift Handover example (§15)
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_shift_handover_notes (summary, notes, created_by)
SELECT 'Shift A → Shift B',
       'Current WO: WO-2026-00125. Produced: 74. Remaining: 26 (of 100 shift target). Machine issue: Station 3 vibration detected. QC: Last sample passed 13:42.',
       u.id
FROM users u WHERE u.email = 'staff@nusaevo.com';

-- ---------------------------------------------------------------------------
-- 11. Audit log: Mixing phase Temperature target adjusted 78 → 80 before
--     this seed ran (illustrative before/after snapshot, same style as
--     PP_SPECS.sql's own audit-log demo entry)
-- ---------------------------------------------------------------------------

INSERT INTO "MES".mes_audit_logs (subject_type, subject_id, action, actor_id, before_snapshot, after_snapshot)
SELECT 'mes.mes_process_parameters', param.id, 'update', u.id,
       jsonb_build_object('target_value', 78), jsonb_build_object('target_value', 80)
FROM "MES".mes_process_parameters param
JOIN "MES".mes_process_phases ph ON ph.id = param.process_phase_id AND ph.phase_name = 'Mixing'
JOIN users u ON u.email = 'admin@nusaevo.com'
WHERE param.parameter_code = 'TEMPERATURE';

COMMIT;

-- =============================================================================
-- Sanity check counts
-- =============================================================================
SELECT 'mes_work_centers' AS table_name, COUNT(*) FROM "MES".mes_work_centers
UNION ALL SELECT 'mes_machines', COUNT(*) FROM "MES".mes_machines
UNION ALL SELECT 'mes_stations', COUNT(*) FROM "MES".mes_stations
UNION ALL SELECT 'mes_routings', COUNT(*) FROM "MES".mes_routings
UNION ALL SELECT 'mes_routing_ops', COUNT(*) FROM "MES".mes_routing_ops
UNION ALL SELECT 'mes_process_phases', COUNT(*) FROM "MES".mes_process_phases
UNION ALL SELECT 'mes_process_parameters', COUNT(*) FROM "MES".mes_process_parameters
UNION ALL SELECT 'mes_qc_inspection_plans', COUNT(*) FROM "MES".mes_qc_inspection_plans
UNION ALL SELECT 'mes_qc_characteristics', COUNT(*) FROM "MES".mes_qc_characteristics
UNION ALL SELECT 'mes_prod_order_hdrs', COUNT(*) FROM "MES".mes_prod_order_hdrs
UNION ALL SELECT 'mes_prod_events', COUNT(*) FROM "MES".mes_prod_events
UNION ALL SELECT 'mes_batches', COUNT(*) FROM "MES".mes_batches
UNION ALL SELECT 'mes_batch_ingredients', COUNT(*) FROM "MES".mes_batch_ingredients
UNION ALL SELECT 'mes_batch_phases', COUNT(*) FROM "MES".mes_batch_phases
UNION ALL SELECT 'mes_batch_parameter_readings', COUNT(*) FROM "MES".mes_batch_parameter_readings
UNION ALL SELECT 'mes_batch_relations', COUNT(*) FROM "MES".mes_batch_relations
UNION ALL SELECT 'mes_serial_links', COUNT(*) FROM "MES".mes_serial_links
UNION ALL SELECT 'mes_material_consumptions', COUNT(*) FROM "MES".mes_material_consumptions
UNION ALL SELECT 'mes_production_outputs', COUNT(*) FROM "MES".mes_production_outputs
UNION ALL SELECT 'mes_qc_samples', COUNT(*) FROM "MES".mes_qc_samples
UNION ALL SELECT 'mes_qc_results', COUNT(*) FROM "MES".mes_qc_results
UNION ALL SELECT 'mes_qc_holds', COUNT(*) FROM "MES".mes_qc_holds
UNION ALL SELECT 'mes_equipment_status_logs', COUNT(*) FROM "MES".mes_equipment_status_logs
UNION ALL SELECT 'mes_downtime_events', COUNT(*) FROM "MES".mes_downtime_events
UNION ALL SELECT 'mes_shift_handover_notes', COUNT(*) FROM "MES".mes_shift_handover_notes
UNION ALL SELECT 'mes_audit_logs', COUNT(*) FROM "MES".mes_audit_logs;

-- Demonstrates the Scrap & Yield calculation §3N describes, for WO-2026-00125:
--   good_output_qty / (good_output_qty + scrap_qty)
SELECT wo.order_number,
       SUM(o.qty) FILTER (WHERE o.output_type = 'finished')                                   AS good_output_qty,
       SUM(o.qty) FILTER (WHERE o.output_type = 'waste')                                      AS scrap_qty,
       ROUND(
           SUM(o.qty) FILTER (WHERE o.output_type = 'finished') /
           NULLIF(SUM(o.qty) FILTER (WHERE o.output_type IN ('finished', 'waste')), 0) * 100, 1
       ) AS yield_pct
FROM "MES".mes_prod_order_hdrs wo
JOIN "MES".mes_production_outputs o ON o.order_id = wo.id
WHERE wo.id = 9001
GROUP BY wo.order_number;

-- Demonstrates Material Traceability §3K's forward-trace shape: everything
-- consumed against WO-2026-00125, joined to what it produced
SELECT 'consumed' AS direction, comp.sku, mc.qty, mc.type
FROM "MES".mes_material_consumptions mc
JOIN "INVENTORY".products comp ON comp.id = mc.material_product_id
WHERE mc.order_id = 9001
UNION ALL
SELECT 'produced', p.sku, o.qty, o.output_type
FROM "MES".mes_production_outputs o
JOIN "INVENTORY".products p ON p.id = o.product_id
WHERE o.order_id = 9001;

-- Demonstrates the PP → MES release join (§3A/§5 boundary): PP's planned
-- order and MES's production order describing the same released work
SELECT po.plan_number, po.status AS pp_status, wo.order_number, wo.status AS mes_status, wo.qty
FROM "PP".pp_planned_orders po
JOIN "MES".mes_prod_order_hdrs wo ON wo.source_type = 'pp.pp_planned_orders' AND wo.source_id = po.id
WHERE po.plan_number = 'PLN-2026-000001';
