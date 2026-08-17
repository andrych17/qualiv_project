-- =============================================================================
-- CRM module — schema + example seed data
-- Source spec: app/Modules/CRM/CRM_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "CRM" — every table
--              lives inside one tenant's own database (CLAUDE.md §4/§7A), no
--              tenant_id column anywhere.
--
-- Partners are the one unified registry every vertical (Legal, Property, ...)
-- and every other Core module (Accounting, WNE) reaches INTO — CRM never reaches
-- back into a vertical's schema (§5). `svc_cases`/`hd_tickets.subject_type`/
-- `subject_id` are plain informational columns, deliberately NOT foreign keys
-- (§5 "Vertical linkage without coupling") — the same subject_type/subject_id
-- seam used by WNE workflow instances and Accounting's polymorphic pointers.
--
-- `type` (individual/organization) vs. `role` (Customer/Vendor/Client/...) are
-- deliberately separate concepts (§5): type is structural and fixed at creation,
-- role is business classification, many-to-many, and tenant-configurable — this
-- is what lets the same CRM schema be resold under different per-vertical
-- vocabulary (Client for Legal, Tenant/Owner for Property) without a migration.
--
-- This file is a reference/planning script, not a Laravel migration — port each
-- section below into `database/migrations/tenant/*.php` per CLAUDE.md §5 build
-- order (CRM comes after WNE/DMS, before Schedule/Inventory/Accounting).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"CUSTOMFIELDS"/"AIINSIGHT"/"ACCOUNTING".
CREATE SCHEMA IF NOT EXISTS "CRM";

-- -----------------------------------------------------------------------------
-- §4. Master / lookup tables — all tenant-editable, no code deploy to rename
-- "Client" to "Tenant" for a new vertical (§5 Extensibility)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CRM".industries (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "CRM".lead_sources (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,        -- referral, web, event, cold outreach, ...
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

