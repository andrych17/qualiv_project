-- =============================================================================
-- WNE Module — Workflow & Notification Engine
-- Schema DDL — PostgreSQL 16
-- Tenant DB, schema WNE (DB-per-tenant isolation — no tenant_id column,
-- per CLAUDE.md §4/§7).
--
-- Run inside a single tenant database, e.g.:
--   psql -h localhost -p 5435 -U postgres -d tenant_001 -v ON_ERROR_STOP=1 -f wne_schema.sql
-- =============================================================================

BEGIN;

CREATE SCHEMA IF NOT EXISTS "WNE";
SET search_path TO "WNE";

-- Common trigger function: keep updated_at current on every UPDATE.
CREATE OR REPLACE FUNCTION "WNE".set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- 1. MASTER / LOOKUP TABLES
-- =============================================================================

-- ---------------------------------------------------------------------------
-- wrkflow_categories — optional grouping lookup for workflow definitions
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_categories (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(50)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    description     TEXT,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_wrkflow_categories_code UNIQUE (code)
);

-- ---------------------------------------------------------------------------
-- channel_types — lookup: email / sms / push / in_app / webhook
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".channel_types (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL,
    name            VARCHAR(100) NOT NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_channel_types_code UNIQUE (code)
);

-- ---------------------------------------------------------------------------
-- msg_categories — tenant-editable notification categories
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_categories (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(50)  NOT NULL,
    name                VARCHAR(150) NOT NULL,
    description         TEXT,
    is_mandatory        BOOLEAN      NOT NULL DEFAULT FALSE, -- cannot be opted out of (e.g. security)
    bypass_quiet_hours  BOOLEAN      NOT NULL DEFAULT FALSE, -- urgent categories ignore quiet hours
    digestible          BOOLEAN      NOT NULL DEFAULT FALSE, -- Future Version: batching/digest flag
    default_channels    JSONB        NOT NULL DEFAULT '["in_app"]'::jsonb,
    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_msg_categories_code UNIQUE (code)
);

-- ---------------------------------------------------------------------------
-- msg_channel_configs — per-channel provider credentials & retry policy
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_channel_configs (
    id                  BIGSERIAL PRIMARY KEY,
    channel_type_id     BIGINT       NOT NULL REFERENCES "WNE".channel_types(id),
    provider_code       VARCHAR(50)  NOT NULL,             -- e.g. 'smtp', 'sendgrid', 'twilio', 'fcm', 'apns'
    credentials         JSONB        NOT NULL DEFAULT '{}'::jsonb, -- encrypted at the app layer before persist
    max_attempts        SMALLINT     NOT NULL DEFAULT 4,
    backoff_schedule_seconds INTEGER[] NOT NULL DEFAULT ARRAY[60, 300, 1800, 7200], -- 1m/5m/30m/2h
    is_enabled          BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_msg_channel_configs_channel UNIQUE (channel_type_id)
);

-- ---------------------------------------------------------------------------
-- msg_templates — one row per category × channel × locale
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_templates (
    id              BIGSERIAL PRIMARY KEY,
    category_id     BIGINT       NOT NULL REFERENCES "WNE".msg_categories(id),
    channel_type_id BIGINT       NOT NULL REFERENCES "WNE".channel_types(id),
    locale          VARCHAR(10)  NOT NULL DEFAULT 'id',
    subject         VARCHAR(255),                  -- used by email/push title; null for sms/in_app
    body            TEXT         NOT NULL,
    variables       JSONB        NOT NULL DEFAULT '[]'::jsonb, -- documented placeholder list
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_msg_templates_combo UNIQUE (category_id, channel_type_id, locale)
);

-- =============================================================================
-- 2. WORKFLOW ENGINE TABLES (wrkflow_ prefix)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- wrkflow_definitions — header; code is the stable handle calling modules use
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_definitions (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(100) NOT NULL,          -- e.g. 'hcm.leave_approval'
    name                VARCHAR(150) NOT NULL,
    description         TEXT,
    category_id         BIGINT       REFERENCES "WNE".wrkflow_categories(id),
    status              VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft | published | unpublished
    published_version_id BIGINT,                        -- FK added after wrkflow_versions exists
    created_by          BIGINT,                          -- references platform users table
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_wrkflow_definitions_code UNIQUE (code),
    CONSTRAINT ck_wrkflow_definitions_status CHECK (status IN ('draft', 'published', 'unpublished'))
);

