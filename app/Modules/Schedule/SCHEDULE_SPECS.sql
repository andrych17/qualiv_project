-- =============================================================================
-- Schedule module — schema + example seed data
-- Source spec: app/Modules/Schedule/SCHEDULE_SPECS.md (see that file for full
-- rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), schema "SCHEDULE", no tenant_id
--              column anywhere.
--
-- ZERO hard module dependency, same posture as WNE and SysConfig — the only
-- external table referenced anywhere below is Laravel's own `users`. WNE
-- itself is explicitly a SOFT dependency here (§5: "Schedule must not throw
-- if WNE is simply absent") — schedule.item_created/item_due_soon/
-- item_cancelled are internal events a WNE listener picks up only if
-- installed, never a schema reference, so nothing in this file mentions WNE
-- at all. A vertical module's own record (e.g. a Legal case deadline) links
-- in only via the informational subject_type/subject_id seam on sched_items,
-- same pattern as every other Core module's optional link.
--
-- sched_items is the unified Task/Event backbone (§2 "one calendar view
-- renders both"). One addition beyond what §3B/§3C literally ask for: a
-- table-level CHECK enforces the type-appropriate field shape (a task has
-- due_at and no start/end window; an event has a start/end window and no
-- due_at; status vocabulary is likewise type-scoped) — a direct DB-level
-- expression of the "type discriminator" the spec already describes in
-- prose, flagged here as a reasonable quality addition, consistent with the
-- platform's general CHECK-constraint convention, not scope creep.
--
-- sched_attendees intentionally has only 'attendee'/'watcher' roles, not
-- 'owner' — the item's owner already lives on sched_items.owner_id (§3B/§3C);
-- adding a redundant 'owner' attendee row would be two sources of truth for
-- the same fact.
--
-- Availability conflict detection (§3E) is deliberately NOT a DB-level
-- EXCLUDE/btree_gist constraint here — the spec frames it as a synchronous
-- service call (AvailabilityService::isFree/findConflicts) with a specific
-- user-facing error message, not a schema-level invariant, and adding
-- btree_gist would pull in an extension dependency the spec never asks for.
-- The seed data below demonstrates the conflict a real overlap would
-- produce via a plain verification query instead.
--
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- or other client-specific meta-commands — so it runs through any Postgres
-- client.
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A) — sched_calendar_feeds.token

CREATE SCHEMA IF NOT EXISTS "SCHEDULE";

-- -----------------------------------------------------------------------------
-- §3D / §4. Master tables
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SCHEDULE".resource_types (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,     -- extensible list, not a hardcoded enum (§3D rule)
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "SCHEDULE".resources (
    id              BIGSERIAL PRIMARY KEY,
    resource_type_id BIGINT NOT NULL REFERENCES "SCHEDULE".resource_types (id),
    name             VARCHAR(150) NOT NULL,
    location_notes    TEXT,
    capacity              INT,            -- informational only in v1 — not enforced/pooled (§3D, Future Version note)
    is_active                BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS idx_schedule_resources_type ON "SCHEDULE".resources (resource_type_id, is_active);

CREATE TABLE IF NOT EXISTS "SCHEDULE".conference_providers (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE, -- 'manual', 'zoom', 'google_meet', ... (§3G — additive driver map)
    name        VARCHAR(100) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

-- -----------------------------------------------------------------------------
-- §3B / §3C / §4. sched_items — unified Task/Event backbone
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SCHEDULE".sched_items (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    type                VARCHAR(10) NOT NULL CHECK (type IN ('task', 'event')),
    title               VARCHAR(255) NOT NULL,
    description         TEXT,
    owner_id            BIGINT NOT NULL REFERENCES users (id),
    subject_type        VARCHAR(100), -- optional polymorphic link, e.g. 'legal.case_hdrs' — NOT a FK (§3B/§3C, §5)
    subject_id          BIGINT,
    recurrence_rule     VARCHAR(255), -- RFC 5545 RRULE subset, e.g. 'FREQ=WEEKLY;BYDAY=MO;COUNT=10' (§3F)
    status              VARCHAR(15) NOT NULL,
    priority            VARCHAR(10) CHECK (priority IN ('low', 'normal', 'high')), -- task only
    due_at              TIMESTAMPTZ, -- task only
    start_at            TIMESTAMPTZ, -- event only
    end_at              TIMESTAMPTZ, -- event only
    all_day             BOOLEAN NOT NULL DEFAULT FALSE, -- event only
    location            VARCHAR(255), -- event only, free text
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (
        (type = 'task'  AND status IN ('open', 'in_progress', 'done', 'cancelled')
                          AND due_at IS NOT NULL AND start_at IS NULL AND end_at IS NULL) OR
        (type = 'event' AND status IN ('scheduled', 'cancelled')
                          AND start_at IS NOT NULL AND end_at IS NOT NULL AND due_at IS NULL)
    ),
    CHECK (start_at IS NULL OR end_at IS NULL OR end_at > start_at)
);

CREATE INDEX IF NOT EXISTS idx_schedule_sched_items_owner   ON "SCHEDULE".sched_items (owner_id, status);
CREATE INDEX IF NOT EXISTS idx_schedule_sched_items_subject ON "SCHEDULE".sched_items (subject_type, subject_id);
CREATE INDEX IF NOT EXISTS idx_schedule_sched_items_due     ON "SCHEDULE".sched_items (due_at) WHERE type = 'task' AND status NOT IN ('done', 'cancelled');
CREATE INDEX IF NOT EXISTS idx_schedule_sched_items_range   ON "SCHEDULE".sched_items (start_at, end_at) WHERE type = 'event';

-- -----------------------------------------------------------------------------
-- §3D / §3E / §4. Resource booking pivot
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SCHEDULE".sched_bookings (
    id              BIGSERIAL PRIMARY KEY,
    sched_item_id   BIGINT NOT NULL REFERENCES "SCHEDULE".sched_items (id) ON DELETE CASCADE,
    resource_id     BIGINT NOT NULL REFERENCES "SCHEDULE".resources (id),
    UNIQUE (sched_item_id, resource_id)
);

CREATE INDEX IF NOT EXISTS idx_schedule_sched_bookings_resource ON "SCHEDULE".sched_bookings (resource_id);

-- -----------------------------------------------------------------------------
-- §3C / §4. Attendees — additional people beyond the item's owner
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SCHEDULE".sched_attendees (
    id              BIGSERIAL PRIMARY KEY,
    sched_item_id   BIGINT NOT NULL REFERENCES "SCHEDULE".sched_items (id) ON DELETE CASCADE,
    user_id         BIGINT NOT NULL REFERENCES users (id),
    role            VARCHAR(10) NOT NULL DEFAULT 'attendee' CHECK (role IN ('attendee', 'watcher')),
    UNIQUE (sched_item_id, user_id)
);

