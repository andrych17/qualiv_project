-- =============================================================================
-- CRM Module — PostgreSQL DDL
-- Core Shared Module: Partner Registry, Leads, After Sales Service, Helpdesk
--
-- Scope: run inside a single TENANT database (tenant_{id}). This module does
-- NOT use a tenant_id column — isolation is the database boundary itself,
-- per CLAUDE.md §4/§7. Schema namespace: CRM (matches WNE's `wne` pattern).
--
-- Conventions (per CLAUDE.md §7 / WNE_SPECS.md):
--   - bigint identity PK/FK for all internal joins.
--   - uuid column on external-facing headers (partners, leads, svc_cases,
--     hd_tickets) for future REST/mobile clients — never used for internal joins.
--   - Master/lookup tables: single word. Transaction/log tables: domain-
--     prefixed (lead_*, svc_*, hd_*).
--   - Status/type columns use varchar + CHECK rather than native enum types,
--     so adding a new value is an ALTER ... DROP/ADD CONSTRAINT, not a type
--     migration — cheaper for a solo dev to evolve.
--   - subject_type / subject_id on svc_cases / hd_tickets are informational
--     pointers into a VERTICAL module's records (e.g. LEGAL.case_hdrs) — they
--     are intentionally NOT foreign keys, since Core must have zero knowledge
--     of Vertical schemas. Resolution happens in application code.
--   - Vertical modules (LEGAL, SALES, PROPERTY, ...) are expected to FK
--     directly into CRM.partners(id) — that direction (Vertical -> Core) is
--     allowed and preferred over UUID matching, since it's the same tenant DB.
--   - Assumes a `public.users` table exists (Laravel default auth table) for
--     owner_id / assigned_to / created_by / logged_by references. Adjust the
--     schema-qualification below if your users table lives elsewhere.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;  -- gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- fuzzy name matching for dedupe (3G)

CREATE SCHEMA IF NOT EXISTS "CRM";

-- -----------------------------------------------------------------------------
-- Shared trigger: keep updated_at current on every UPDATE.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION "CRM".set_updated_at()
RETURNS trigger AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- MASTER / LOOKUP TABLES
-- =============================================================================

-- -----------------------------------------------------------------------------
-- CRM.industries — optional classification for Companies.
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".industries (
    id          bigserial PRIMARY KEY,
    code        varchar(50)  NOT NULL,
    label       varchar(150) NOT NULL,
    is_active   boolean      NOT NULL DEFAULT true,
    created_at  timestamptz  NOT NULL DEFAULT now(),
    updated_at  timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT uq_industries_code UNIQUE (code)
);
CREATE TRIGGER trg_industries_updated_at
    BEFORE UPDATE ON "CRM".industries
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();
COMMENT ON TABLE "CRM".industries IS 'Optional industry classification for organization-type partners.';

-- -----------------------------------------------------------------------------
-- CRM.lead_sources — how a lead entered the pipeline.
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".lead_sources (
    id          bigserial PRIMARY KEY,
    code        varchar(50)  NOT NULL,
    label       varchar(150) NOT NULL,
    is_active   boolean      NOT NULL DEFAULT true,
    created_at  timestamptz  NOT NULL DEFAULT now(),
    updated_at  timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT uq_lead_sources_code UNIQUE (code)
);
CREATE TRIGGER trg_lead_sources_updated_at
    BEFORE UPDATE ON "CRM".lead_sources
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();

-- -----------------------------------------------------------------------------
-- CRM.ticket_categories — shared by After Sales Service and Helpdesk.
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".ticket_categories (
    id          bigserial PRIMARY KEY,
    code        varchar(50)  NOT NULL,
    label       varchar(150) NOT NULL,
    applies_to  varchar(20)  NOT NULL DEFAULT 'both',
    is_active   boolean      NOT NULL DEFAULT true,
    created_at  timestamptz  NOT NULL DEFAULT now(),
    updated_at  timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT uq_ticket_categories_code UNIQUE (code),
    CONSTRAINT ck_ticket_categories_applies_to
        CHECK (applies_to IN ('after_sales', 'helpdesk', 'both'))
);
CREATE TRIGGER trg_ticket_categories_updated_at
    BEFORE UPDATE ON "CRM".ticket_categories
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();

