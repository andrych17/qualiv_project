-- =====================================================================
-- SCHEDULE — Dummy / Seed Data
-- Scenario: a small legal firm's calendar — a mix of Tasks and Events,
-- resource bookings (room, room+video, vehicle), a recurring weekly
-- meeting with exceptions, a conference link, working hours, and ICS
-- subscription feeds.
--
-- Single-tenant script: run once inside ONE tenant's database, after
-- schedule_schema.sql. No tenant_id anywhere — isolation is the
-- database boundary (DB-per-tenant), so this script is not multi-tenant
-- like the earlier WNE dummy data was.
--
-- Assumes schedule_schema.sql has already run, which seeds:
--   resource_types: 1=room, 2=equipment, 3=vehicle, 4=staff
--   conference_providers: 1=manual, 2=zoom, 3=google_meet
-- All rows below use EXPLICIT ids so foreign keys are predictable —
-- safe to run once against a freshly migrated schema.
-- =====================================================================

SET search_path TO "SCHEDULE";

-- =====================================================================
-- RESOURCES
-- =====================================================================

INSERT INTO "SCHEDULE".resources (id, resource_type_id, name, location, notes, capacity, is_active) VALUES
    (1, 1, 'Conference Room A',              '3rd Floor, East Wing',  'Standard meeting room, whiteboard',        8,  TRUE),
    (2, 1, 'Conference Room B (Video)',      '3rd Floor, East Wing',  'Video-equipped, camera + mic array',       12, TRUE),
    (3, 2, 'Projector Unit 1',               'Storage Closet 2',      'Portable, HDMI + wireless cast',           NULL, TRUE),
    (4, 3, 'Firm Car - Toyota Innova',       'Basement Parking B1',   'Site visits / court runs',                  6,  TRUE),
    (5, 4, 'Paralegal Pool - Rina W.',       NULL,                    'Shared paralegal, book via Schedule',      NULL, TRUE);
SELECT setval('"SCHEDULE".resources_id_seq', 5);

-- =====================================================================
-- CALENDAR ITEMS (Tasks + Events)
-- =====================================================================

INSERT INTO "SCHEDULE".sched_items
    (id, type, title, description, start_at, end_at, all_day, location, priority, status, owner_id, subject_type, subject_id, recurrence_rule) VALUES
    (1, 'task',  'File motion for Case #2026-0142',
        'Draft and file the motion to compel discovery before the deadline.',
        '2026-07-22 17:00:00+00', NULL, FALSE, NULL, 'high', 'in_progress', 210,
        'App\Modules\Legal\Models\LegalCase', 142, NULL),

    (2, 'task',  'Prepare deposition questions',
        'Outline questions for the Hartono deposition.',
        '2026-07-20 12:00:00+00', NULL, FALSE, NULL, 'normal', 'open', 211,
        NULL, NULL, NULL),

    (3, 'event', 'Client meeting - Wijaya Trust',
        'Quarterly review with the Wijaya Trust beneficiaries.',
        '2026-07-21 02:00:00+00', '2026-07-21 03:00:00+00', FALSE, 'Conference Room A', NULL, 'scheduled', 211,
        NULL, NULL, NULL),

    (4, 'event', 'Weekly Case Review',
        'Standing internal review of all active case statuses.',
        '2026-07-20 01:00:00+00', '2026-07-20 02:00:00+00', FALSE, 'Conference Room B (Video)', NULL, 'scheduled', 210,
        NULL, NULL, 'FREQ=WEEKLY;BYDAY=MO;COUNT=10'),

    (5, 'event', 'Court Hearing - Case #2026-0138',
        'Preliminary hearing before Judge Santoso.',
        '2026-07-24 03:30:00+00', '2026-07-24 05:00:00+00', FALSE, 'Central District Court, Room 4', NULL, 'scheduled', 212,
        'App\Modules\Legal\Models\LegalCase', 138, NULL),

    (6, 'task',  'Send invoice reminder - Brightpath LLC',
        'Follow up on the overdue July retainer invoice.',
        '2026-07-15 09:00:00+00', NULL, FALSE, NULL, 'normal', 'done', 212,
        NULL, NULL, NULL),

    (7, 'event', 'Site visit - property inspection',
        'On-site inspection ahead of the Kusuma property dispute filing.',
        '2026-07-23 01:00:00+00', '2026-07-23 04:00:00+00', FALSE, 'Jl. Merdeka No. 12, Malang', NULL, 'scheduled', 213,
        NULL, NULL, NULL),

    (8, 'event', 'Internal Sync (cancelled)',
        'Cancelled - merged into Weekly Case Review.',
        '2026-07-16 06:00:00+00', '2026-07-16 06:30:00+00', FALSE, 'Conference Room A', NULL, 'cancelled', 210,
        NULL, NULL, NULL);
SELECT setval('"SCHEDULE".sched_items_id_seq', 8);

-- =====================================================================
-- RESOURCE BOOKINGS
-- =====================================================================