-- Shared by After Sales Service (§3E) and Helpdesk (§3F)
CREATE TABLE IF NOT EXISTS "CRM".ticket_categories (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "CRM".partner_role_types (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,                -- tenant-facing label, e.g. "Client" / "Tenant" / "Owner"
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

-- -----------------------------------------------------------------------------
-- §3B / §3C / §4. Partners — the unified Company + Contact record
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CRM".partners (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing, future REST clients (§5)
    type                    VARCHAR(12) NOT NULL CHECK (type IN ('individual', 'organization')),
    parent_partner_id       BIGINT REFERENCES "CRM".partners (id), -- individual: employer/represents; organization: parent company
    name                    VARCHAR(255) NOT NULL,    -- full name (individual) or legal name (organization)
    trade_name              VARCHAR(255),              -- organization only
    title_position          VARCHAR(100),               -- individual only: title/position at their employer
    registration_tax_id     VARCHAR(50),                 -- organization only: NPWP or other registration id
    industry_id             BIGINT REFERENCES "CRM".industries (id), -- organization only, optional classification
    tags                    TEXT[],                        -- freeform tags; not a separate table per §4's own storage list
    owner_id                BIGINT REFERENCES users (id), -- internal user responsible for the relationship
    source                  VARCHAR(20) NOT NULL DEFAULT 'manual'
                                CHECK (source IN ('manual', 'lead_conversion', 'import')),
    is_active                BOOLEAN NOT NULL DEFAULT TRUE,
    merged_into_partner_id     BIGINT REFERENCES "CRM".partners (id), -- tombstone set by a merge (§3G) — row stays so vertical FKs still resolve
    created_at                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (id <> parent_partner_id AND id <> merged_into_partner_id)
);

CREATE INDEX IF NOT EXISTS idx_crm_partners_parent ON "CRM".partners (parent_partner_id);
CREATE INDEX IF NOT EXISTS idx_crm_partners_type ON "CRM".partners (type, is_active);

CREATE TABLE IF NOT EXISTS "CRM".addresses (
    id              BIGSERIAL PRIMARY KEY,
    partner_id      BIGINT NOT NULL REFERENCES "CRM".partners (id) ON DELETE CASCADE,
    type            VARCHAR(10) NOT NULL CHECK (type IN ('billing', 'shipping', 'office', 'other')),
    line1           VARCHAR(255) NOT NULL,
    line2           VARCHAR(255),
    city            VARCHAR(100),
    state_province  VARCHAR(100),
    postal_code     VARCHAR(20),
    country         VARCHAR(100),
    is_primary      BOOLEAN NOT NULL DEFAULT FALSE
);

-- One primary address per (partner, type) — same NULLS-NOT-DISTINCT caveat noted
-- elsewhere doesn't apply here since none of these columns are nullable.
CREATE UNIQUE INDEX IF NOT EXISTS uq_crm_addresses_primary
    ON "CRM".addresses (partner_id, type) WHERE is_primary;

CREATE TABLE IF NOT EXISTS "CRM".contact_points (
    id          BIGSERIAL PRIMARY KEY,
    partner_id  BIGINT NOT NULL REFERENCES "CRM".partners (id) ON DELETE CASCADE,
    type        VARCHAR(10) NOT NULL CHECK (type IN ('email', 'phone', 'mobile', 'fax')),
    value       VARCHAR(255) NOT NULL,
    is_primary  BOOLEAN NOT NULL DEFAULT FALSE,
    opt_out     BOOLEAN NOT NULL DEFAULT FALSE          -- respected by WNE routing rules (§4)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_crm_contact_points_primary
    ON "CRM".contact_points (partner_id, type) WHERE is_primary;

-- -----------------------------------------------------------------------------
-- §4. Roles (many-to-many, with history) and generalized relationships
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CRM".partner_roles (
    id              BIGSERIAL PRIMARY KEY,
    partner_id      BIGINT NOT NULL REFERENCES "CRM".partners (id) ON DELETE CASCADE,
    role_type_id    BIGINT NOT NULL REFERENCES "CRM".partner_role_types (id),
    assigned_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    assigned_by     BIGINT REFERENCES users (id),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

-- No plain UNIQUE(partner_id, role_type_id): removing then re-adding a role is a
-- new historical row (§4 "with history"), not an update — only one ACTIVE
-- assignment of a given role per partner is enforced.
CREATE UNIQUE INDEX IF NOT EXISTS uq_crm_partner_roles_active
    ON "CRM".partner_roles (partner_id, role_type_id) WHERE is_active;

CREATE INDEX IF NOT EXISTS idx_crm_partner_roles_partner ON "CRM".partner_roles (partner_id, is_active);

CREATE TABLE IF NOT EXISTS "CRM".partner_relationships (
    id                  BIGSERIAL PRIMARY KEY,
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id) ON DELETE CASCADE,
    related_partner_id  BIGINT NOT NULL REFERENCES "CRM".partners (id),
    relationship_type   VARCHAR(15) NOT NULL CHECK (relationship_type IN ('works_at', 'subsidiary_of', 'referred_by', 'other')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (partner_id <> related_partner_id)
);

CREATE INDEX IF NOT EXISTS idx_crm_partner_relationships_partner ON "CRM".partner_relationships (partner_id);

-- -----------------------------------------------------------------------------
-- §3D / §4. Leads — pre-partner pipeline
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CRM".leads (
    id                      BIGSERIAL PRIMARY KEY,
    name                    VARCHAR(255) NOT NULL,     -- free text until conversion
    company_name            VARCHAR(255),               -- free text, nullable
    source_id               BIGINT REFERENCES "CRM".lead_sources (id),
    stage                   VARCHAR(15) NOT NULL DEFAULT 'new'
                                CHECK (stage IN ('new', 'contacted', 'qualified', 'converted', 'disqualified')),
    owner_id                 BIGINT REFERENCES users (id),
    estimated_value            NUMERIC(18, 2),          -- free-form; no currency logic here (that belongs to Sales, §3D rule)
    next_action_at              TIMESTAMPTZ,
    notes                          TEXT,
    converted_partner_id             BIGINT REFERENCES "CRM".partners (id), -- set on conversion (§3D)
    disqualify_reason                  VARCHAR(100),    -- required (app-enforced) when stage = disqualified
    created_at                           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                             TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- A Lead is never itself a Partner (§3D rule) — converted_partner_id is set
    -- only once, at conversion; a Lead cannot be referenced by any vertical
    -- transaction directly, only the resulting partners.id can.
    CHECK ((stage = 'converted') = (converted_partner_id IS NOT NULL))
);

CREATE INDEX IF NOT EXISTS idx_crm_leads_stage ON "CRM".leads (stage, owner_id);

CREATE TABLE IF NOT EXISTS "CRM".lead_activities (
    id              BIGSERIAL PRIMARY KEY,
    lead_id         BIGINT NOT NULL REFERENCES "CRM".leads (id) ON DELETE CASCADE,
    activity_type   VARCHAR(10) NOT NULL CHECK (activity_type IN ('call', 'email', 'meeting', 'note')),
    body            TEXT,
    logged_by       BIGINT REFERENCES users (id),
    logged_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_crm_lead_activities_lead ON "CRM".lead_activities (lead_id, logged_at);

-- -----------------------------------------------------------------------------
-- §3E / §4. After Sales Service
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CRM".svc_cases (
    id              BIGSERIAL PRIMARY KEY,
    partner_id      BIGINT NOT NULL REFERENCES "CRM".partners (id),
    subject         VARCHAR(255) NOT NULL,
    category_id     BIGINT REFERENCES "CRM".ticket_categories (id),
    priority        VARCHAR(10) NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    status          VARCHAR(20) NOT NULL DEFAULT 'open'
                        CHECK (status IN ('open', 'in_progress', 'waiting_on_partner', 'resolved', 'closed')),
    assigned_to     BIGINT REFERENCES users (id),
    sla_due_at      TIMESTAMPTZ,
    subject_type    VARCHAR(100),                       -- informational only, e.g. 'legal.case_hdrs' — NOT a FK (§5)
    subject_id      VARCHAR(100),
    closed_at       TIMESTAMPTZ,                         -- drives the reopen grace window (default 7 days, §3E rule)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_crm_svc_cases_partner ON "CRM".svc_cases (partner_id, status);
CREATE INDEX IF NOT EXISTS idx_crm_svc_cases_subject ON "CRM".svc_cases (subject_type, subject_id);

CREATE TABLE IF NOT EXISTS "CRM".svc_case_activities (
    id              BIGSERIAL PRIMARY KEY,
    case_id         BIGINT NOT NULL REFERENCES "CRM".svc_cases (id) ON DELETE CASCADE,
    activity_type   VARCHAR(15) NOT NULL CHECK (activity_type IN ('note', 'status_change', 'attachment')),
    body            TEXT,
    logged_by       BIGINT REFERENCES users (id),
    logged_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_crm_svc_case_activities_case ON "CRM".svc_case_activities (case_id, logged_at);

-- -----------------------------------------------------------------------------
-- §3F / §4. Helpdesk — kept as its own engine (§5), sharing only lookups
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CRM".hd_tickets (
    id                  BIGSERIAL PRIMARY KEY,
    partner_id          BIGINT REFERENCES "CRM".partners (id), -- nullable: requester may be unidentified/pre-CRM
    requester_free_text VARCHAR(255),                 -- name/contact when partner_id is NULL
    subject             VARCHAR(255) NOT NULL,
    category_id           BIGINT REFERENCES "CRM".ticket_categories (id),
    priority                VARCHAR(10) NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    status                    VARCHAR(20) NOT NULL DEFAULT 'open'
                                  CHECK (status IN ('open', 'in_progress', 'waiting_on_partner', 'resolved', 'closed')),
    assigned_to                 BIGINT REFERENCES users (id),
    sla_due_at                    TIMESTAMPTZ,
    channel                         VARCHAR(10) NOT NULL DEFAULT 'email' CHECK (channel IN ('email', 'phone', 'web_form', 'in_app')),
    created_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (partner_id IS NOT NULL OR requester_free_text IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_crm_hd_tickets_partner ON "CRM".hd_tickets (partner_id, status);

CREATE TABLE IF NOT EXISTS "CRM".hd_ticket_messages (
    id                  BIGSERIAL PRIMARY KEY,
    ticket_id           BIGINT NOT NULL REFERENCES "CRM".hd_tickets (id) ON DELETE CASCADE,
    direction            VARCHAR(15) NOT NULL CHECK (direction IN ('inbound', 'outbound', 'internal_note')),
    body                    TEXT NOT NULL,
    sender_id                 BIGINT REFERENCES users (id), -- internal sender
    sender_free_text            VARCHAR(255),           -- external sender when there's no user record
    sent_at                        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_crm_hd_ticket_messages_ticket ON "CRM".hd_ticket_messages (ticket_id, sent_at);

-- -----------------------------------------------------------------------------
-- §3G / §4. Partner Merge / Deduplication — always logged, never silent (§3G rule)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "CRM".partner_merge_log (
    id                          BIGSERIAL PRIMARY KEY,
    merged_from_partner_id      BIGINT NOT NULL REFERENCES "CRM".partners (id),
    merged_into_partner_id      BIGINT NOT NULL REFERENCES "CRM".partners (id),
    merged_by                   BIGINT REFERENCES users (id),
    merged_at                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    field_conflicts             JSONB,                  -- snapshot of what differed between the two records
    CHECK (merged_from_partner_id <> merged_into_partner_id)
);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Two partners (an organization "client" and an organization "vendor") are
-- seeded with the same emails ACCOUNTING_SPECS.sql's example expects
-- (client@example-corp.com / vendor@office-rentals.example) — running this
-- script before ACCOUNTING_SPECS.sql's seed gives it real "CRM".partners rows
-- to FK against, matching CLAUDE.md §5 build order (CRM before Accounting).
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset or
-- other client-specific meta-commands — so it runs through any Postgres client.
-- =============================================================================

BEGIN;

INSERT INTO "CRM".industries (name) VALUES ('Legal Services'), ('Real Estate'), ('Manufacturing');
INSERT INTO "CRM".lead_sources (name) VALUES ('Referral'), ('Website'), ('Event'), ('Cold Outreach');
INSERT INTO "CRM".ticket_categories (name) VALUES ('Billing'), ('Technical'), ('General Inquiry');

-- Tenant-configurable role vocabulary — a Legal-vertical tenant's own wording
INSERT INTO "CRM".partner_role_types (code, name) VALUES
    ('CLIENT', 'Client'),
    ('VENDOR', 'Vendor'),
    ('REFERRAL_PARTNER', 'Referral Partner');

-- Two organizations (a client and a vendor) + one individual who works at the client
WITH industry AS (SELECT id FROM "CRM".industries WHERE name = 'Legal Services')
INSERT INTO "CRM".partners (type, name, registration_tax_id, industry_id, owner_id, source)
SELECT 'organization', 'Example Corp', '02.345.678.9-012.000', industry.id,
       (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'manual'
FROM industry;

INSERT INTO "CRM".partners (type, name, registration_tax_id, owner_id, source)
VALUES ('organization', 'Office Rentals Vendor', '03.456.789.0-123.000',
        (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'manual');

WITH parent AS (SELECT id FROM "CRM".partners WHERE name = 'Example Corp')
INSERT INTO "CRM".partners (type, parent_partner_id, name, title_position, owner_id, source)
SELECT 'individual', parent.id, 'Budi Santoso', 'Finance Manager',
       (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'manual'
FROM parent;

-- Primary billing address + primary email for each organization
WITH p AS (SELECT id FROM "CRM".partners WHERE name = 'Example Corp')
INSERT INTO "CRM".addresses (partner_id, type, line1, city, state_province, postal_code, country, is_primary)
SELECT id, 'billing', 'Jl. Sudirman No. 1', 'Jakarta', 'DKI Jakarta', '10220', 'Indonesia', TRUE FROM p;

WITH p AS (SELECT id FROM "CRM".partners WHERE name = 'Example Corp')
INSERT INTO "CRM".contact_points (partner_id, type, value, is_primary)
SELECT id, 'email', 'client@example-corp.com', TRUE FROM p;

WITH p AS (SELECT id FROM "CRM".partners WHERE name = 'Office Rentals Vendor')
INSERT INTO "CRM".contact_points (partner_id, type, value, is_primary)
SELECT id, 'email', 'vendor@office-rentals.example', TRUE FROM p;

WITH p AS (SELECT id FROM "CRM".partners WHERE name = 'Budi Santoso')
INSERT INTO "CRM".contact_points (partner_id, type, value, is_primary)
SELECT id, 'mobile', '+62 812-3456-7890', TRUE FROM p;

-- Roles: Example Corp is a Client, the vendor org is a Vendor
INSERT INTO "CRM".partner_roles (partner_id, role_type_id, assigned_by)
SELECT p.id, rt.id, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "CRM".partners p, "CRM".partner_role_types rt
WHERE p.name = 'Example Corp' AND rt.code = 'CLIENT'
UNION ALL
SELECT p.id, rt.id, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "CRM".partners p, "CRM".partner_role_types rt
WHERE p.name = 'Office Rentals Vendor' AND rt.code = 'VENDOR';

-- Relationship beyond the simple parent_partner_id hierarchy (§4): Example Corp
-- was referred by the vendor org
INSERT INTO "CRM".partner_relationships (partner_id, related_partner_id, relationship_type)
SELECT a.id, b.id, 'referred_by'
FROM "CRM".partners a, "CRM".partners b
WHERE a.name = 'Example Corp' AND b.name = 'Office Rentals Vendor';

-- A lead in the pipeline, with one logged activity
WITH lead_ins AS (
    INSERT INTO "CRM".leads (name, company_name, source_id, stage, owner_id, estimated_value, next_action_at, notes)
    SELECT 'Siti Rahayu', 'Toko Maju Bersama',
           (SELECT id FROM "CRM".lead_sources WHERE name = 'Website'),
           'qualified',
           (SELECT id FROM users WHERE email = 'admin@nusaevo.com'),
           25000000, now() + INTERVAL '3 days', 'Interested in a retainer package.'
    RETURNING id
)
INSERT INTO "CRM".lead_activities (lead_id, activity_type, body, logged_by)
SELECT lead_ins.id, 'call', 'Initial qualification call — confirmed budget and timeline.',
       (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM lead_ins;

-- After Sales case pointing back at a (hypothetical) Legal case, matching the
-- example given in the spec's own text (§3E: subject_type = 'legal.case_hdrs')
WITH case_ins AS (
    INSERT INTO "CRM".svc_cases (partner_id, subject, category_id, priority, status, assigned_to, sla_due_at, subject_type, subject_id)
    SELECT p.id, 'Follow-up filing needed for closed matter',
           (SELECT id FROM "CRM".ticket_categories WHERE name = 'General Inquiry'),
           'normal', 'in_progress',
           (SELECT id FROM users WHERE email = 'staff@nusaevo.com'),
           now() + INTERVAL '2 days', 'legal.case_hdrs', '4821'
    FROM "CRM".partners p WHERE p.name = 'Example Corp'
    RETURNING id
)
INSERT INTO "CRM".svc_case_activities (case_id, activity_type, body, logged_by)
SELECT case_ins.id, 'note', 'Contacted client, awaiting signed authorization letter.',
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
FROM case_ins;

-- A helpdesk ticket with one inbound message
WITH ticket_ins AS (
    INSERT INTO "CRM".hd_tickets (partner_id, subject, category_id, priority, status, assigned_to, sla_due_at, channel)
    SELECT p.id, 'Question about last invoice',
           (SELECT id FROM "CRM".ticket_categories WHERE name = 'Billing'),
           'normal', 'open',
           (SELECT id FROM users WHERE email = 'staff@nusaevo.com'),
           now() + INTERVAL '1 day', 'email'
    FROM "CRM".partners p WHERE p.name = 'Example Corp'
    RETURNING id
)
INSERT INTO "CRM".hd_ticket_messages (ticket_id, direction, body, sender_free_text)
SELECT ticket_ins.id, 'inbound', 'Could you clarify the PPN line on INV-2026-0001?', 'client@example-corp.com'
FROM ticket_ins;

-- Merge example: a duplicate org record gets merged into the real Example Corp
INSERT INTO "CRM".partners (type, name, source)
VALUES ('organization', 'Example Corp (dup. import)', 'import');

WITH survivor AS (SELECT id FROM "CRM".partners WHERE name = 'Example Corp'),
     loser AS (SELECT id FROM "CRM".partners WHERE name = 'Example Corp (dup. import)')
UPDATE "CRM".partners
SET is_active = FALSE, merged_into_partner_id = survivor.id
FROM survivor, loser
WHERE "CRM".partners.id = loser.id;

INSERT INTO "CRM".partner_merge_log (merged_from_partner_id, merged_into_partner_id, merged_by, field_conflicts)
SELECT loser.id, survivor.id, (SELECT id FROM users WHERE email = 'admin@nusaevo.com'),
       jsonb_build_object('registration_tax_id', jsonb_build_object('kept', '02.345.678.9-012.000', 'discarded', NULL))
FROM "CRM".partners survivor, "CRM".partners loser
WHERE survivor.name = 'Example Corp' AND loser.name = 'Example Corp (dup. import)';

COMMIT;