-- -----------------------------------------------------------------------------
-- CRM.partner_role_types — tenant-editable role vocabulary
-- (Customer / Vendor / Client / Employee / Referral / Other, ...).
-- is_system = true marks the seeded defaults so a tenant admin can add their
-- own rows but the baseline set stays intact.
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".partner_role_types (
    id          bigserial PRIMARY KEY,
    code        varchar(50)  NOT NULL,
    label       varchar(100) NOT NULL,
    is_system   boolean      NOT NULL DEFAULT false,
    is_active   boolean      NOT NULL DEFAULT true,
    sort_order  integer      NOT NULL DEFAULT 0,
    created_at  timestamptz  NOT NULL DEFAULT now(),
    updated_at  timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT uq_partner_role_types_code UNIQUE (code)
);
CREATE TRIGGER trg_partner_role_types_updated_at
    BEFORE UPDATE ON "CRM".partner_role_types
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();

-- =============================================================================
-- PARTNER REGISTRY (Contacts + Companies, unified)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- CRM.partners — the single source of truth. type = individual (Contact) or
-- organization (Company). parent_partner_id links a Contact to the Company
-- they represent, or a Company to its parent (subsidiary_of).
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".partners (
    id                    bigserial PRIMARY KEY,
    uuid                  uuid         NOT NULL DEFAULT gen_random_uuid(),
    type                  varchar(20)  NOT NULL,
    parent_partner_id     bigint       NULL REFERENCES "CRM".partners(id) ON DELETE SET NULL,
    name                  varchar(255) NOT NULL,
    trade_name            varchar(255) NULL,
    first_name            varchar(120) NULL,
    last_name             varchar(120) NULL,
    position_title        varchar(150) NULL,
    registration_no       varchar(100) NULL,
    industry_id           bigint       NULL REFERENCES "CRM".industries(id) ON DELETE SET NULL,
    owner_id              bigint       NULL REFERENCES public.users(id) ON DELETE SET NULL,
    source                varchar(50)  NULL,
    notes                 text         NULL,
    is_active             boolean      NOT NULL DEFAULT true,
    merged_into_partner_id bigint      NULL REFERENCES "CRM".partners(id) ON DELETE SET NULL,
    created_by            bigint       NULL REFERENCES public.users(id) ON DELETE SET NULL,
    updated_by            bigint       NULL REFERENCES public.users(id) ON DELETE SET NULL,
    created_at            timestamptz  NOT NULL DEFAULT now(),
    updated_at            timestamptz  NOT NULL DEFAULT now(),
    deleted_at            timestamptz  NULL,
    CONSTRAINT uq_partners_uuid UNIQUE (uuid),
    CONSTRAINT ck_partners_type CHECK (type IN ('individual', 'organization')),
    CONSTRAINT ck_partners_not_self_parent CHECK (parent_partner_id IS DISTINCT FROM id),
    CONSTRAINT ck_partners_not_self_merge CHECK (merged_into_partner_id IS DISTINCT FROM id)
);
CREATE INDEX ix_partners_parent_partner_id ON "CRM".partners(parent_partner_id);
CREATE INDEX ix_partners_type ON "CRM".partners(type);
CREATE INDEX ix_partners_owner_id ON "CRM".partners(owner_id);
CREATE INDEX ix_partners_name_trgm ON "CRM".partners USING gin (name gin_trgm_ops);
CREATE TRIGGER trg_partners_updated_at
    BEFORE UPDATE ON "CRM".partners
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();
COMMENT ON TABLE "CRM".partners IS 'Unified Contact (individual) + Company (organization) registry. Vertical modules FK directly into this table (Vertical -> Core is the allowed direction).';
COMMENT ON COLUMN "CRM".partners.merged_into_partner_id IS 'Set when this record was merged away (see partner_merge_log). Row is kept as a tombstone so existing FKs from vertical modules still resolve.';