INSERT INTO "SCHEDULE".sched_bookings (id, sched_item_id, resource_id, status) VALUES
    (1, 3, 1, 'confirmed'),   -- Client meeting -> Room A
    (2, 4, 2, 'confirmed'),   -- Weekly Case Review -> Room B (video)
    (3, 7, 4, 'confirmed'),   -- Site visit -> Firm Car
    (4, 7, 5, 'confirmed'),   -- Site visit -> Paralegal Rina (accompanying)
    (5, 8, 1, 'cancelled');   -- Internal Sync -> Room A, booking cancelled with the event
SELECT setval('"SCHEDULE".sched_bookings_id_seq', 5);

-- =====================================================================
-- ATTENDEES
-- =====================================================================

INSERT INTO "SCHEDULE".sched_attendees (id, sched_item_id, user_id, role, response_status) VALUES
    (1, 1, 210, 'owner',    'accepted'),
    (2, 1, 506, 'watcher',  'pending'),

    (3, 3, 211, 'owner',    'accepted'),
    (4, 3, 501, 'attendee', 'accepted'),
    (5, 3, 502, 'attendee', 'pending'),

    (6, 4, 210, 'owner',    'accepted'),
    (7, 4, 503, 'attendee', 'accepted'),
    (8, 4, 504, 'attendee', 'accepted'),
    (9, 4, 505, 'watcher',  'pending'),

    (10, 7, 213, 'owner',    'accepted'),
    (11, 7, 507, 'attendee', 'accepted');
SELECT setval('"SCHEDULE".sched_attendees_id_seq', 11);

-- =====================================================================
-- RECURRENCE EXCEPTIONS (against item 4, "Weekly Case Review")
-- =====================================================================

INSERT INTO "SCHEDULE".sched_recurrence_exceptions
    (id, sched_item_id, original_occurrence_date, action, override_start_at, override_end_at, override_title) VALUES
    (1, 4, '2026-08-17', 'skipped', NULL, NULL, NULL),                                    -- national holiday, series skips this week
    (2, 4, '2026-08-24', 'moved',   '2026-08-24 03:00:00+00', '2026-08-24 04:00:00+00', NULL); -- moved 2h later that week only
SELECT setval('"SCHEDULE".sched_recurrence_exceptions_id_seq', 2);

-- =====================================================================
-- CONFERENCE LINK (item 4, "Weekly Case Review")
-- =====================================================================

INSERT INTO "SCHEDULE".sched_conference_links
    (id, sched_item_id, conference_provider_id, join_url, external_meeting_id, dial_in_info, host_key) VALUES
    (1, 4, 2, 'https://zoom.us/j/88812345678', '888-1234-5678',
        'Dial +1 646 558 8656, Meeting ID: 888 1234 5678', 'hostkey-4471');
SELECT setval('"SCHEDULE".sched_conference_links_id_seq', 1);

-- =====================================================================
-- WORKING HOURS
-- =====================================================================

-- Conference Room A: Mon-Fri 09:00-17:00
INSERT INTO "SCHEDULE".sched_working_hours (id, resource_id, day_of_week, start_time, end_time) VALUES
    (1, 1, 1, '09:00', '17:00'),
    (2, 1, 2, '09:00', '17:00'),
    (3, 1, 3, '09:00', '17:00'),
    (4, 1, 4, '09:00', '17:00'),
    (5, 1, 5, '09:00', '17:00'),
-- Conference Room B: Mon-Fri 08:00-18:00 (video calls run earlier/later for client timezones)
    (6, 2, 1, '08:00', '18:00'),
    (7, 2, 2, '08:00', '18:00'),
    (8, 2, 3, '08:00', '18:00'),
    (9, 2, 4, '08:00', '18:00'),
    (10, 2, 5, '08:00', '18:00'),
-- Firm Car: Mon-Sat 07:00-19:00
    (11, 4, 1, '07:00', '19:00'),
    (12, 4, 2, '07:00', '19:00'),
    (13, 4, 3, '07:00', '19:00'),
    (14, 4, 4, '07:00', '19:00'),
    (15, 4, 5, '07:00', '19:00'),
    (16, 4, 6, '07:00', '19:00');
SELECT setval('"SCHEDULE".sched_working_hours_id_seq', 16);

-- =====================================================================
-- CALENDAR FEEDS (ICS subscription tokens)
-- =====================================================================

INSERT INTO "SCHEDULE".sched_calendar_feeds (id, token, owner_type, owner_ref, is_active, last_accessed_at) VALUES
    (1, 'a1b2c3d4-1111-4a2b-9c3d-000000000001', 'user',     210, TRUE, '2026-07-17 01:15:00+00'),
    (2, 'a1b2c3d4-2222-4a2b-9c3d-000000000002', 'user',     211, TRUE, NULL),
    (3, 'a1b2c3d4-3333-4a2b-9c3d-000000000003', 'resource', 1,   TRUE, '2026-07-16 23:50:00+00'); -- reception display feed for Room A
SELECT setval('"SCHEDULE".sched_calendar_feeds_id_seq', 3);
