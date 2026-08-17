-- =============================================================================
-- WNE module — schema + example seed data
-- Source spec: app/Modules/WNE/WNE_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB — every table lives inside one tenant's own
--              database (CLAUDE.md §4/§7A), schema "WNE", no tenant_id column
--              anywhere.
--
-- ZERO MODULE DEPENDENCY (§5 "Feature-flag independence"). Even lighter than
-- SYSCONFIG's posture: the ONLY external table referenced anywhere below is
-- Laravel's own `users`. Not even "SYSCONFIG".groups gets a real FK, despite
-- SYSCONFIG now being built before WNE in practice (its own spec's build-order
-- correction) — §5 is explicit that WNE "should not even assume which other
-- Core modules are installed," and §5's cross-schema-reads note says WNE
-- resolves roles/teams/CRM.partners only via "a read through the owning
-- module's facade, never a direct cross-schema FK." So wrkflow_instance_steps.
-- assigned_team_id and msg_notifications.recipient_type/recipient_id below are
-- informational BIGINT/VARCHAR columns, no FK — even though in a real deployed
-- tenant SYSCONFIG.groups will almost always exist by the time WNE runs, this
-- script honors WNE's own spec's explicit, deliberately stricter posture
-- rather than the general build-order fact.
--
-- wrkflow_tasks (§4) is built as a VIEW, not a physical table — §4 itself
-- offers this as an explicit alternative ("denormalized ... rows (or a query
-- view)"), and a view avoids a second copy of wrkflow_instance_steps state
-- that could drift.
--
-- msg_digests IS created here, stubbed empty — the ONE deliberate exception
-- across every *_SPECS.sql script so far to the usual "don't create Future
-- Version tables" rule. Every other module's Future Version items get no
-- table at all; §4 explicitly instructs this one to be "stubbed empty at
-- launch," a different instruction from "don't build it," so it's honored
-- literally here (no columns beyond what §4 already implies, no logic).
--
-- Comment-only immutability (no blocking trigger) on wrkflow_audit_logs and
-- msg_delivery_events, matching the established convention for every other
-- append-only log table across this platform (DMS.access_logs,
-- ACCOUNTING.audit_logs, PAYROLL.payroll_access_logs, SYSCONFIG.config_audit_logs).
--
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- or other client-specific meta-commands — so it runs through any Postgres
-- client. Row-creating INSERTs are split into their own top-level statements
-- ahead of any statement that needs to UPDATE that same row (the recurring
-- same-statement/same-snapshot CTE limitation documented since DMS_SPECS.sql).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

CREATE SCHEMA IF NOT EXISTS "WNE";