-- -----------------------------------------------------------------------------
-- CRM.addresses — one-to-many per partner.
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".addresses (
    id              bigserial PRIMARY KEY,
    partner_id      bigint       NOT NULL REFERENCES "CRM".partners(id) ON DELETE CASCADE,
    type            varchar(30)  NOT NULL DEFAULT 'office',
    line1           varchar(255) NOT NULL,
    line2           varchar(255) NULL,
    city            varchar(120) NULL,
    state_province  varchar(120) NULL,
    postal_code     varchar(20)  NULL,
    country_code    char(2)      NULL,
    is_primary      boolean      NOT NULL DEFAULT false,
    created_at      timestamptz  NOT NULL DEFAULT now(),
    updated_at      timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT ck_addresses_type CHECK (type IN ('billing', 'shipping', 'office', 'other'))
);
CREATE INDEX ix_addresses_partner_id ON "CRM".addresses(partner_id);
CREATE UNIQUE INDEX uq_addresses_one_primary_per_partner
    ON "CRM".addresses(partner_id) WHERE is_primary;
CREATE TRIGGER trg_addresses_updated_at
    BEFORE UPDATE ON "CRM".addresses
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();

-- -----------------------------------------------------------------------------
-- CRM.contact_points — email/phone/etc, one-to-many per partner.
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".contact_points (
    id          bigserial PRIMARY KEY,
    partner_id  bigint       NOT NULL REFERENCES "CRM".partners(id) ON DELETE CASCADE,
    type        varchar(20)  NOT NULL,
    value       varchar(255) NOT NULL,
    is_primary  boolean      NOT NULL DEFAULT false,
    opt_out     boolean      NOT NULL DEFAULT false,
    created_at  timestamptz  NOT NULL DEFAULT now(),
    updated_at  timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT ck_contact_points_type CHECK (type IN ('email', 'phone', 'mobile', 'fax', 'other'))
);
CREATE INDEX ix_contact_points_partner_id ON "CRM".contact_points(partner_id);
CREATE INDEX ix_contact_points_value ON "CRM".contact_points(value);
CREATE UNIQUE INDEX uq_contact_points_one_primary_per_type
    ON "CRM".contact_points(partner_id, type) WHERE is_primary;
CREATE TRIGGER trg_contact_points_updated_at
    BEFORE UPDATE ON "CRM".contact_points
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();

-- -----------------------------------------------------------------------------
-- CRM.partner_roles — many-to-many partner <-> role_type, with assignment
-- history (rows are deactivated, not deleted, when a role is revoked).
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".partner_roles (
    id            bigserial PRIMARY KEY,
    partner_id    bigint      NOT NULL REFERENCES "CRM".partners(id) ON DELETE CASCADE,
    role_type_id  bigint      NOT NULL REFERENCES "CRM".partner_role_types(id) ON DELETE RESTRICT,
    assigned_at   timestamptz NOT NULL DEFAULT now(),
    assigned_by   bigint      NULL REFERENCES public.users(id) ON DELETE SET NULL,
    revoked_at    timestamptz NULL,
    is_active     boolean     NOT NULL DEFAULT true
);
CREATE INDEX ix_partner_roles_partner_id ON "CRM".partner_roles(partner_id);
CREATE INDEX ix_partner_roles_role_type_id ON "CRM".partner_roles(role_type_id);
CREATE UNIQUE INDEX uq_partner_roles_active_pair
    ON "CRM".partner_roles(partner_id, role_type_id) WHERE is_active;
COMMENT ON TABLE "CRM".partner_roles IS 'A partner can hold multiple roles at once (e.g. Vendor and Client). Role vocabulary is tenant-editable via partner_role_types.';

