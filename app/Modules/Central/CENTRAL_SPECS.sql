-- =============================================================================
-- CENTRAL module — schema + example seed data
-- Source spec: app/Modules/CENTRAL_SPECS.md (see that file for full rationale)
-- Target DB:   Central DB (`nusaevo`) — PostgreSQL. NOT a tenant DB.
--
-- Per CENTRAL_SPECS.md §4: this database has exactly one job, so — unlike a tenant
-- DB (SYSCONFIG./WNE./CRM./... schemas, CLAUDE.md §7A) — there is no per-module
-- schema separation here. All tables live in the default `public` schema.
--
-- `tenants` and `tenant_user_lookups` already exist (created by the stancl/tenancy
-- migrations + 2026_07_17_000002_add_plan_to_tenants_table.php) and are EXTENDED
-- below rather than redefined. Every other table is new, prefixed `central_` for
-- clarity against those two pre-existing ones (§4).
--
-- This file is a reference/planning script, not a Laravel migration — when this
-- module actually gets built (CLAUDE.md §5 build order), port each section below
-- into a proper `database/migrations/*.php` file (see §3B build-order note in the
-- spec: CENTRAL is step 0, before SYSCONFIG can exist inside any tenant DB).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- -----------------------------------------------------------------------------
-- §3D. Subscription Plan Catalog (Master)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS central_plans (
    code            VARCHAR(50) PRIMARY KEY,           -- e.g. LEGAL_STARTER, LEGAL_PRO
    name            VARCHAR(150) NOT NULL,
    description     TEXT,
    price_monthly   NUMERIC(14, 2) NOT NULL DEFAULT 0,
    price_annual    NUMERIC(14, 2) NOT NULL DEFAULT 0,
    currency        VARCHAR(3) NOT NULL DEFAULT 'IDR',
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,      -- deactivate blocks new assignment only (§3D rules)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- pivot: default module set a plan entitles, before any à la carte add-ons (§3C)
CREATE TABLE IF NOT EXISTS central_plan_modules (
    plan_code       VARCHAR(50) NOT NULL REFERENCES central_plans (code),
    module_code     VARCHAR(50) NOT NULL,               -- same code space as SYSCONFIG.tenant_modules.module_code
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (plan_code, module_code)
);

-- -----------------------------------------------------------------------------
-- Platform admin accounts (§3A/§3B) — separate from any tenant's own users table
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS central_admin_users (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3B. Tenant Registry & Provisioning — EXTEND the existing `tenants` table
-- (id is a string PK, e.g. '001' → tenant DB `tenant_001`; see config/tenancy.php)
-- -----------------------------------------------------------------------------

ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS legal_name          VARCHAR(255),
    ADD COLUMN IF NOT EXISTS contact_name         VARCHAR(150),
    ADD COLUMN IF NOT EXISTS contact_email        VARCHAR(255),
    ADD COLUMN IF NOT EXISTS contact_phone        VARCHAR(50),
    ADD COLUMN IF NOT EXISTS billing_address      TEXT,
    -- `plan` (existing string column) stays as the app's current lookup key into
    -- config/tenant_modules.php; `plan_code` is the new FK into central_plans and
    -- is the one §3C's entitlement resolution should read going forward.
    ADD COLUMN IF NOT EXISTS plan_code            VARCHAR(50) REFERENCES central_plans (code),
    ADD COLUMN IF NOT EXISTS provisioning_status  VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS access_status        VARCHAR(20) NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS tenant_db_name       VARCHAR(100),
    ADD COLUMN IF NOT EXISTS provisioned_at       TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS notes                TEXT;

ALTER TABLE tenants
    DROP CONSTRAINT IF EXISTS tenants_provisioning_status_check,
    ADD CONSTRAINT tenants_provisioning_status_check
        CHECK (provisioning_status IN ('pending', 'provisioning', 'provisioned', 'active'));

ALTER TABLE tenants
    DROP CONSTRAINT IF EXISTS tenants_access_status_check,
    ADD CONSTRAINT tenants_access_status_check
        CHECK (access_status IN ('active', 'read_only'));

-- `tenant_user_lookups` (existing, unchanged) — email -> tenant_id, CLAUDE.md §4. No changes here;
-- shown below as a reference only (defined by database/migrations/2026_07_13_000001_create_tenant_user_lookups_table.php,
-- constraint later widened by 2026_07_17_000001_multi_tenant_membership.php to allow one email in many tenants).
--
-- CREATE TABLE tenant_user_lookups (
--     id           BIGSERIAL PRIMARY KEY,
--     email        VARCHAR(255) NOT NULL,
--     tenant_id    VARCHAR(255) NOT NULL,          -- no FK: stancl/tenancy's `tenants.id` predates this app's own tables
--     created_at   TIMESTAMPTZ,
--     updated_at   TIMESTAMPTZ,
--     UNIQUE (email, tenant_id)
-- );
-- CREATE INDEX ON tenant_user_lookups (tenant_id);

-- -----------------------------------------------------------------------------
-- §3C. Plan & Module Entitlement Configuration — à la carte add-ons
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS central_tenant_addons (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       VARCHAR(255) NOT NULL REFERENCES tenants (id),
    module_code     VARCHAR(50) NOT NULL,
    added_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    price_override  NUMERIC(14, 2),                     -- nullable: negotiated rate
    status          VARCHAR(20) NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active', 'removed')), -- removal is a status flip, never a delete
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_central_tenant_addons_tenant ON central_tenant_addons (tenant_id, status);

-- -----------------------------------------------------------------------------
-- Future Version (§2 — explicitly deferred, NOT built in MVP; kept commented for
-- forward reference, same table SYSCONFIG_SPECS.md's own Future Version section
-- already points at as `central.tenant_module_grants`). Do not uncomment until
-- there's a real commercial need for per-tenant overrides beyond plan+add-ons.
-- -----------------------------------------------------------------------------
-- CREATE TABLE central_tenant_module_grants (
--     id              BIGSERIAL PRIMARY KEY,
--     tenant_id       VARCHAR(255) NOT NULL REFERENCES tenants (id),
--     module_code     VARCHAR(50) NOT NULL,
--     reason          VARCHAR(255),
--     granted_by      BIGINT REFERENCES central_admin_users (id),
--     expires_at      TIMESTAMPTZ,                      -- nullable: time-boxed trial
--     status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked')),
--     created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
-- );

-- -----------------------------------------------------------------------------
-- §3E. Billing / Invoice Engine
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS central_invoices (
    id                     BIGSERIAL PRIMARY KEY,
    uuid                   UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing (§3H tenant portal)
    tenant_id              VARCHAR(255) NOT NULL REFERENCES tenants (id),
    billing_period_start   DATE NOT NULL,
    billing_period_end     DATE NOT NULL,
    plan_code              VARCHAR(50) NOT NULL,        -- snapshotted at issue time; NOT an FK (must survive plan edits/deletes)
    status                 VARCHAR(20) NOT NULL DEFAULT 'draft'
                               CHECK (status IN ('draft', 'issued', 'payment_submitted', 'paid', 'overdue', 'void')),
    amount_total           NUMERIC(14, 2) NOT NULL DEFAULT 0,
    currency               VARCHAR(3) NOT NULL DEFAULT 'IDR',
    due_date               DATE NOT NULL,
    issued_at              TIMESTAMPTZ,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- idempotency (§5): re-running invoice generation never double-bills a period
    UNIQUE (tenant_id, billing_period_start, billing_period_end)
);

CREATE INDEX IF NOT EXISTS idx_central_invoices_tenant ON central_invoices (tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_central_invoices_due ON central_invoices (due_date) WHERE status IN ('issued', 'overdue');

CREATE TABLE IF NOT EXISTS central_invoice_lines (
    id              BIGSERIAL PRIMARY KEY,
    invoice_id      BIGINT NOT NULL REFERENCES central_invoices (id) ON DELETE CASCADE,
    description     VARCHAR(255) NOT NULL,               -- e.g. "Plan: LEGAL_STARTER" / "Add-on: PERFORMANCE"
    amount          NUMERIC(14, 2) NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_central_invoice_lines_invoice ON central_invoice_lines (invoice_id);

-- -----------------------------------------------------------------------------
-- §3F. Payment Capture & Confirmation (incl. Receipt)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS central_payments (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing (§3H tenant portal)
    invoice_id          BIGINT NOT NULL REFERENCES central_invoices (id),
    tenant_id           VARCHAR(255) NOT NULL REFERENCES tenants (id),
    amount              NUMERIC(14, 2) NOT NULL,
    method              VARCHAR(30) NOT NULL DEFAULT 'bank_transfer'
                            CHECK (method IN ('bank_transfer')), -- reserved values added when a Future Version gateway ships
    receipt_object_key  VARCHAR(500) NOT NULL,           -- R2 object key, see §4 central/tenants/{id}/receipts/...
    status              VARCHAR(20) NOT NULL DEFAULT 'pending_review'
                            CHECK (status IN ('pending_review', 'confirmed', 'rejected')),
    submitted_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    reviewed_by         BIGINT REFERENCES central_admin_users (id),
    reviewed_at         TIMESTAMPTZ,
    rejection_reason    VARCHAR(500),                    -- required (app-enforced) when status = rejected
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_central_payments_invoice ON central_payments (invoice_id);
CREATE INDEX IF NOT EXISTS idx_central_payments_tenant ON central_payments (tenant_id, status);

-- -----------------------------------------------------------------------------
-- §3G. Dunning Engine — Reminders & Soft Cutoff
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS central_dunning_policies (
    id                      BIGSERIAL PRIMARY KEY,
    scope_type              VARCHAR(20) NOT NULL
                                CHECK (scope_type IN ('platform_default', 'plan', 'tenant')),
    scope_id                VARCHAR(255),                -- NULL for platform_default; plan_code or tenant_id otherwise
    reminder_offsets_days   JSONB NOT NULL DEFAULT '[-7, -3, -1, 3, 7]', -- negative = before due, positive = past due
    cutoff_days_after_due   INTEGER NOT NULL DEFAULT 14,
    cutoff_action           VARCHAR(20) NOT NULL DEFAULT 'read_only'
                                CHECK (cutoff_action IN ('read_only')), -- only value in MVP (§3G)
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- most-specific-wins resolution needs at most one row per scope. Postgres treats
    -- NULL scope_id values as distinct for uniqueness purposes, so this only truly
    -- guards the 'plan'/'tenant' rows; enforce "one platform_default row" in the
    -- service layer (or add a partial unique index on scope_type = 'platform_default').
    UNIQUE (scope_type, scope_id)
);

-- append-only + functional: existence of a (tenant, invoice, offset) row prevents a duplicate send
CREATE TABLE IF NOT EXISTS central_dunning_log (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       VARCHAR(255) NOT NULL REFERENCES tenants (id),
    invoice_id      BIGINT NOT NULL REFERENCES central_invoices (id),
    offset_days     INTEGER NOT NULL,                    -- the configured offset that fired
    channel         VARCHAR(20) NOT NULL DEFAULT 'email', -- MVP's only channel (§5 — why this doesn't reuse WNE)
    sent_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, invoice_id, offset_days)
);

CREATE INDEX IF NOT EXISTS idx_central_dunning_log_tenant ON central_dunning_log (tenant_id);

-- -----------------------------------------------------------------------------
-- §3I. Central Audit Log — append-only, no update/delete at the app layer
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS central_audit_logs (
    id              BIGSERIAL PRIMARY KEY,
    action          VARCHAR(40) NOT NULL CHECK (action IN (
                        'tenant_registered', 'tenant_provisioned', 'plan_changed',
                        'addon_added', 'addon_removed', 'invoice_issued', 'invoice_voided',
                        'payment_submitted', 'payment_confirmed', 'payment_rejected',
                        'access_status_changed', 'dunning_policy_changed'
                    )),
    actor_type      VARCHAR(10) NOT NULL DEFAULT 'admin' CHECK (actor_type IN ('admin', 'system')),
    actor_id        BIGINT REFERENCES central_admin_users (id), -- NULL when actor_type = 'system'
    entity_type     VARCHAR(50) NOT NULL,                 -- e.g. 'tenant', 'central_invoices', 'central_payments'
    entity_id       VARCHAR(255) NOT NULL,
    before_snapshot JSONB,
    after_snapshot  JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_central_audit_logs_entity ON central_audit_logs (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_central_audit_logs_created ON central_audit_logs (created_at);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Mirrors the two demo tenants already created by database/seeders/DatabaseSeeder.php
-- ('001' Nusaevo / plan=internal, '002' Demo Legal / plan=legal) so this script is
-- runnable as-is against a freshly migrated+seeded dev DB. Adjust ids/emails for
-- your own environment before running anywhere else.
-- =============================================================================

BEGIN;

-- §3D. Plan catalog
INSERT INTO central_plans (code, name, description, price_monthly, price_annual, currency, is_active) VALUES
    ('INTERNAL',        'Internal (Nusaevo)',    'Not sold — Nusaevo''s own operational tenant.', 0,        0,         'IDR', TRUE),
    ('LEGAL_STARTER',   'Legal Starter',         'Core + Legal vertical, single-office firms.',   1500000,  15000000,  'IDR', TRUE),
    ('LEGAL_PRO',       'Legal Pro',             'Legal Starter + Accounting + Purchase.',        3500000,  35000000,  'IDR', TRUE),
    ('LEGAL_ENTERPRISE','Legal Enterprise',      'Full platform, multi-branch firms.',            7500000,  75000000,  'IDR', TRUE)
ON CONFLICT (code) DO NOTHING;

-- §3D. Plan -> module entitlement (mirrors config/tenant_modules.php's 'legal'/'full' tiers,
-- WNE per the current post-merge schema name, CLAUDE.md §4)
INSERT INTO central_plan_modules (plan_code, module_code) VALUES
    ('INTERNAL', 'INVENTORY'), ('INTERNAL', 'PROJECTS'), ('INTERNAL', 'DESIGN_SYSTEM'),

    ('LEGAL_STARTER', 'INVENTORY'), ('LEGAL_STARTER', 'LEGAL'), ('LEGAL_STARTER', 'CRM'),
    ('LEGAL_STARTER', 'SCHEDULE'), ('LEGAL_STARTER', 'WNE'), ('LEGAL_STARTER', 'DESIGN_SYSTEM'),

    ('LEGAL_PRO', 'INVENTORY'), ('LEGAL_PRO', 'LEGAL'), ('LEGAL_PRO', 'CRM'),
    ('LEGAL_PRO', 'SCHEDULE'), ('LEGAL_PRO', 'WNE'), ('LEGAL_PRO', 'ACCOUNTING'),
    ('LEGAL_PRO', 'PURCHASE'), ('LEGAL_PRO', 'DESIGN_SYSTEM'),

    ('LEGAL_ENTERPRISE', 'INVENTORY'), ('LEGAL_ENTERPRISE', 'LEGAL'), ('LEGAL_ENTERPRISE', 'CRM'),
    ('LEGAL_ENTERPRISE', 'SCHEDULE'), ('LEGAL_ENTERPRISE', 'WNE'), ('LEGAL_ENTERPRISE', 'ACCOUNTING'),
    ('LEGAL_ENTERPRISE', 'PURCHASE'), ('LEGAL_ENTERPRISE', 'SALES'), ('LEGAL_ENTERPRISE', 'HCM'),
    ('LEGAL_ENTERPRISE', 'PAYROLL'), ('LEGAL_ENTERPRISE', 'DESIGN_SYSTEM')
ON CONFLICT DO NOTHING;

-- Platform admin (§3A) — password is a placeholder bcrypt hash, replace before real use
INSERT INTO central_admin_users (name, email, password, is_active) VALUES
    ('Simon', 'simon@nusaevo.com', '$2y$10$examplehashdonotusexamplehashdonotuse', TRUE)
ON CONFLICT (email) DO NOTHING;

-- §3G. Platform-default dunning policy (only one row needs scope_type = platform_default)
INSERT INTO central_dunning_policies (scope_type, scope_id, reminder_offsets_days, cutoff_days_after_due, cutoff_action)
VALUES ('platform_default', NULL, '[-7, -3, -1, 3, 7]', 14, 'read_only')
ON CONFLICT (scope_type, scope_id) DO NOTHING;

-- Example tenant-specific dunning override: a strategic early client gets a longer cutoff window
-- (§3G/§5 marketability note — "configurable dunning windows ... without any code change")
INSERT INTO central_dunning_policies (scope_type, scope_id, reminder_offsets_days, cutoff_days_after_due, cutoff_action)
VALUES ('tenant', '002', '[-5, -1, 5]', 30, 'read_only')
ON CONFLICT (scope_type, scope_id) DO NOTHING;

-- §3B. Extend the two existing demo tenants with the new registry/billing columns
UPDATE tenants SET
    legal_name = 'PT Nusaevo Teknologi',
    contact_name = 'Simon',
    contact_email = 'simon@nusaevo.com',
    plan_code = 'INTERNAL',
    provisioning_status = 'provisioned',
    access_status = 'active',
    tenant_db_name = 'tenant_001',
    provisioned_at = now()
WHERE id = '001';

UPDATE tenants SET
    legal_name = 'Demo Legal Firm',
    contact_name = 'Firm B Admin',
    contact_email = 'admin@demolegal.example',
    plan_code = 'LEGAL_STARTER',
    provisioning_status = 'provisioned',
    access_status = 'active',
    tenant_db_name = 'tenant_002',
    provisioned_at = now()
WHERE id = '002';

-- §3C. Example à la carte add-on: Firm B also bought standalone Performance
INSERT INTO central_tenant_addons (tenant_id, module_code, price_override, status)
VALUES ('002', 'PERFORMANCE', NULL, 'active');

-- §3E. Example prior (paid) invoice + current (issued) invoice for tenant 002
INSERT INTO central_invoices (uuid, tenant_id, billing_period_start, billing_period_end, plan_code, status, amount_total, currency, due_date, issued_at)
VALUES
    (gen_random_uuid(), '002', '2026-07-01', '2026-07-31', 'LEGAL_STARTER', 'paid',   1750000, 'IDR', '2026-07-10', '2026-07-01 08:00:00+07'),
    (gen_random_uuid(), '002', '2026-08-01', '2026-08-31', 'LEGAL_STARTER', 'issued', 1750000, 'IDR', '2026-08-10', '2026-08-01 08:00:00+07');

INSERT INTO central_invoice_lines (invoice_id, description, amount)
SELECT id, 'Plan: LEGAL_STARTER', 1500000 FROM central_invoices WHERE tenant_id = '002' AND billing_period_start = '2026-07-01'
UNION ALL
SELECT id, 'Add-on: PERFORMANCE', 250000 FROM central_invoices WHERE tenant_id = '002' AND billing_period_start = '2026-07-01'
UNION ALL
SELECT id, 'Plan: LEGAL_STARTER', 1500000 FROM central_invoices WHERE tenant_id = '002' AND billing_period_start = '2026-08-01'
UNION ALL
SELECT id, 'Add-on: PERFORMANCE', 250000 FROM central_invoices WHERE tenant_id = '002' AND billing_period_start = '2026-08-01';

-- §3F. Example confirmed payment against the (already paid) July invoice
INSERT INTO central_payments (uuid, invoice_id, tenant_id, amount, method, receipt_object_key, status, submitted_at, reviewed_by, reviewed_at)
SELECT gen_random_uuid(), id, '002', 1750000, 'bank_transfer',
       'central/tenants/002/receipts/1/bukti-transfer-juli.pdf', 'confirmed',
       '2026-07-08 10:15:00+07',
       (SELECT id FROM central_admin_users WHERE email = 'simon@nusaevo.com'),
       '2026-07-08 14:30:00+07'
FROM central_invoices WHERE tenant_id = '002' AND billing_period_start = '2026-07-01';

-- §3G. Example reminder already sent for the current (August) invoice, -1 day offset
INSERT INTO central_dunning_log (tenant_id, invoice_id, offset_days, channel, sent_at)
SELECT '002', id, -1, 'email', '2026-08-09 09:00:00+07'
FROM central_invoices WHERE tenant_id = '002' AND billing_period_start = '2026-08-01';

-- §3I. Example audit trail entries
INSERT INTO central_audit_logs (action, actor_type, actor_id, entity_type, entity_id, before_snapshot, after_snapshot) VALUES
    ('tenant_provisioned', 'admin',
        (SELECT id FROM central_admin_users WHERE email = 'simon@nusaevo.com'),
        'tenant', '002',
        '{"provisioning_status": "provisioning"}', '{"provisioning_status": "provisioned"}'),
    ('payment_confirmed', 'admin',
        (SELECT id FROM central_admin_users WHERE email = 'simon@nusaevo.com'),
        'central_payments', '1',
        '{"status": "pending_review"}', '{"status": "confirmed"}'),
    ('invoice_issued', 'system', NULL,
        'central_invoices',
        (SELECT id::text FROM central_invoices WHERE tenant_id = '002' AND billing_period_start = '2026-08-01'),
        '{"status": "draft"}', '{"status": "issued"}');

COMMIT;