-- -----------------------------------------------------------------------------
-- §3F / §4. Recurrence exceptions — per-occurrence overrides
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SCHEDULE".sched_recurrence_exceptions (
    id                          BIGSERIAL PRIMARY KEY,
    sched_item_id               BIGINT NOT NULL REFERENCES "SCHEDULE".sched_items (id) ON DELETE CASCADE,
    original_occurrence_date    DATE NOT NULL,
    action                      VARCHAR(10) NOT NULL CHECK (action IN ('skipped', 'moved', 'modified')),
    override_start_at           TIMESTAMPTZ, -- set for 'moved'/'modified'
    override_end_at             TIMESTAMPTZ,
    UNIQUE (sched_item_id, original_occurrence_date)
);

-- -----------------------------------------------------------------------------
-- §3G / §4. Conference links — one per event that has one
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SCHEDULE".sched_conference_links (
    id                      BIGSERIAL PRIMARY KEY,
    sched_item_id            BIGINT NOT NULL UNIQUE REFERENCES "SCHEDULE".sched_items (id) ON DELETE CASCADE,
    conference_provider_id   BIGINT NOT NULL REFERENCES "SCHEDULE".conference_providers (id),
    join_url                 VARCHAR(500) NOT NULL,
    external_meeting_id      VARCHAR(150), -- for future cancel/update calls against the provider (§3G)
    dial_in_info             TEXT,         -- free text, not structured (§3G — "not worth modeling in v1")
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- §3D / §3E / §4. Optional per-resource weekly working hours
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SCHEDULE".sched_working_hours (
    id              BIGSERIAL PRIMARY KEY,
    resource_id     BIGINT NOT NULL REFERENCES "SCHEDULE".resources (id) ON DELETE CASCADE,
    day_of_week     SMALLINT NOT NULL CHECK (day_of_week BETWEEN 0 AND 6), -- 0 = Sunday, matching ISO/JS convention
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    UNIQUE (resource_id, day_of_week),
    CHECK (end_time > start_time)
);