-- -----------------------------------------------------------------------------
-- CRM.partner_relationships — generalizes affiliations beyond the strict
-- parent_partner_id hierarchy (referred_by, subsidiary_of, works_at, other).
-- -----------------------------------------------------------------------------
CREATE TABLE "CRM".partner_relationships (
    id                 bigserial PRIMARY KEY,
    partner_id         bigint      NOT NULL REFERENCES "CRM".partners(id) ON DELETE CASCADE,
    related_partner_id bigint      NOT NULL REFERENCES "CRM".partners(id) ON DELETE CASCADE,
    relationship_type  varchar(30) NOT NULL,
    notes              text        NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_partner_relationships_type
        CHECK (relationship_type IN ('works_at', 'subsidiary_of', 'referred_by', 'other')),
    CONSTRAINT ck_partner_relationships_not_self
        CHECK (partner_id <> related_partner_id)
);
CREATE INDEX ix_partner_relationships_partner_id ON "CRM".partner_relationships(partner_id);
CREATE INDEX ix_partner_relationships_related_partner_id ON "CRM".partner_relationships(related_partner_id);

-- =============================================================================
-- LEADS
-- =============================================================================

CREATE TABLE "CRM".leads (
    id                   bigserial PRIMARY KEY,
    uuid                 uuid         NOT NULL DEFAULT gen_random_uuid(),
    name                 varchar(255) NOT NULL,
    company_name         varchar(255) NULL,
    source_id            bigint       NULL REFERENCES "CRM".lead_sources(id) ON DELETE SET NULL,
    stage                varchar(20)  NOT NULL DEFAULT 'new',
    owner_id             bigint       NULL REFERENCES public.users(id) ON DELETE SET NULL,
    estimated_value      numeric(14,2) NULL,
    next_action_at       timestamptz  NULL,
    converted_partner_id bigint       NULL REFERENCES "CRM".partners(id) ON DELETE SET NULL,
    disqualify_reason    varchar(100) NULL,
    created_at           timestamptz  NOT NULL DEFAULT now(),
    updated_at           timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT uq_leads_uuid UNIQUE (uuid),
    CONSTRAINT ck_leads_stage
        CHECK (stage IN ('new', 'contacted', 'qualified', 'converted', 'disqualified'))
);
CREATE INDEX ix_leads_stage ON "CRM".leads(stage);
CREATE INDEX ix_leads_owner_id ON "CRM".leads(owner_id);
CREATE TRIGGER trg_leads_updated_at
    BEFORE UPDATE ON "CRM".leads
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();
COMMENT ON TABLE "CRM".leads IS 'Pre-partner pipeline. A lead has no roles and cannot be referenced by any vertical transaction until converted.';

CREATE TABLE "CRM".lead_activities (
    id           bigserial PRIMARY KEY,
    lead_id      bigint      NOT NULL REFERENCES "CRM".leads(id) ON DELETE CASCADE,
    activity_type varchar(20) NOT NULL,
    body         text        NULL,
    logged_by    bigint      NULL REFERENCES public.users(id) ON DELETE SET NULL,
    logged_at    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_lead_activities_type
        CHECK (activity_type IN ('call', 'email', 'meeting', 'note', 'stage_change'))
);
CREATE INDEX ix_lead_activities_lead_id ON "CRM".lead_activities(lead_id);

-- =============================================================================
-- AFTER SALES SERVICE
-- =============================================================================

