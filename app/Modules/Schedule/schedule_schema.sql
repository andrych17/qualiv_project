-- =====================================================================
-- SCHEDULE — Calendar & Scheduling Engine
-- Schema DDL (PostgreSQL)
--
-- Multi-tenancy: DB-per-tenant (stancl/tenancy). This schema is created
-- INSIDE each tenant's own database, so there is deliberately NO
-- tenant_id column anywhere — isolation is the database boundary itself
-- (per CLAUDE.md §4/§7). This differs from the earlier WNE tables,
-- which used an explicit tenant_id column; flagged for reconciliation.
--
-- Naming:
--   - Master tables   : single word              (resources, resource_types, conference_providers)
--   - Transaction tbl : 2 parts, domain_ prefix   (sched_*)
-- =====================================================================

CREATE SCHEMA IF NOT EXISTS "SCHEDULE";
SET search_path TO "SCHEDULE";

-- =====================================================================
-- MASTER TABLES
-- =====================================================================

-- Resource classification: Room, Equipment, Vehicle, Staff... tenant-extensible
CREATE TABLE "SCHEDULE".resource_types (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Bookable resources: a specific room, a specific vehicle, a shared staff pool, etc.
CREATE TABLE "SCHEDULE".resources (
    id              BIGSERIAL PRIMARY KEY,
    resource_type_id BIGINT      NOT NULL REFERENCES "SCHEDULE".resource_types(id),
    name            VARCHAR(150) NOT NULL,
    location        VARCHAR(255),
    notes           TEXT,
    capacity        INT,                            -- informational only in v1, not pooled/enforced
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_resources_type ON "SCHEDULE".resources(resource_type_id);

-- Conference/video providers: manual link, Zoom, Google Meet, Teams...
CREATE TABLE "SCHEDULE".conference_providers (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    driver_class    VARCHAR(150) NOT NULL,           -- FQCN implementing ConferenceDriverInterface
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- =====================================================================
-- TRANSACTION TABLES (sched_*)
-- =====================================================================

-- Unified Task/Event header. `type` discriminates; task-only and event-only
-- fields simply go unused on the other type rather than splitting tables,
-- to keep v1 small and the calendar view a single query.
CREATE TABLE "SCHEDULE".sched_items (
    id              BIGSERIAL PRIMARY KEY,
    type            VARCHAR(10)  NOT NULL,           -- task, event
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    start_at        TIMESTAMPTZ,                     -- tasks may use due_at-only (start_at = due_at, end_at null)
    end_at          TIMESTAMPTZ,
    all_day         BOOLEAN      NOT NULL DEFAULT FALSE,
    location        VARCHAR(255),                    -- free-text physical location (events)
    priority        VARCHAR(10),                     -- low, normal, high — tasks only
    status          VARCHAR(20)  NOT NULL DEFAULT 'open', -- open,in_progress,done,cancelled / scheduled,cancelled
    owner_id        BIGINT       NOT NULL,
    subject_type    VARCHAR(150),                    -- polymorphic: owning module's model class (nullable)
    subject_id      BIGINT,
    recurrence_rule TEXT,                             -- RFC5545 RRULE string, nullable
    created_by      BIGINT,
    updated_by      BIGINT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ,
    CONSTRAINT chk_sched_items_type CHECK (type IN ('task', 'event'))
);
CREATE INDEX idx_sched_items_range ON "SCHEDULE".sched_items(start_at, end_at);
CREATE INDEX idx_sched_items_owner ON "SCHEDULE".sched_items(owner_id, status);
CREATE INDEX idx_sched_items_subject ON "SCHEDULE".sched_items(subject_type, subject_id);
CREATE INDEX idx_sched_items_status ON "SCHEDULE".sched_items(status);

-- Resource bookings: which resource(s) are attached to which calendar item.
-- For recurring items, this row represents the booking for the whole series;
-- per-occurrence conflicts are resolved at read time by the Recurrence Engine,
-- not by materializing one booking row per occurrence.
CREATE TABLE "SCHEDULE".sched_bookings (
    id              BIGSERIAL PRIMARY KEY,
    sched_item_id   BIGINT       NOT NULL REFERENCES "SCHEDULE".sched_items(id) ON DELETE CASCADE,
    resource_id     BIGINT       NOT NULL REFERENCES "SCHEDULE".resources(id),
    status          VARCHAR(20)  NOT NULL DEFAULT 'confirmed', -- confirmed, cancelled
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_sched_bookings_item ON "SCHEDULE".sched_bookings(sched_item_id);
CREATE INDEX idx_sched_bookings_resource ON "SCHEDULE".sched_bookings(resource_id, status);

-- People on a calendar item: owner (redundant with sched_items.owner_id for
-- query convenience), attendee, or watcher (tasks: notified but not assigned).
CREATE TABLE "SCHEDULE".sched_attendees (
    id              BIGSERIAL PRIMARY KEY,
    sched_item_id   BIGINT       NOT NULL REFERENCES "SCHEDULE".sched_items(id) ON DELETE CASCADE,
    user_id         BIGINT       NOT NULL,
    role            VARCHAR(20)  NOT NULL DEFAULT 'attendee', -- owner, attendee, watcher
    response_status VARCHAR(20)  NOT NULL DEFAULT 'pending',  -- pending, accepted, declined
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (sched_item_id, user_id)
);
CREATE INDEX idx_sched_attendees_user ON "SCHEDULE".sched_attendees(user_id);

-- Per-occurrence overrides for recurring items: skip one, or move/modify one,
-- without breaking the series defined by sched_items.recurrence_rule.
CREATE TABLE "SCHEDULE".sched_recurrence_exceptions (
    id                      BIGSERIAL PRIMARY KEY,
    sched_item_id           BIGINT       NOT NULL REFERENCES "SCHEDULE".sched_items(id) ON DELETE CASCADE,
    original_occurrence_date DATE        NOT NULL,   -- the date this exception applies to
    action                  VARCHAR(10)  NOT NULL,    -- skipped, moved, modified
    override_start_at       TIMESTAMPTZ,
    override_end_at         TIMESTAMPTZ,
    override_title          VARCHAR(255),
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_sched_rec_exc_action CHECK (action IN ('skipped', 'moved', 'modified')),
    UNIQUE (sched_item_id, original_occurrence_date)
);
CREATE INDEX idx_sched_rec_exc_item ON "SCHEDULE".sched_recurrence_exceptions(sched_item_id);

-- Conference/video link attached to an event.
CREATE TABLE "SCHEDULE".sched_conference_links (
    id                      BIGSERIAL PRIMARY KEY,
    sched_item_id           BIGINT       NOT NULL REFERENCES "SCHEDULE".sched_items(id) ON DELETE CASCADE,
    conference_provider_id  BIGINT       NOT NULL REFERENCES "SCHEDULE".conference_providers(id),
    join_url                VARCHAR(500) NOT NULL,
    external_meeting_id     VARCHAR(150),             -- provider's own ID, for future cancel/update calls
    dial_in_info            TEXT,                      -- free text, not structured in v1
    host_key                VARCHAR(150),
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (sched_item_id)
);

-- Optional weekly availability window per resource. Absence of rows for a
-- resource means "available 24/7" (no restriction applied).
CREATE TABLE "SCHEDULE".sched_working_hours (
    id              BIGSERIAL PRIMARY KEY,
    resource_id     BIGINT       NOT NULL REFERENCES "SCHEDULE".resources(id) ON DELETE CASCADE,
    day_of_week     SMALLINT     NOT NULL,            -- 0=Sunday .. 6=Saturday
    start_time      TIME         NOT NULL,
    end_time        TIME         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_sched_wh_dow CHECK (day_of_week BETWEEN 0 AND 6),
    CONSTRAINT chk_sched_wh_range CHECK (end_time > start_time)
);
CREATE INDEX idx_sched_working_hours_resource ON "SCHEDULE".sched_working_hours(resource_id, day_of_week);

-- Signed ICS subscription feeds — per user or per resource, revocable by
-- deactivating the token without touching auth.
CREATE TABLE "SCHEDULE".sched_calendar_feeds (
    id                BIGSERIAL PRIMARY KEY,
    token             UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    owner_type        VARCHAR(20)  NOT NULL,          -- user, resource
    owner_ref         BIGINT       NOT NULL,           -- user_id or resource_id, depending on owner_type
    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
    last_accessed_at  TIMESTAMPTZ,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_sched_feed_owner_type CHECK (owner_type IN ('user', 'resource'))
);
CREATE INDEX idx_sched_calendar_feeds_owner ON "SCHEDULE".sched_calendar_feeds(owner_type, owner_ref);

-- =====================================================================
-- SEED — minimal master data to bootstrap the module
-- =====================================================================

INSERT INTO "SCHEDULE".resource_types (code, name) VALUES
    ('room',      'Room'),
    ('equipment', 'Equipment'),
    ('vehicle',   'Vehicle'),
    ('staff',     'Staff');

INSERT INTO "SCHEDULE".conference_providers (code, name, driver_class, is_active) VALUES
    ('manual',      'Manual Link',  'App\Modules\Schedule\Drivers\ManualLinkDriver',  TRUE),
    ('zoom',        'Zoom',         'App\Modules\Schedule\Drivers\ZoomDriver',        FALSE),
    ('google_meet', 'Google Meet',  'App\Modules\Schedule\Drivers\GoogleMeetDriver',  FALSE);
-- zoom/google_meet ship inactive by default until a tenant configures API credentials;
-- flip is_active once OAuth/API keys are wired up.