-- -----------------------------------------------------------------------------
-- §3A / §4. Calendar feeds — signed ICS subscription tokens, per user or
-- per resource (exactly one of the two, never both/neither)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "SCHEDULE".sched_calendar_feeds (
    id              BIGSERIAL PRIMARY KEY,
    token           UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- external-facing, appears in a URL — must not be guessable (§5)
    user_id         BIGINT REFERENCES users (id),
    resource_id     BIGINT REFERENCES "SCHEDULE".resources (id),
    revoked_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK ((user_id IS NOT NULL) <> (resource_id IS NOT NULL))
);

-- =============================================================================
-- NOTE — Future Version tables intentionally NOT created here (§2 MVP scope
-- boundary): resource pools/capacity enforcement, calendar sharing/
-- delegation tables, two-way external sync state, and any SLA/escalation
-- table (that's WNE Workflow territory per §2's own explicit note — not
-- duplicated here).
-- =============================================================================

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- One Task (a filing deadline, linked to a hypothetical Legal case via the
-- informational subject_type/subject_id seam), one one-off Event (a client
-- kickoff meeting with two attendees, two booked resources, and a Zoom
-- link), and one recurring Event (a weekly status call with one skipped
-- occurrence), plus per-resource working hours and one user's ICS feed.
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- or other client-specific meta-commands — so it runs through any Postgres
-- client.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Lookups: resource types, resources, conference providers, working hours
-- ---------------------------------------------------------------------------

INSERT INTO "SCHEDULE".resource_types (code, name) VALUES
    ('ROOM', 'Meeting Room'), ('EQUIPMENT', 'Equipment');

INSERT INTO "SCHEDULE".resources (resource_type_id, name, capacity)
SELECT rt.id, 'Conference Room A', 8 FROM "SCHEDULE".resource_types rt WHERE rt.code = 'ROOM'
UNION ALL
SELECT rt.id, 'Projector', NULL FROM "SCHEDULE".resource_types rt WHERE rt.code = 'EQUIPMENT';

INSERT INTO "SCHEDULE".conference_providers (code, name) VALUES
    ('manual', 'Manual / Custom Link'), ('zoom', 'Zoom');

-- Conference Room A: available Mon-Fri, 09:00-17:00
INSERT INTO "SCHEDULE".sched_working_hours (resource_id, day_of_week, start_time, end_time)
SELECT r.id, d.dow, TIME '09:00', TIME '17:00'
FROM "SCHEDULE".resources r
JOIN (VALUES (1), (2), (3), (4), (5)) AS d (dow) ON TRUE
WHERE r.name = 'Conference Room A';

-- ---------------------------------------------------------------------------
-- 2. A Task: filing deadline linked to a hypothetical Legal case
-- ---------------------------------------------------------------------------

INSERT INTO "SCHEDULE".sched_items (type, title, description, owner_id, subject_type, subject_id, status, priority, due_at)
SELECT 'task', 'File response brief - Case A-001', 'Deadline set by court order.', u.id, 'legal.case_hdrs', 4821,
       'open', 'high', TIMESTAMPTZ '2026-08-20 17:00:00+07'
FROM users u WHERE u.email = 'staff@nusaevo.com';

-- ---------------------------------------------------------------------------
-- 3. A one-off Event: client kickoff meeting, two attendees, two resources,
--    a Zoom link
-- ---------------------------------------------------------------------------

INSERT INTO "SCHEDULE".sched_items (type, title, description, owner_id, status, start_at, end_at, location)
SELECT 'event', 'Client Kickoff Meeting', 'Initial engagement kickoff with Example Corp.', u.id, 'scheduled',
       TIMESTAMPTZ '2026-08-18 10:00:00+07', TIMESTAMPTZ '2026-08-18 11:00:00+07', 'Conference Room A'