-- -----------------------------------------------------------------------------
-- §4. Master / lookup tables
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_categories (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "WNE".msg_categories (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(50) NOT NULL UNIQUE,
    name                VARCHAR(100) NOT NULL,
    is_mandatory        BOOLEAN NOT NULL DEFAULT FALSE, -- opt-out-blocked flag (§3J) — security/critical categories only
    digestible          BOOLEAN NOT NULL DEFAULT FALSE, -- Future Version flag (§3N), reserved now so no later migration
    default_channels    TEXT[] NOT NULL DEFAULT '{}',   -- applies when a user has set no explicit preference (§3J rule)
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "WNE".channel_types (
    id      BIGSERIAL PRIMARY KEY,
    code    VARCHAR(10) NOT NULL UNIQUE CHECK (code IN ('email', 'sms', 'push', 'in_app', 'webhook')),
    label   VARCHAR(50) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "WNE".msg_channel_configs (
    id                          BIGSERIAL PRIMARY KEY,
    channel_type_id             BIGINT NOT NULL UNIQUE REFERENCES "WNE".channel_types (id), -- one config per channel per tenant (DB is already the tenant boundary)
    provider_credentials_encrypted TEXT, -- app-layer encrypted at rest — security-sensitive field
    max_attempts                   INT NOT NULL DEFAULT 4,
    backoff_schedule                JSONB NOT NULL DEFAULT '["1m", "5m", "30m", "2h"]', -- exponential by default (§3M)
    is_enabled                        BOOLEAN NOT NULL DEFAULT FALSE -- SMS/Push stay disabled until a tenant configures credentials (§3I)
);

CREATE TABLE IF NOT EXISTS "WNE".msg_templates (
    id              BIGSERIAL PRIMARY KEY,
    category_id     BIGINT NOT NULL REFERENCES "WNE".msg_categories (id),
    channel_type_id BIGINT NOT NULL REFERENCES "WNE".channel_types (id),
    locale          VARCHAR(10) NOT NULL DEFAULT 'id',
    subject         VARCHAR(255),           -- email/push title; NULL for channels with no subject concept (in_app, sms)
    body            TEXT NOT NULL,          -- {{variable}} placeholder syntax, resolved at send time (§3L)
    variables       TEXT[] NOT NULL DEFAULT '{}', -- documented variable list for the admin authoring it
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (category_id, channel_type_id, locale)
);

-- -----------------------------------------------------------------------------
-- §3B / §3C / §3E / §4. Workflow Definition, Version, Step, Transition
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_definitions (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(100) NOT NULL UNIQUE, -- what a calling module references, e.g. 'hcm.leave_approval' (§3B rule)
    name            VARCHAR(200) NOT NULL,
    description     TEXT,
    category_id     BIGINT REFERENCES "WNE".wrkflow_categories (id),
    status          VARCHAR(15) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'unpublished')),
    created_by      BIGINT REFERENCES users (id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Immutable per-publish snapshot (§3B/§3E): editing a published definition
-- always edits a new draft; publishing again creates version N+1.
CREATE TABLE IF NOT EXISTS "WNE".wrkflow_versions (
    id              BIGSERIAL PRIMARY KEY,
    definition_id   BIGINT NOT NULL REFERENCES "WNE".wrkflow_definitions (id) ON DELETE CASCADE,
    version_no      INT NOT NULL,
    published_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    published_by    BIGINT REFERENCES users (id),
    UNIQUE (definition_id, version_no)
);

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_steps (
    id              BIGSERIAL PRIMARY KEY,
    version_id      BIGINT NOT NULL REFERENCES "WNE".wrkflow_versions (id) ON DELETE CASCADE,
    step_code       VARCHAR(50) NOT NULL,   -- stable within a version; used by transitions and entry-step lookup
    type            VARCHAR(20) NOT NULL CHECK (type IN ('approval', 'task', 'condition', 'parallel_split',
                                                            'parallel_join', 'webhook_call', 'wait_for_callback', 'notify')),
    config          JSONB NOT NULL DEFAULT '{}', -- assignee rule / condition expr / webhook url+template / template ref, per type (§3B)
    pos_x           NUMERIC(10, 2),         -- unused by v1 form UI; captured now so the Future Version canvas needs no migration (§3B)
    pos_y           NUMERIC(10, 2),
    is_entry_step   BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE (version_id, step_code)
);

-- Exactly one entry step per version — where WorkflowService::start() creates
-- the first wrkflow_instance_steps row (§3C).
CREATE UNIQUE INDEX IF NOT EXISTS uq_wne_wrkflow_steps_entry
    ON "WNE".wrkflow_steps (version_id) WHERE is_entry_step;

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_transitions (
    id                      BIGSERIAL PRIMARY KEY,
    from_step_id            BIGINT NOT NULL REFERENCES "WNE".wrkflow_steps (id) ON DELETE CASCADE,
    to_step_id              BIGINT NOT NULL REFERENCES "WNE".wrkflow_steps (id),
    condition_expression    JSONB, -- NULL = the mandatory default/"else" transition (§3D rule) — evaluated against the payload snapshot
    seq                     INT NOT NULL DEFAULT 0, -- evaluation order — first matching transition wins (§3D)
    CHECK (from_step_id <> to_step_id)
);

CREATE INDEX IF NOT EXISTS idx_wne_wrkflow_transitions_from ON "WNE".wrkflow_transitions (from_step_id, seq);

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_sla_rules (
    id                  BIGSERIAL PRIMARY KEY,
    step_id             BIGINT REFERENCES "WNE".wrkflow_steps (id) ON DELETE CASCADE,    -- per-step SLA
    version_id          BIGINT REFERENCES "WNE".wrkflow_versions (id) ON DELETE CASCADE, -- OR a definition-version default
    sla_hours           NUMERIC(6, 2) NOT NULL,
    escalation_action    VARCHAR(30) NOT NULL
                             CHECK (escalation_action IN ('reassign_to_role', 'notify_manager_of_assignee', 'notify_role')),
    escalation_target       VARCHAR(100), -- role code / user reference, per escalation_action
    CHECK (step_id IS NOT NULL OR version_id IS NOT NULL)
);

-- -----------------------------------------------------------------------------
-- §3C / §3D / §4. Workflow Instance Engine — the durable execution core
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_instances (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    definition_version_id   BIGINT NOT NULL REFERENCES "WNE".wrkflow_versions (id), -- fixed at start, NEVER updated after insert (§3E/§5)
    subject_type            VARCHAR(100), -- optional polymorphic link, e.g. 'hcm.leave_requests' — NOT a FK (§3C rule)
    subject_id              BIGINT,
    status                  VARCHAR(15) NOT NULL DEFAULT 'running'
                                 CHECK (status IN ('running', 'completed', 'failed', 'cancelled')),
    payload                 JSONB NOT NULL DEFAULT '{}', -- snapshot taken at start (§3D rule) — never a live re-query
    started_by              BIGINT REFERENCES users (id),
    started_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    ended_at                TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_wne_wrkflow_instances_subject ON "WNE".wrkflow_instances (subject_type, subject_id);
CREATE INDEX IF NOT EXISTS idx_wne_wrkflow_instances_status  ON "WNE".wrkflow_instances (status);

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_instance_steps (
    id                  BIGSERIAL PRIMARY KEY,
    instance_id         BIGINT NOT NULL REFERENCES "WNE".wrkflow_instances (id) ON DELETE CASCADE,
    step_id             BIGINT NOT NULL REFERENCES "WNE".wrkflow_steps (id),
    status              VARCHAR(20) NOT NULL DEFAULT 'pending'
                             CHECK (status IN ('pending', 'in_progress', 'completed', 'failed', 'skipped', 'cancelled', 'waiting_external')),
    assigned_to         BIGINT REFERENCES users (id),
    assigned_role       VARCHAR(50),
    assigned_team_id    BIGINT, -- informational only — no FK, WNE does not assume any module owns "team" (§5, see header note)
    due_at              TIMESTAMPTZ, -- computed at step-start from the matching wrkflow_sla_rules.sla_hours (§3F)
    attempt             INT NOT NULL DEFAULT 1,
    idempotency_key     VARCHAR(150) UNIQUE, -- derived (instance_id, step_id, attempt) at app layer (§5 "core design decision")
    started_at          TIMESTAMPTZ,
    completed_at        TIMESTAMPTZ,
    decision            VARCHAR(50),  -- 'approve' / 'reject' / a custom decision set defined on the step (§3H)
    comment             TEXT
);

CREATE INDEX IF NOT EXISTS idx_wne_wrkflow_instance_steps_assignee ON "WNE".wrkflow_instance_steps (assigned_to, status);
CREATE INDEX IF NOT EXISTS idx_wne_wrkflow_instance_steps_due      ON "WNE".wrkflow_instance_steps (due_at) WHERE status IN ('pending', 'in_progress');
CREATE INDEX IF NOT EXISTS idx_wne_wrkflow_instance_steps_instance ON "WNE".wrkflow_instance_steps (instance_id);

-- §3H "My Approvals / Task Inbox" — built as a VIEW, not a physical table
-- (§4 offers this as an explicit alternative to a denormalized table; a view
-- can never drift from wrkflow_instance_steps, the actual source of truth).
CREATE OR REPLACE VIEW "WNE".wrkflow_tasks AS
SELECT
    wis.id                  AS instance_step_id,
    wis.instance_id,
    wi.subject_type,
    wi.subject_id,
    ws.type                 AS step_type,
    ws.config                AS step_config,
    wis.assigned_to,
    wis.assigned_role,
    wis.assigned_team_id,
    wis.status,
    wis.due_at
FROM "WNE".wrkflow_instance_steps wis
JOIN "WNE".wrkflow_instances wi ON wi.id = wis.instance_id
JOIN "WNE".wrkflow_steps ws ON ws.id = wis.step_id
WHERE wis.status IN ('pending', 'in_progress', 'waiting_external');

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_escalation_log (
    id                      BIGSERIAL PRIMARY KEY,
    instance_step_id        BIGINT NOT NULL REFERENCES "WNE".wrkflow_instance_steps (id) ON DELETE CASCADE,
    sla_rule_id              BIGINT REFERENCES "WNE".wrkflow_sla_rules (id),
    escalated_to_user_id        BIGINT REFERENCES users (id),
    escalated_to_role              VARCHAR(50),
    escalated_at                       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_webhooks (
    id                  BIGSERIAL PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    url                 VARCHAR(500) NOT NULL,
    http_method         VARCHAR(10) NOT NULL DEFAULT 'POST' CHECK (http_method IN ('GET', 'POST', 'PUT', 'PATCH')),
    payload_template    JSONB NOT NULL DEFAULT '{}',
    auth_header_encrypted TEXT, -- app-layer encrypted at rest — security-sensitive field
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
    -- Referenced from a webhook_call step's own config JSON (by id/name), not a schema FK — the
    -- step<->webhook link is data inside wrkflow_steps.config, matching every other step-type's
    -- config-driven shape (§3B) rather than one-off columns per step type.
);

CREATE TABLE IF NOT EXISTS "WNE".wrkflow_callbacks (
    id                  BIGSERIAL PRIMARY KEY,
    instance_step_id    BIGINT NOT NULL REFERENCES "WNE".wrkflow_instance_steps (id) ON DELETE CASCADE,
    token               UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- POST /api/wne/callbacks/{token} (§3G)
    expires_at          TIMESTAMPTZ NOT NULL,
    consumed_at         TIMESTAMPTZ -- a replayed/late callback after this is set, or past expires_at, is rejected (§3G rule)
);

-- Append-only (comment-only immutability, see header note)
CREATE TABLE IF NOT EXISTS "WNE".wrkflow_audit_logs (
    id                  BIGSERIAL PRIMARY KEY,
    instance_id         BIGINT REFERENCES "WNE".wrkflow_instances (id),
    instance_step_id    BIGINT REFERENCES "WNE".wrkflow_instance_steps (id),
    event_type          VARCHAR(30) NOT NULL, -- 'instance_started' / 'step_completed' / 'decision_made' / 'instance_cancelled' / ...
    actor_id            BIGINT REFERENCES users (id),
    detail              JSONB,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_wne_wrkflow_audit_logs_instance ON "WNE".wrkflow_audit_logs (instance_id, created_at);

-- -----------------------------------------------------------------------------
-- §3I / §3J / §3K / §3L / §3M / §3O / §4. Notification Module
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "WNE".msg_user_preferences (
    id                  BIGSERIAL PRIMARY KEY,
    user_id             BIGINT NOT NULL REFERENCES users (id),
    category_id         BIGINT NOT NULL REFERENCES "WNE".msg_categories (id),
    preferred_channels  TEXT[] NOT NULL DEFAULT '{}', -- multi-select, e.g. {'email','in_app'}
    opted_out           BOOLEAN NOT NULL DEFAULT FALSE, -- app-enforced: can only be TRUE when the category is NOT is_mandatory (§3J)
    quiet_hours_start   TIME,
    quiet_hours_end     TIME,
    UNIQUE (user_id, category_id)
);

CREATE TABLE IF NOT EXISTS "WNE".msg_notifications (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    category_id         BIGINT NOT NULL REFERENCES "WNE".msg_categories (id),
    subject_type        VARCHAR(100), -- optional polymorphic source link — NOT a FK (§4)
    subject_id          BIGINT,
    recipient_user_id   BIGINT REFERENCES users (id), -- internal-user recipient (real FK — users is foundational)
    recipient_type      VARCHAR(100), -- informational, e.g. 'crm.partners' — set when the recipient isn't a platform user (§5)
    recipient_id        BIGINT,       -- informational; no FK, resolved via the owning module's facade (§5)
    payload             JSONB NOT NULL DEFAULT '{}',
    priority             VARCHAR(10) NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (recipient_user_id IS NOT NULL OR (recipient_type IS NOT NULL AND recipient_id IS NOT NULL))
);

CREATE INDEX IF NOT EXISTS idx_wne_msg_notifications_recipient ON "WNE".msg_notifications (recipient_user_id);
CREATE INDEX IF NOT EXISTS idx_wne_msg_notifications_subject   ON "WNE".msg_notifications (subject_type, subject_id);

CREATE TABLE IF NOT EXISTS "WNE".msg_notification_deliveries (
    id                      BIGSERIAL PRIMARY KEY,
    notification_id         BIGINT NOT NULL REFERENCES "WNE".msg_notifications (id) ON DELETE CASCADE,
    channel_type_id          BIGINT NOT NULL REFERENCES "WNE".channel_types (id),
    status                       VARCHAR(15) NOT NULL DEFAULT 'queued'
                                     CHECK (status IN ('queued', 'sending', 'sent', 'delivered', 'failed', 'retrying', 'deferred', 'dead_lettered')),
    provider_message_id             VARCHAR(150),
    attempt_count                       INT NOT NULL DEFAULT 0,
    next_retry_at                           TIMESTAMPTZ, -- also used for quiet-hours deferral (§5 "reuses the same retry-scheduling mechanism")
    sent_at                                     TIMESTAMPTZ,
    delivered_at                                    TIMESTAMPTZ,
    error_detail                                        TEXT,
    created_at                                              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                 TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_wne_msg_deliveries_notification ON "WNE".msg_notification_deliveries (notification_id);
CREATE INDEX IF NOT EXISTS idx_wne_msg_deliveries_retry_queue  ON "WNE".msg_notification_deliveries (next_retry_at) WHERE status IN ('retrying', 'deferred');

-- Append-only (comment-only immutability, see header note)
CREATE TABLE IF NOT EXISTS "WNE".msg_delivery_events (
    id              BIGSERIAL PRIMARY KEY,
    delivery_id     BIGINT NOT NULL REFERENCES "WNE".msg_notification_deliveries (id) ON DELETE CASCADE,
    event_type      VARCHAR(15) NOT NULL CHECK (event_type IN ('created', 'queued', 'sending', 'sent', 'delivered',
                                                                  'opened', 'bounced', 'failed', 'retrying', 'dead_lettered')),
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    provider_payload JSONB -- raw provider callback detail (bounce reason, message id, ...) (§3O)
);

CREATE INDEX IF NOT EXISTS idx_wne_msg_delivery_events_delivery ON "WNE".msg_delivery_events (delivery_id, occurred_at);

CREATE TABLE IF NOT EXISTS "WNE".msg_dead_letters (
    id                  BIGSERIAL PRIMARY KEY,
    delivery_id         BIGINT NOT NULL UNIQUE REFERENCES "WNE".msg_notification_deliveries (id),
    full_message        JSONB NOT NULL,     -- full message + failure history preserved (§3M)
    failure_history      JSONB NOT NULL,
    dead_lettered_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_action              VARCHAR(10) CHECK (resolved_action IN ('resend', 'discard')), -- never a silent drop (§3M rule)
    resolved_by                      BIGINT REFERENCES users (id),
    resolved_at                          TIMESTAMPTZ
);

-- Future Version (§3N), stubbed empty at launch per §4's own explicit
-- instruction — see header note for why this is the one exception to the
-- "don't build Future Version tables" rule used everywhere else.
CREATE TABLE IF NOT EXISTS "WNE".msg_digests (
    id              BIGSERIAL PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users (id),
    period_start    TIMESTAMPTZ NOT NULL,
    period_end      TIMESTAMPTZ NOT NULL,
    status          VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent')),
    sent_at         TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- Two workflow definitions:
--   1. 'demo.expense_approval' — sequential -> conditional branch (manager
--      decision) -> a second conditional branch (amount threshold) ->
--      finance approval -> notify. Walked through to completion, with one
--      SLA breach + escalation logged along the way, and full audit trail.
--   2. 'demo.webhook_integration' — webhook_call -> wait_for_callback,
--      demonstrating the pause-and-resume seam (§3G) with an
--      in-flight, not-yet-consumed callback token.
-- Plus: notification categories/channels/templates, a delivered email+in_app
-- notification, and a second notification that exhausts retries and lands in
-- the DLQ (§3M).
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset
-- or other client-specific meta-commands — so it runs through any Postgres
-- client.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Lookups: channel types, msg categories, channel configs, templates
-- ---------------------------------------------------------------------------

INSERT INTO "WNE".channel_types (code, label) VALUES
    ('email', 'Email'), ('sms', 'SMS'), ('push', 'Push'), ('in_app', 'In-App'), ('webhook', 'Webhook');

INSERT INTO "WNE".msg_channel_configs (channel_type_id, max_attempts, is_enabled)
SELECT id, 4, TRUE FROM "WNE".channel_types WHERE code = 'email'
UNION ALL
SELECT id, 4, TRUE FROM "WNE".channel_types WHERE code = 'in_app'
UNION ALL
SELECT id, 3, FALSE FROM "WNE".channel_types WHERE code = 'sms'; -- credential-gated, not configured for this demo tenant (§3I)

INSERT INTO "WNE".msg_categories (code, name, is_mandatory, default_channels) VALUES
    ('expense_approved', 'Expense Approved', FALSE, ARRAY['email', 'in_app']),
    ('expense_rejected', 'Expense Rejected', FALSE, ARRAY['email', 'in_app']),
    ('sla_breach', 'SLA Breach Alert', TRUE, ARRAY['email', 'in_app']); -- mandatory: cannot be opted out of

INSERT INTO "WNE".wrkflow_categories (name) VALUES ('Finance'), ('Integrations');

INSERT INTO "WNE".msg_templates (category_id, channel_type_id, locale, subject, body, variables)
SELECT c.id, ch.id, 'en', 'Your expense report was approved', 'Hi {{employee_name}}, your expense report for {{amount}} was approved.', ARRAY['employee_name', 'amount']
FROM "WNE".msg_categories c, "WNE".channel_types ch WHERE c.code = 'expense_approved' AND ch.code = 'email'
UNION ALL
SELECT c.id, ch.id, 'en', NULL, 'Expense report {{amount}} approved.', ARRAY['amount']
FROM "WNE".msg_categories c, "WNE".channel_types ch WHERE c.code = 'expense_approved' AND ch.code = 'in_app';

-- ---------------------------------------------------------------------------
-- 2. Definition 1: demo.expense_approval — build, publish (version 1)
-- ---------------------------------------------------------------------------

INSERT INTO "WNE".wrkflow_definitions (code, name, category_id, status, created_by)
SELECT 'demo.expense_approval', 'Expense Approval', wc.id, 'published',
       (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "WNE".wrkflow_categories wc WHERE wc.name = 'Finance';

INSERT INTO "WNE".wrkflow_versions (definition_id, version_no, published_by)
SELECT d.id, 1, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "WNE".wrkflow_definitions d WHERE d.code = 'demo.expense_approval';

INSERT INTO "WNE".wrkflow_steps (version_id, step_code, type, config, is_entry_step)
SELECT v.id, s.step_code, s.type, s.config::jsonb, s.is_entry
FROM "WNE".wrkflow_versions v
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.expense_approval'
JOIN (VALUES
    ('submit',             'task',     '{"assignee": {"type": "payload_field", "field": "submitter_id"}}', TRUE),
    ('manager_approval',   'approval', '{"assignee": {"type": "role", "role": "manager"}}', FALSE),
    ('amount_check',       'condition','{}', FALSE),
    ('finance_approval',   'approval', '{"assignee": {"type": "role", "role": "finance_lead"}}', FALSE),
    ('notify_approved',    'notify',   '{"category_code": "expense_approved"}', FALSE),
    ('notify_rejected',    'notify',   '{"category_code": "expense_rejected"}', FALSE)
) AS s (step_code, type, config, is_entry) ON TRUE;

INSERT INTO "WNE".wrkflow_transitions (from_step_id, to_step_id, condition_expression, seq)
SELECT f.id, t.id, NULL, 0
FROM "WNE".wrkflow_steps f, "WNE".wrkflow_steps t
WHERE f.version_id = t.version_id AND f.step_code = 'submit' AND t.step_code = 'manager_approval'
UNION ALL
SELECT f.id, t.id, '{"field": "decision", "op": "=", "value": "approve"}'::jsonb, 1
FROM "WNE".wrkflow_steps f, "WNE".wrkflow_steps t
WHERE f.version_id = t.version_id AND f.step_code = 'manager_approval' AND t.step_code = 'amount_check'
UNION ALL
SELECT f.id, t.id, NULL, 2 -- default/"else": manager rejected
FROM "WNE".wrkflow_steps f, "WNE".wrkflow_steps t
WHERE f.version_id = t.version_id AND f.step_code = 'manager_approval' AND t.step_code = 'notify_rejected'
UNION ALL
SELECT f.id, t.id, '{"field": "amount", "op": ">", "value": 5000000}'::jsonb, 1
FROM "WNE".wrkflow_steps f, "WNE".wrkflow_steps t
WHERE f.version_id = t.version_id AND f.step_code = 'amount_check' AND t.step_code = 'finance_approval'
UNION ALL
SELECT f.id, t.id, NULL, 2 -- default/"else": under threshold, no second approval needed
FROM "WNE".wrkflow_steps f, "WNE".wrkflow_steps t
WHERE f.version_id = t.version_id AND f.step_code = 'amount_check' AND t.step_code = 'notify_approved'
UNION ALL
SELECT f.id, t.id, '{"field": "decision", "op": "=", "value": "approve"}'::jsonb, 1
FROM "WNE".wrkflow_steps f, "WNE".wrkflow_steps t
WHERE f.version_id = t.version_id AND f.step_code = 'finance_approval' AND t.step_code = 'notify_approved'
UNION ALL
SELECT f.id, t.id, NULL, 2
FROM "WNE".wrkflow_steps f, "WNE".wrkflow_steps t
WHERE f.version_id = t.version_id AND f.step_code = 'finance_approval' AND t.step_code = 'notify_rejected';

INSERT INTO "WNE".wrkflow_sla_rules (step_id, sla_hours, escalation_action, escalation_target)
SELECT s.id, 24, 'notify_manager_of_assignee', 'manager'
FROM "WNE".wrkflow_steps s WHERE s.step_code = 'manager_approval';

-- ---------------------------------------------------------------------------
-- 3. Instance walked through to completion (submit -> manager approve ->
--    amount over threshold -> finance approve -> notify_approved), with one
--    SLA breach + escalation logged on manager_approval before it completed
-- ---------------------------------------------------------------------------

INSERT INTO "WNE".wrkflow_instances (definition_version_id, subject_type, subject_id, status, payload, started_by, started_at, ended_at)
SELECT v.id, 'demo.expense_reports', 501, 'completed',
       jsonb_build_object('submitter_id', (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), 'amount', 7500000),
       (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), TIMESTAMPTZ '2026-08-01 09:00:00+07', TIMESTAMPTZ '2026-08-03 11:00:00+07'
FROM "WNE".wrkflow_versions v
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.expense_approval';

INSERT INTO "WNE".wrkflow_instance_steps
    (instance_id, step_id, status, assigned_to, due_at, attempt, idempotency_key, started_at, completed_at, decision)
SELECT i.id, s.id, 'completed', (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), NULL, 1,
       i.id || ':' || s.id || ':1', TIMESTAMPTZ '2026-08-01 09:00:00+07', TIMESTAMPTZ '2026-08-01 09:00:00+07', NULL
FROM "WNE".wrkflow_instances i
JOIN "WNE".wrkflow_versions v ON v.id = i.definition_version_id
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.expense_approval'
JOIN "WNE".wrkflow_steps s ON s.version_id = v.id AND s.step_code = 'submit'
WHERE i.subject_id = 501;

-- manager_approval: breached its 24h SLA once, escalated, then completed on attempt 2
INSERT INTO "WNE".wrkflow_instance_steps
    (instance_id, step_id, status, assigned_to, assigned_role, due_at, attempt, idempotency_key, started_at, completed_at, decision, comment)
SELECT i.id, s.id, 'completed', (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'manager',
       TIMESTAMPTZ '2026-08-02 09:00:00+07', 2, i.id || ':' || s.id || ':2',
       TIMESTAMPTZ '2026-08-01 09:00:00+07', TIMESTAMPTZ '2026-08-02 15:00:00+07', 'approve', 'Approved after escalation nudge.'
FROM "WNE".wrkflow_instances i
JOIN "WNE".wrkflow_versions v ON v.id = i.definition_version_id
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.expense_approval'
JOIN "WNE".wrkflow_steps s ON s.version_id = v.id AND s.step_code = 'manager_approval'
WHERE i.subject_id = 501;

INSERT INTO "WNE".wrkflow_escalation_log (instance_step_id, sla_rule_id, escalated_to_user_id, escalated_to_role, escalated_at)
SELECT wis.id, sr.id, (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'manager', TIMESTAMPTZ '2026-08-02 09:05:00+07'
FROM "WNE".wrkflow_instance_steps wis
JOIN "WNE".wrkflow_steps s ON s.id = wis.step_id AND s.step_code = 'manager_approval'
JOIN "WNE".wrkflow_sla_rules sr ON sr.step_id = s.id
JOIN "WNE".wrkflow_instances i ON i.id = wis.instance_id AND i.subject_id = 501;

INSERT INTO "WNE".wrkflow_instance_steps (instance_id, step_id, status, attempt, idempotency_key, started_at, completed_at)
SELECT i.id, s.id, 'completed', 1, i.id || ':' || s.id || ':1', TIMESTAMPTZ '2026-08-02 15:00:00+07', TIMESTAMPTZ '2026-08-02 15:00:00+07'
FROM "WNE".wrkflow_instances i
JOIN "WNE".wrkflow_versions v ON v.id = i.definition_version_id
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.expense_approval'
JOIN "WNE".wrkflow_steps s ON s.version_id = v.id AND s.step_code = 'amount_check'
WHERE i.subject_id = 501;

INSERT INTO "WNE".wrkflow_instance_steps
    (instance_id, step_id, status, assigned_to, assigned_role, attempt, idempotency_key, started_at, completed_at, decision)
SELECT i.id, s.id, 'completed', (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'finance_lead', 1,
       i.id || ':' || s.id || ':1', TIMESTAMPTZ '2026-08-02 15:05:00+07', TIMESTAMPTZ '2026-08-03 10:55:00+07', 'approve'
FROM "WNE".wrkflow_instances i
JOIN "WNE".wrkflow_versions v ON v.id = i.definition_version_id
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.expense_approval'
JOIN "WNE".wrkflow_steps s ON s.version_id = v.id AND s.step_code = 'finance_approval'
WHERE i.subject_id = 501;

INSERT INTO "WNE".wrkflow_instance_steps (instance_id, step_id, status, attempt, idempotency_key, started_at, completed_at)
SELECT i.id, s.id, 'completed', 1, i.id || ':' || s.id || ':1', TIMESTAMPTZ '2026-08-03 11:00:00+07', TIMESTAMPTZ '2026-08-03 11:00:00+07'
FROM "WNE".wrkflow_instances i
JOIN "WNE".wrkflow_versions v ON v.id = i.definition_version_id
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.expense_approval'
JOIN "WNE".wrkflow_steps s ON s.version_id = v.id AND s.step_code = 'notify_approved'
WHERE i.subject_id = 501;

INSERT INTO "WNE".wrkflow_audit_logs (instance_id, instance_step_id, event_type, actor_id, detail)
SELECT i.id, NULL, 'instance_started', i.started_by, jsonb_build_object('definition_code', 'demo.expense_approval')
FROM "WNE".wrkflow_instances i WHERE i.subject_id = 501
UNION ALL
SELECT i.id, wis.id, 'decision_made', wis.assigned_to, jsonb_build_object('decision', wis.decision, 'comment', wis.comment)
FROM "WNE".wrkflow_instances i
JOIN "WNE".wrkflow_instance_steps wis ON wis.instance_id = i.id
JOIN "WNE".wrkflow_steps s ON s.id = wis.step_id AND s.step_code = 'manager_approval'
WHERE i.subject_id = 501
UNION ALL
SELECT i.id, NULL, 'instance_completed', NULL, '{}'::jsonb
FROM "WNE".wrkflow_instances i WHERE i.subject_id = 501;

-- ---------------------------------------------------------------------------
-- 4. Definition 2: demo.webhook_integration — pause-and-resume seam (§3G),
--    instance currently parked at wait_for_callback with a live token
-- ---------------------------------------------------------------------------

INSERT INTO "WNE".wrkflow_webhooks (name, url, http_method, payload_template) VALUES
    ('Tax Clearance Check', 'https://example-gov-portal.test/api/tax-clearance', 'POST', '{"case_id": "{{subject_id}}"}');

INSERT INTO "WNE".wrkflow_definitions (code, name, category_id, status, created_by)
SELECT 'demo.webhook_integration', 'External Tax Clearance Check', wc.id, 'published',
       (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "WNE".wrkflow_categories wc WHERE wc.name = 'Integrations';

INSERT INTO "WNE".wrkflow_versions (definition_id, version_no, published_by)
SELECT d.id, 1, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')
FROM "WNE".wrkflow_definitions d WHERE d.code = 'demo.webhook_integration';

INSERT INTO "WNE".wrkflow_steps (version_id, step_code, type, config, is_entry_step)
SELECT v.id, s.step_code, s.type, s.config::jsonb, s.is_entry
FROM "WNE".wrkflow_versions v
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.webhook_integration'
JOIN (VALUES
    ('call_gov_portal', 'webhook_call',       '{"webhook": "Tax Clearance Check"}', TRUE),
    ('await_result',    'wait_for_callback',  '{}', FALSE)
) AS s (step_code, type, config, is_entry) ON TRUE;

INSERT INTO "WNE".wrkflow_transitions (from_step_id, to_step_id, condition_expression, seq)
SELECT f.id, t.id, NULL, 0
FROM "WNE".wrkflow_steps f, "WNE".wrkflow_steps t
WHERE f.version_id = t.version_id AND f.step_code = 'call_gov_portal' AND t.step_code = 'await_result';

INSERT INTO "WNE".wrkflow_instances (definition_version_id, subject_type, subject_id, status, payload, started_at)
SELECT v.id, 'demo.legal_matters', 601, 'running', jsonb_build_object('matter_id', 601), TIMESTAMPTZ '2026-08-13 08:00:00+07'
FROM "WNE".wrkflow_versions v
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.webhook_integration';

INSERT INTO "WNE".wrkflow_instance_steps (instance_id, step_id, status, attempt, idempotency_key, started_at, completed_at)
SELECT i.id, s.id, 'completed', 1, i.id || ':' || s.id || ':1', TIMESTAMPTZ '2026-08-13 08:00:00+07', TIMESTAMPTZ '2026-08-13 08:00:05+07'
FROM "WNE".wrkflow_instances i
JOIN "WNE".wrkflow_versions v ON v.id = i.definition_version_id
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.webhook_integration'
JOIN "WNE".wrkflow_steps s ON s.version_id = v.id AND s.step_code = 'call_gov_portal'
WHERE i.subject_id = 601;

INSERT INTO "WNE".wrkflow_instance_steps (instance_id, step_id, status, attempt, idempotency_key, started_at)
SELECT i.id, s.id, 'waiting_external', 1, i.id || ':' || s.id || ':1', TIMESTAMPTZ '2026-08-13 08:00:05+07'
FROM "WNE".wrkflow_instances i
JOIN "WNE".wrkflow_versions v ON v.id = i.definition_version_id
JOIN "WNE".wrkflow_definitions d ON d.id = v.definition_id AND d.code = 'demo.webhook_integration'
JOIN "WNE".wrkflow_steps s ON s.version_id = v.id AND s.step_code = 'await_result'
WHERE i.subject_id = 601;

INSERT INTO "WNE".wrkflow_callbacks (instance_step_id, expires_at)
SELECT wis.id, TIMESTAMPTZ '2026-08-20 08:00:05+07'
FROM "WNE".wrkflow_instance_steps wis
JOIN "WNE".wrkflow_steps s ON s.id = wis.step_id AND s.step_code = 'await_result'
JOIN "WNE".wrkflow_instances i ON i.id = wis.instance_id AND i.subject_id = 601;

-- ---------------------------------------------------------------------------
-- 5. Notifications: one delivered cleanly (email + in_app), one that exhausts
--    retries and lands in the DLQ (§3M)
-- ---------------------------------------------------------------------------

INSERT INTO "WNE".msg_user_preferences (user_id, category_id, preferred_channels)
SELECT u.id, c.id, ARRAY['email', 'in_app']
FROM users u, "WNE".msg_categories c
WHERE u.email = 'staff@nusaevo.com' AND c.code = 'expense_approved';

INSERT INTO "WNE".msg_notifications (category_id, subject_type, subject_id, recipient_user_id, payload, priority)
SELECT c.id, 'demo.expense_reports', 501, u.id, jsonb_build_object('employee_name', 'Staff User', 'amount', 'IDR 7,500,000'), 'normal'
FROM "WNE".msg_categories c, users u
WHERE c.code = 'expense_approved' AND u.email = 'staff@nusaevo.com';

INSERT INTO "WNE".msg_notification_deliveries (notification_id, channel_type_id, status, provider_message_id, attempt_count, sent_at, delivered_at)
SELECT n.id, ch.id, 'delivered', 'sg-msg-1001', 1, TIMESTAMPTZ '2026-08-03 11:00:10+07', TIMESTAMPTZ '2026-08-03 11:00:30+07'
FROM "WNE".msg_notifications n, "WNE".channel_types ch
WHERE n.subject_id = 501 AND ch.code = 'email'
UNION ALL
SELECT n.id, ch.id, 'delivered', NULL, 1, TIMESTAMPTZ '2026-08-03 11:00:05+07', TIMESTAMPTZ '2026-08-03 11:00:05+07'
FROM "WNE".msg_notifications n, "WNE".channel_types ch
WHERE n.subject_id = 501 AND ch.code = 'in_app';

INSERT INTO "WNE".msg_delivery_events (delivery_id, event_type, occurred_at, provider_payload)
SELECT d.id, e.event_type, e.occurred_at, e.payload::jsonb
FROM "WNE".msg_notification_deliveries d
JOIN "WNE".msg_notifications n ON n.id = d.notification_id AND n.subject_id = 501
JOIN "WNE".channel_types ch ON ch.id = d.channel_type_id AND ch.code = 'email'
JOIN (VALUES
    ('created',   TIMESTAMPTZ '2026-08-03 11:00:00+07', '{}'),
    ('queued',    TIMESTAMPTZ '2026-08-03 11:00:01+07', '{}'),
    ('sent',      TIMESTAMPTZ '2026-08-03 11:00:10+07', '{"provider_message_id": "sg-msg-1001"}'),
    ('delivered', TIMESTAMPTZ '2026-08-03 11:00:30+07', '{"provider_message_id": "sg-msg-1001"}')
) AS e (event_type, occurred_at, payload) ON TRUE;

-- A second delivery that exhausts retries and dead-letters
INSERT INTO "WNE".msg_notifications (category_id, subject_type, subject_id, recipient_user_id, payload, priority)
SELECT c.id, 'demo.expense_reports', 502, u.id, jsonb_build_object('employee_name', 'Staff User', 'amount', 'IDR 1,200,000'), 'normal'
FROM "WNE".msg_categories c, users u
WHERE c.code = 'expense_approved' AND u.email = 'staff@nusaevo.com';

INSERT INTO "WNE".msg_notification_deliveries (notification_id, channel_type_id, status, attempt_count, error_detail)
SELECT n.id, ch.id, 'dead_lettered', 4, 'Provider returned 503 on every attempt (simulated outage).'
FROM "WNE".msg_notifications n, "WNE".channel_types ch
WHERE n.subject_id = 502 AND ch.code = 'email';

INSERT INTO "WNE".msg_delivery_events (delivery_id, event_type, occurred_at, provider_payload)
SELECT d.id, e.event_type, e.occurred_at, e.payload::jsonb
FROM "WNE".msg_notification_deliveries d
JOIN "WNE".msg_notifications n ON n.id = d.notification_id AND n.subject_id = 502
JOIN (VALUES
    ('created',       TIMESTAMPTZ '2026-08-04 09:00:00+07', '{}'),
    ('queued',        TIMESTAMPTZ '2026-08-04 09:00:01+07', '{}'),
    ('failed',        TIMESTAMPTZ '2026-08-04 09:01:00+07', '{"error": "503"}'),
    ('retrying',      TIMESTAMPTZ '2026-08-04 09:06:00+07', '{"attempt": 2}'),
    ('failed',        TIMESTAMPTZ '2026-08-04 09:06:05+07', '{"error": "503"}'),
    ('retrying',      TIMESTAMPTZ '2026-08-04 09:36:00+07', '{"attempt": 3}'),
    ('failed',        TIMESTAMPTZ '2026-08-04 09:36:05+07', '{"error": "503"}'),
    ('dead_lettered', TIMESTAMPTZ '2026-08-04 11:36:05+07', '{"attempt": 4}')
) AS e (event_type, occurred_at, payload) ON TRUE;

INSERT INTO "WNE".msg_dead_letters (delivery_id, full_message, failure_history)
SELECT d.id,
       jsonb_build_object('category', 'expense_approved', 'payload', n.payload),
       jsonb_build_object('attempts', 4, 'last_error', '503')
FROM "WNE".msg_notification_deliveries d
JOIN "WNE".msg_notifications n ON n.id = d.notification_id AND n.subject_id = 502;

COMMIT;

-- =============================================================================
-- Sanity check counts
-- =============================================================================
SELECT 'wrkflow_definitions' AS table_name, COUNT(*) FROM "WNE".wrkflow_definitions
UNION ALL SELECT 'wrkflow_versions', COUNT(*) FROM "WNE".wrkflow_versions
UNION ALL SELECT 'wrkflow_steps', COUNT(*) FROM "WNE".wrkflow_steps
UNION ALL SELECT 'wrkflow_transitions', COUNT(*) FROM "WNE".wrkflow_transitions
UNION ALL SELECT 'wrkflow_instances', COUNT(*) FROM "WNE".wrkflow_instances
UNION ALL SELECT 'wrkflow_instance_steps', COUNT(*) FROM "WNE".wrkflow_instance_steps
UNION ALL SELECT 'wrkflow_escalation_log', COUNT(*) FROM "WNE".wrkflow_escalation_log
UNION ALL SELECT 'wrkflow_callbacks', COUNT(*) FROM "WNE".wrkflow_callbacks
UNION ALL SELECT 'wrkflow_audit_logs', COUNT(*) FROM "WNE".wrkflow_audit_logs
UNION ALL SELECT 'msg_notifications', COUNT(*) FROM "WNE".msg_notifications
UNION ALL SELECT 'msg_notification_deliveries', COUNT(*) FROM "WNE".msg_notification_deliveries
UNION ALL SELECT 'msg_delivery_events', COUNT(*) FROM "WNE".msg_delivery_events
UNION ALL SELECT 'msg_dead_letters', COUNT(*) FROM "WNE".msg_dead_letters
UNION ALL SELECT 'wrkflow_tasks (view)', COUNT(*) FROM "WNE".wrkflow_tasks;