-- ---------------------------------------------------------------------------
-- wrkflow_versions — immutable snapshot created on each publish
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_versions (
    id              BIGSERIAL PRIMARY KEY,
    definition_id   BIGINT       NOT NULL REFERENCES "WNE".wrkflow_definitions(id),
    version_no      INTEGER      NOT NULL,
    is_published    BOOLEAN      NOT NULL DEFAULT TRUE,
    published_by    BIGINT,                              -- references platform users table
    published_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    unpublished_at  TIMESTAMPTZ,
    CONSTRAINT uq_wrkflow_versions_def_version UNIQUE (definition_id, version_no)
);

ALTER TABLE "WNE".wrkflow_definitions
    ADD CONSTRAINT fk_wrkflow_definitions_published_version
    FOREIGN KEY (published_version_id) REFERENCES "WNE".wrkflow_versions(id);

-- ---------------------------------------------------------------------------
-- wrkflow_steps — steps belonging to one immutable version
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_steps (
    id              BIGSERIAL PRIMARY KEY,
    version_id      BIGINT       NOT NULL REFERENCES "WNE".wrkflow_versions(id),
    step_key        VARCHAR(50)  NOT NULL,               -- stable key within the version, e.g. 'start'
    name            VARCHAR(150) NOT NULL,
    type            VARCHAR(30)  NOT NULL,                -- approval|task|condition|parallel_split|
                                                            -- parallel_join|webhook_call|wait_for_callback|
                                                            -- notify|start|end
    config          JSONB        NOT NULL DEFAULT '{}'::jsonb, -- assignee rule, condition, url/template, etc.
    pos_x           INTEGER      NOT NULL DEFAULT 0,      -- reserved for Future Version canvas designer
    pos_y           INTEGER      NOT NULL DEFAULT 0,
    is_entry_step   BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_wrkflow_steps_version_key UNIQUE (version_id, step_key),
    CONSTRAINT ck_wrkflow_steps_type CHECK (type IN (
        'start','approval','task','condition','parallel_split','parallel_join',
        'webhook_call','wait_for_callback','notify','end'
    ))
);