FROM users u WHERE u.email = 'staff@nusaevo.com';

INSERT INTO "SCHEDULE".sched_attendees (sched_item_id, user_id, role)
SELECT si.id, u.id, 'attendee'
FROM "SCHEDULE".sched_items si, users u
WHERE si.title = 'Client Kickoff Meeting' AND u.email = 'admin@nusaevo.com';

INSERT INTO "SCHEDULE".sched_bookings (sched_item_id, resource_id)
SELECT si.id, r.id
FROM "SCHEDULE".sched_items si, "SCHEDULE".resources r
WHERE si.title = 'Client Kickoff Meeting' AND r.name IN ('Conference Room A', 'Projector');

INSERT INTO "SCHEDULE".sched_conference_links (sched_item_id, conference_provider_id, join_url, external_meeting_id)
SELECT si.id, cp.id, 'https://zoom.us/j/1234567890', 'zoom-meeting-1234567890'
FROM "SCHEDULE".sched_items si, "SCHEDULE".conference_providers cp
WHERE si.title = 'Client Kickoff Meeting' AND cp.code = 'zoom';

-- ---------------------------------------------------------------------------
-- 4. A recurring Event: weekly status call, one occurrence skipped
-- ---------------------------------------------------------------------------

INSERT INTO "SCHEDULE".sched_items (type, title, owner_id, status, start_at, end_at, recurrence_rule)
SELECT 'event', 'Weekly Status Call', u.id, 'scheduled',
       TIMESTAMPTZ '2026-08-03 09:00:00+07', TIMESTAMPTZ '2026-08-03 09:30:00+07',
       'FREQ=WEEKLY;BYDAY=MO;COUNT=8'
FROM users u WHERE u.email = 'staff@nusaevo.com';

-- The 2026-08-17 occurrence is skipped (public holiday)
INSERT INTO "SCHEDULE".sched_recurrence_exceptions (sched_item_id, original_occurrence_date, action)
SELECT si.id, DATE '2026-08-17', 'skipped'
FROM "SCHEDULE".sched_items si WHERE si.title = 'Weekly Status Call';

-- ---------------------------------------------------------------------------
-- 5. ICS calendar feed for staff@nusaevo.com
-- ---------------------------------------------------------------------------

INSERT INTO "SCHEDULE".sched_calendar_feeds (user_id)
SELECT u.id FROM users u WHERE u.email = 'staff@nusaevo.com';

COMMIT;

-- =============================================================================
-- Sanity check counts
-- =============================================================================
SELECT 'resources' AS table_name, COUNT(*) FROM "SCHEDULE".resources
UNION ALL SELECT 'sched_items', COUNT(*) FROM "SCHEDULE".sched_items
UNION ALL SELECT 'sched_bookings', COUNT(*) FROM "SCHEDULE".sched_bookings
UNION ALL SELECT 'sched_attendees', COUNT(*) FROM "SCHEDULE".sched_attendees
UNION ALL SELECT 'sched_recurrence_exceptions', COUNT(*) FROM "SCHEDULE".sched_recurrence_exceptions
UNION ALL SELECT 'sched_conference_links', COUNT(*) FROM "SCHEDULE".sched_conference_links
UNION ALL SELECT 'sched_working_hours', COUNT(*) FROM "SCHEDULE".sched_working_hours
UNION ALL SELECT 'sched_calendar_feeds', COUNT(*) FROM "SCHEDULE".sched_calendar_feeds;

-- Demonstrates the overlap check §3E's AvailabilityService would run: is
-- Conference Room A free 10:30-11:30 on 2026-08-18? (It should NOT be —
-- overlaps the Kickoff Meeting 10:00-11:00 booked above.)
SELECT si.title, si.start_at, si.end_at
FROM "SCHEDULE".sched_bookings b
JOIN "SCHEDULE".sched_items si ON si.id = b.sched_item_id
JOIN "SCHEDULE".resources r ON r.id = b.resource_id
WHERE r.name = 'Conference Room A'
  AND si.status <> 'cancelled'
  AND si.start_at < TIMESTAMPTZ '2026-08-18 11:30:00+07'
  AND si.end_at   > TIMESTAMPTZ '2026-08-18 10:30:00+07';