CREATE TABLE "CRM".svc_cases (
    id           bigserial PRIMARY KEY,
    uuid         uuid         NOT NULL DEFAULT gen_random_uuid(),
    partner_id   bigint       NOT NULL REFERENCES "CRM".partners(id) ON DELETE RESTRICT,
    subject      varchar(255) NOT NULL,
    category_id  bigint       NULL REFERENCES "CRM".ticket_categories(id) ON DELETE SET NULL,
    priority     varchar(10)  NOT NULL DEFAULT 'normal',
    status       varchar(20)  NOT NULL DEFAULT 'open',
    assigned_to  bigint       NULL REFERENCES public.users(id) ON DELETE SET NULL,
    sla_due_at   timestamptz  NULL,
    subject_type varchar(100) NULL,
    subject_id   bigint       NULL,
    closed_at    timestamptz  NULL,
    created_at   timestamptz  NOT NULL DEFAULT now(),
    updated_at   timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT uq_svc_cases_uuid UNIQUE (uuid),
    CONSTRAINT ck_svc_cases_priority CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    CONSTRAINT ck_svc_cases_status
        CHECK (status IN ('open', 'in_progress', 'waiting_on_partner', 'resolved', 'closed'))
);
CREATE INDEX ix_svc_cases_partner_id ON "CRM".svc_cases(partner_id);
CREATE INDEX ix_svc_cases_status ON "CRM".svc_cases(status);
CREATE INDEX ix_svc_cases_assigned_to ON "CRM".svc_cases(assigned_to);
CREATE INDEX ix_svc_cases_subject_ref ON "CRM".svc_cases(subject_type, subject_id);
CREATE TRIGGER trg_svc_cases_updated_at
    BEFORE UPDATE ON "CRM".svc_cases
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();
COMMENT ON COLUMN "CRM".svc_cases.subject_type IS 'Informational pointer only (e.g. legal.case_hdrs) — NOT a foreign key. CRM never reaches into a vertical schema.';

CREATE TABLE "CRM".svc_case_activities (
    id              bigserial PRIMARY KEY,
    case_id         bigint      NOT NULL REFERENCES "CRM".svc_cases(id) ON DELETE CASCADE,
    activity_type   varchar(20) NOT NULL,
    body            text        NULL,
    attachment_path varchar(500) NULL,
    logged_by       bigint      NULL REFERENCES public.users(id) ON DELETE SET NULL,
    logged_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_svc_case_activities_type
        CHECK (activity_type IN ('note', 'status_change', 'attachment'))
);
CREATE INDEX ix_svc_case_activities_case_id ON "CRM".svc_case_activities(case_id);

-- =============================================================================
-- HELPDESK
-- =============================================================================

CREATE TABLE "CRM".hd_tickets (
    id                bigserial PRIMARY KEY,
    uuid              uuid         NOT NULL DEFAULT gen_random_uuid(),
    partner_id        bigint       NULL REFERENCES "CRM".partners(id) ON DELETE SET NULL,
    requester_name    varchar(255) NULL,
    requester_contact varchar(255) NULL,
    subject           varchar(255) NOT NULL,
    category_id       bigint       NULL REFERENCES "CRM".ticket_categories(id) ON DELETE SET NULL,
    priority          varchar(10)  NOT NULL DEFAULT 'normal',
    status            varchar(20)  NOT NULL DEFAULT 'open',
    assigned_to       bigint       NULL REFERENCES public.users(id) ON DELETE SET NULL,
    sla_due_at        timestamptz  NULL,
    channel           varchar(20)  NOT NULL DEFAULT 'email',
    closed_at         timestamptz  NULL,
    created_at        timestamptz  NOT NULL DEFAULT now(),
    updated_at        timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT uq_hd_tickets_uuid UNIQUE (uuid),
    CONSTRAINT ck_hd_tickets_priority CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    CONSTRAINT ck_hd_tickets_status
        CHECK (status IN ('open', 'in_progress', 'waiting_on_partner', 'resolved', 'closed')),
    CONSTRAINT ck_hd_tickets_channel
        CHECK (channel IN ('email', 'phone', 'web_form', 'in_app', 'other')),
    CONSTRAINT ck_hd_tickets_requester_present
        CHECK (partner_id IS NOT NULL OR requester_name IS NOT NULL)
);
CREATE INDEX ix_hd_tickets_partner_id ON "CRM".hd_tickets(partner_id);
CREATE INDEX ix_hd_tickets_status ON "CRM".hd_tickets(status);
CREATE INDEX ix_hd_tickets_assigned_to ON "CRM".hd_tickets(assigned_to);
CREATE TRIGGER trg_hd_tickets_updated_at
    BEFORE UPDATE ON "CRM".hd_tickets
    FOR EACH ROW EXECUTE FUNCTION "CRM".set_updated_at();