-- ---------------------------------------------------------------------------
-- wrkflow_transitions — from_step -> to_step, optional condition
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_transitions (
    id                      BIGSERIAL PRIMARY KEY,
    version_id              BIGINT       NOT NULL REFERENCES "WNE".wrkflow_versions(id),
    from_step_id            BIGINT       NOT NULL REFERENCES "WNE".wrkflow_steps(id),
    to_step_id               BIGINT       NOT NULL REFERENCES "WNE".wrkflow_steps(id),
    condition_expression     JSONB,                        -- null = unconditional / default transition
    is_default               BOOLEAN      NOT NULL DEFAULT FALSE, -- the "else" path for a branch
    sort_order                SMALLINT     NOT NULL DEFAULT 0,
    created_at                TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX idx_wrkflow_transitions_from ON "WNE".wrkflow_transitions(from_step_id);
CREATE INDEX idx_wrkflow_transitions_to   ON "WNE".wrkflow_transitions(to_step_id);

-- ---------------------------------------------------------------------------
-- wrkflow_sla_rules — per-step (or definition-default) SLA + escalation
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_sla_rules (
    id                  BIGSERIAL PRIMARY KEY,
    step_id             BIGINT       REFERENCES "WNE".wrkflow_steps(id), -- null = definition-wide default
    version_id          BIGINT       NOT NULL REFERENCES "WNE".wrkflow_versions(id),
    sla_hours           NUMERIC(8,2) NOT NULL,
    escalation_action    VARCHAR(30)  NOT NULL, -- reassign_to_role|notify_manager_of_assignee|notify_role
    escalation_target    VARCHAR(150),           -- role code / user id / free text per action type
    created_at            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_wrkflow_sla_rules_action CHECK (escalation_action IN (
        'reassign_to_role','notify_manager_of_assignee','notify_role'
    ))
);

-- ---------------------------------------------------------------------------
-- wrkflow_instances — a running/completed process
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_instances (
    id                      BIGSERIAL PRIMARY KEY,
    definition_id            BIGINT       NOT NULL REFERENCES "WNE".wrkflow_definitions(id),
    definition_version_id    BIGINT       NOT NULL REFERENCES "WNE".wrkflow_versions(id), -- pinned at start, immutable
    subject_type              VARCHAR(100),           -- polymorphic pointer, e.g. 'hcm.leave_requests'
    subject_id                 BIGINT,
    status                      VARCHAR(20)  NOT NULL DEFAULT 'running', -- running|completed|failed|cancelled
    payload                     JSONB        NOT NULL DEFAULT '{}'::jsonb, -- snapshot taken at start
    started_by                  BIGINT,                 -- references platform users table
    started_at                  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    ended_at                    TIMESTAMPTZ,
    cancel_reason                TEXT,
    CONSTRAINT ck_wrkflow_instances_status CHECK (status IN ('running','completed','failed','cancelled'))
);

CREATE INDEX idx_wrkflow_instances_subject ON "WNE".wrkflow_instances(subject_type, subject_id);
CREATE INDEX idx_wrkflow_instances_status  ON "WNE".wrkflow_instances(status);
CREATE INDEX idx_wrkflow_instances_definition ON "WNE".wrkflow_instances(definition_id);

-- ---------------------------------------------------------------------------
-- wrkflow_instance_steps — persisted state of each step within an instance
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_instance_steps (
    id                  BIGSERIAL PRIMARY KEY,
    instance_id         BIGINT       NOT NULL REFERENCES "WNE".wrkflow_instances(id),
    step_id             BIGINT       NOT NULL REFERENCES "WNE".wrkflow_steps(id),
    status              VARCHAR(20)  NOT NULL DEFAULT 'pending',
        -- pending|in_progress|waiting_external|completed|failed|skipped|cancelled
    assigned_to_user    BIGINT,                          -- references platform users table
    assigned_to_role    VARCHAR(100),
    idempotency_key     VARCHAR(150) NOT NULL,
    due_at              TIMESTAMPTZ,
    started_at          TIMESTAMPTZ,
    completed_at        TIMESTAMPTZ,
    decision            VARCHAR(50),                     -- e.g. approve|reject|custom code
    comment             TEXT,
    parallel_group_key  VARCHAR(100),                     -- correlates fan-out steps for a join
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_wrkflow_instance_steps_idempotency UNIQUE (idempotency_key),
    CONSTRAINT ck_wrkflow_instance_steps_status CHECK (status IN (
        'pending','in_progress','waiting_external','completed','failed','skipped','cancelled'
    ))
);

CREATE INDEX idx_wrkflow_instance_steps_instance ON "WNE".wrkflow_instance_steps(instance_id);
CREATE INDEX idx_wrkflow_instance_steps_assignee ON "WNE".wrkflow_instance_steps(assigned_to_user, status);
CREATE INDEX idx_wrkflow_instance_steps_role     ON "WNE".wrkflow_instance_steps(assigned_to_role, status);
CREATE INDEX idx_wrkflow_instance_steps_due      ON "WNE".wrkflow_instance_steps(due_at) WHERE status IN ('pending','in_progress');

-- ---------------------------------------------------------------------------
-- wrkflow_escalation_log — append-only escalation history
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_escalation_log (
    id                  BIGSERIAL PRIMARY KEY,
    instance_step_id    BIGINT       NOT NULL REFERENCES "WNE".wrkflow_instance_steps(id),
    sla_rule_id         BIGINT       REFERENCES "WNE".wrkflow_sla_rules(id),
    action_applied      VARCHAR(30)  NOT NULL,
    escalated_to        VARCHAR(150),
    escalated_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX idx_wrkflow_escalation_log_step ON "WNE".wrkflow_escalation_log(instance_step_id);

-- ---------------------------------------------------------------------------
-- wrkflow_webhooks — outbound webhook subscriptions referenced by webhook_call steps
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_webhooks (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    url             TEXT         NOT NULL,
    http_method     VARCHAR(10)  NOT NULL DEFAULT 'POST',
    payload_template JSONB       NOT NULL DEFAULT '{}'::jsonb,
    auth_config     JSONB        NOT NULL DEFAULT '{}'::jsonb, -- encrypted at app layer
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- wrkflow_callbacks — signed single-use tokens for wait_for_callback steps
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_callbacks (
    id                  BIGSERIAL PRIMARY KEY,
    instance_step_id    BIGINT       NOT NULL REFERENCES "WNE".wrkflow_instance_steps(id),
    token               VARCHAR(100) NOT NULL,
    expires_at          TIMESTAMPTZ  NOT NULL,
    consumed_at         TIMESTAMPTZ,
    payload_received    JSONB,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_wrkflow_callbacks_token UNIQUE (token)
);

-- ---------------------------------------------------------------------------
-- wrkflow_audit_logs — append-only, no update/delete at app layer
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".wrkflow_audit_logs (
    id              BIGSERIAL PRIMARY KEY,
    instance_id     BIGINT       REFERENCES "WNE".wrkflow_instances(id),
    instance_step_id BIGINT      REFERENCES "WNE".wrkflow_instance_steps(id),
    action          VARCHAR(50)  NOT NULL, -- instance_started|step_completed|decision_made|escalated|cancelled|...
    actor_user_id   BIGINT,                 -- references platform users table; null = system
    detail          JSONB        NOT NULL DEFAULT '{}'::jsonb,
    occurred_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX idx_wrkflow_audit_logs_instance ON "WNE".wrkflow_audit_logs(instance_id);

-- =============================================================================
-- 3. NOTIFICATION MODULE TABLES (msg_ prefix)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- msg_user_preferences — per user, per category
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_user_preferences (
    id                  BIGSERIAL PRIMARY KEY,
    user_id             BIGINT       NOT NULL,           -- references platform users table
    category_id         BIGINT       NOT NULL REFERENCES "WNE".msg_categories(id),
    preferred_channels  JSONB        NOT NULL DEFAULT '[]'::jsonb, -- array of channel codes
    opted_out           BOOLEAN      NOT NULL DEFAULT FALSE,
    quiet_hours_start   TIME,
    quiet_hours_end     TIME,
    timezone            VARCHAR(50)  NOT NULL DEFAULT 'Asia/Jakarta',
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_msg_user_preferences_combo UNIQUE (user_id, category_id)
);

CREATE INDEX idx_msg_user_preferences_user ON "WNE".msg_user_preferences(user_id);

-- ---------------------------------------------------------------------------
-- msg_notifications — logical notification header (fans out to N deliveries)
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_notifications (
    id              BIGSERIAL PRIMARY KEY,
    category_id     BIGINT       NOT NULL REFERENCES "WNE".msg_categories(id),
    subject_type    VARCHAR(100),                        -- optional polymorphic source link
    subject_id      BIGINT,
    recipient_user_id BIGINT,                              -- references platform users table (nullable if recipient is external, e.g. a CRM partner)
    recipient_partner_id BIGINT,                            -- optional, informational link to CRM.partners
    payload         JSONB        NOT NULL DEFAULT '{}'::jsonb, -- variables for template resolution
    priority        VARCHAR(20)  NOT NULL DEFAULT 'normal',   -- low|normal|high|urgent
    triggered_by_instance_step_id BIGINT REFERENCES "WNE".wrkflow_instance_steps(id),
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_msg_notifications_priority CHECK (priority IN ('low','normal','high','urgent'))
);

CREATE INDEX idx_msg_notifications_subject   ON "WNE".msg_notifications(subject_type, subject_id);
CREATE INDEX idx_msg_notifications_recipient ON "WNE".msg_notifications(recipient_user_id);
CREATE INDEX idx_msg_notifications_category  ON "WNE".msg_notifications(category_id);

-- ---------------------------------------------------------------------------
-- msg_notification_deliveries — one row per channel attempt for a notification
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_notification_deliveries (
    id                  BIGSERIAL PRIMARY KEY,
    notification_id     BIGINT       NOT NULL REFERENCES "WNE".msg_notifications(id),
    channel_type_id     BIGINT       NOT NULL REFERENCES "WNE".channel_types(id),
    template_id         BIGINT       REFERENCES "WNE".msg_templates(id),
    status              VARCHAR(20)  NOT NULL DEFAULT 'queued',
        -- queued|deferred|sending|sent|delivered|failed|retrying|dead_lettered
    provider_message_id VARCHAR(150),
    attempt_count       SMALLINT     NOT NULL DEFAULT 0,
    next_retry_at       TIMESTAMPTZ,
    sent_at             TIMESTAMPTZ,
    delivered_at        TIMESTAMPTZ,
    error_detail        TEXT,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_msg_notification_deliveries_status CHECK (status IN (
        'queued','deferred','sending','sent','delivered','failed','retrying','dead_lettered'
    ))
);

CREATE INDEX idx_msg_notification_deliveries_notification ON "WNE".msg_notification_deliveries(notification_id);
CREATE INDEX idx_msg_notification_deliveries_status       ON "WNE".msg_notification_deliveries(status);
CREATE INDEX idx_msg_notification_deliveries_retry        ON "WNE".msg_notification_deliveries(next_retry_at)
    WHERE status IN ('retrying','deferred');

-- ---------------------------------------------------------------------------
-- msg_delivery_events — append-only lifecycle log, no update/delete at app layer
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_delivery_events (
    id              BIGSERIAL PRIMARY KEY,
    delivery_id     BIGINT       NOT NULL REFERENCES "WNE".msg_notification_deliveries(id),
    event_type      VARCHAR(20)  NOT NULL,
        -- created|queued|sending|sent|delivered|opened|bounced|failed|retrying|dead_lettered
    provider_payload JSONB       NOT NULL DEFAULT '{}'::jsonb,
    occurred_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_msg_delivery_events_type CHECK (event_type IN (
        'created','queued','sending','sent','delivered','opened','bounced','failed','retrying','dead_lettered'
    ))
);

CREATE INDEX idx_msg_delivery_events_delivery ON "WNE".msg_delivery_events(delivery_id);

-- ---------------------------------------------------------------------------
-- msg_dead_letters — exhausted deliveries, full message + failure history
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_dead_letters (
    id                  BIGSERIAL PRIMARY KEY,
    delivery_id         BIGINT       NOT NULL REFERENCES "WNE".msg_notification_deliveries(id),
    message_snapshot    JSONB        NOT NULL,           -- full resolved message at time of dead-lettering
    failure_history     JSONB        NOT NULL DEFAULT '[]'::jsonb,
    resolved_action     VARCHAR(20),                      -- null = unresolved | resent | discarded
    resolved_by         BIGINT,                            -- references platform users table
    resolved_at         TIMESTAMPTZ,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_msg_dead_letters_resolved_action CHECK (resolved_action IS NULL OR resolved_action IN ('resent','discarded'))
);

CREATE INDEX idx_msg_dead_letters_delivery ON "WNE".msg_dead_letters(delivery_id);

-- ---------------------------------------------------------------------------
-- msg_digests — Future Version stub (empty at launch, additive only)
-- ---------------------------------------------------------------------------
CREATE TABLE "WNE".msg_digests (
    id              BIGSERIAL PRIMARY KEY,
    user_id         BIGINT       NOT NULL,
    category_id     BIGINT       NOT NULL REFERENCES "WNE".msg_categories(id),
    period_start    TIMESTAMPTZ  NOT NULL,
    period_end      TIMESTAMPTZ  NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending|sent
    sent_at         TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- =============================================================================
-- 4. VIEWS
-- =============================================================================

-- wrkflow_tasks — "My Approvals" inbox surface (3H), a read view over
-- wrkflow_instance_steps rather than a denormalized table, to avoid a second
-- write path to keep in sync.
CREATE OR REPLACE VIEW "WNE".wrkflow_tasks AS
SELECT
    wis.id                    AS instance_step_id,
    wis.instance_id,
    wi.subject_type,
    wi.subject_id,
    wd.code                   AS workflow_code,
    wd.name                   AS workflow_name,
    ws.name                   AS step_name,
    ws.type                   AS step_type,
    wis.status,
    wis.assigned_to_user,
    wis.assigned_to_role,
    wis.due_at,
    wis.started_at,
    wis.created_at
FROM "WNE".wrkflow_instance_steps wis
JOIN "WNE".wrkflow_instances wi ON wi.id = wis.instance_id
JOIN "WNE".wrkflow_definitions wd ON wd.id = wi.definition_id
JOIN "WNE".wrkflow_steps ws ON ws.id = wis.step_id
WHERE ws.type IN ('approval', 'task')
  AND wis.status IN ('pending', 'in_progress');

-- =============================================================================
-- 5. TRIGGERS — keep updated_at current
-- =============================================================================

DO $$
DECLARE
    t TEXT;
    tables_with_updated_at TEXT[] := ARRAY[
        'wrkflow_categories','channel_types','msg_categories','msg_channel_configs',
        'msg_templates','wrkflow_definitions','wrkflow_instance_steps','wrkflow_webhooks',
        'msg_user_preferences','msg_notification_deliveries'
    ];
BEGIN
    FOREACH t IN ARRAY tables_with_updated_at LOOP
        EXECUTE format(
            'CREATE TRIGGER trg_set_updated_at BEFORE UPDATE ON "WNE".%I
             FOR EACH ROW EXECUTE FUNCTION "WNE".set_updated_at();', t
        );
    END LOOP;
END $$;

COMMIT;

-- =============================================================================
-- End of WNE schema
-- =============================================================================