COMMENT ON COLUMN "CRM".hd_tickets.partner_id IS 'Nullable: a Helpdesk ticket may arrive before the requester is identified/converted from a Lead.';

CREATE TABLE "CRM".hd_ticket_messages (
    id               bigserial PRIMARY KEY,
    ticket_id        bigint      NOT NULL REFERENCES "CRM".hd_tickets(id) ON DELETE CASCADE,
    direction        varchar(20) NOT NULL,
    body             text        NOT NULL,
    sender_partner_id bigint     NULL REFERENCES "CRM".partners(id) ON DELETE SET NULL,
    sender_user_id   bigint      NULL REFERENCES public.users(id) ON DELETE SET NULL,
    sender_free_text varchar(255) NULL,
    attachment_path  varchar(500) NULL,
    sent_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_hd_ticket_messages_direction
        CHECK (direction IN ('inbound', 'outbound', 'internal_note'))
);
CREATE INDEX ix_hd_ticket_messages_ticket_id ON "CRM".hd_ticket_messages(ticket_id);

-- =============================================================================
-- PARTNER MERGE / DEDUPLICATION AUDIT
-- =============================================================================

CREATE TABLE "CRM".partner_merge_log (
    id                     bigserial PRIMARY KEY,
    merged_from_partner_id bigint      NOT NULL REFERENCES "CRM".partners(id) ON DELETE RESTRICT,
    merged_into_partner_id bigint      NOT NULL REFERENCES "CRM".partners(id) ON DELETE RESTRICT,
    merged_by              bigint      NULL REFERENCES public.users(id) ON DELETE SET NULL,
    merged_at              timestamptz NOT NULL DEFAULT now(),
    field_conflicts        jsonb       NULL,
    CONSTRAINT ck_partner_merge_log_not_self
        CHECK (merged_from_partner_id <> merged_into_partner_id)
);
CREATE INDEX ix_partner_merge_log_merged_from ON "CRM".partner_merge_log(merged_from_partner_id);
CREATE INDEX ix_partner_merge_log_merged_into ON "CRM".partner_merge_log(merged_into_partner_id);

-- =============================================================================
-- SEED: default (is_system) lookups — safe to run once per tenant DB.
-- Tenants can add their own rows alongside these; is_system rows are the
-- baseline vocabulary and should not be deleted (enforce in app layer).
-- =============================================================================

INSERT INTO "CRM".partner_role_types (code, label, is_system, sort_order) VALUES
    ('customer', 'Customer', true, 10),
    ('vendor',   'Vendor',   true, 20),
    ('client',   'Client',   true, 30),
    ('employee', 'Employee', true, 40),
    ('referral', 'Referral Partner', true, 50),
    ('other',    'Other',    true, 60);

INSERT INTO "CRM".lead_sources (code, label) VALUES
    ('referral',       'Referral'),
    ('website',        'Website'),
    ('event',          'Event'),
    ('cold_outreach',  'Cold Outreach'),
    ('other',          'Other');

INSERT INTO "CRM".ticket_categories (code, label, applies_to) VALUES
    ('billing_inquiry',   'Billing Inquiry',        'both'),
    ('document_request',  'Document Request',       'after_sales'),
    ('case_status_update','Case Status Update',     'after_sales'),
    ('technical_support', 'Technical Support',      'helpdesk'),
    ('general_inquiry',   'General Inquiry',        'helpdesk');

-- =============================================================================
-- End of CRM_Schema.sql
-- =============================================================================
