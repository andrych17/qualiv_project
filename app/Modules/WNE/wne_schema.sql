-- =====================================================================
-- WNE — Workflow & Notification Engine
-- Schema DDL (PostgreSQL)
--
-- Assumption: PostgreSQL, since "schema" is used as an actual namespace
-- object (CREATE SCHEMA). If the stack is MySQL/MariaDB instead, schema
-- isolation isn't native there — let me know and I'll convert this to a
-- single database with a `wne_` table-prefix convention instead.
--
-- Naming:
--   - Master tables   : single word              (channels, providers, events, actions)
--   - Transaction tbl : 2 parts, domain_ prefix   (wrkflow_*, msg_*)
--
-- Multi-tenancy: tenant_id is a plain bigint column (no FK) since the
-- tenants table lives outside this schema/module. Enforce isolation via
-- app-level global scope (and optionally Postgres RLS later).
-- =====================================================================

CREATE SCHEMA IF NOT EXISTS wne;
SET search_path TO wne;

-- =====================================================================
-- MASTER TABLES
-- =====================================================================

-- Channel types: email, sms, whatsapp, push, inapp
CREATE TABLE wne.channels (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    driver_class    VARCHAR(150) NOT NULL,        -- FQCN implementing ChannelDriverInterface
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Concrete delivery providers per channel: SES, Twilio, WhatsApp Business API, FCM...
CREATE TABLE wne.providers (
    id              BIGSERIAL PRIMARY KEY,
    channel_id      BIGINT       NOT NULL REFERENCES wne.channels(id),
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_providers_channel ON wne.providers(channel_id);

-- Event catalog: codes any module can publish (po.created, workflow.step_pending, ...)
CREATE TABLE wne.events (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(100) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    module          VARCHAR(100) NOT NULL,        -- owning module, e.g. purchasing.po
    description     TEXT,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Workflow action vocabulary: approve, reject, revise, escalate, delegate
CREATE TABLE wne.actions (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- =====================================================================
-- TRANSACTION TABLES — WORKFLOW DOMAIN (wrkflow_*)
-- =====================================================================

CREATE TABLE wne.wrkflow_definitions (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT       NOT NULL,
    code            VARCHAR(100) NOT NULL,
    name            VARCHAR(150) NOT NULL,
    module          VARCHAR(100) NOT NULL,
    description     TEXT,
    version         INT          NOT NULL DEFAULT 1,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by      BIGINT,
    updated_by      BIGINT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ,
    UNIQUE (tenant_id, code, version)
);
CREATE INDEX idx_wrkflow_definitions_tenant ON wne.wrkflow_definitions(tenant_id);

CREATE TABLE wne.wrkflow_states (
    id                      BIGSERIAL PRIMARY KEY,
    wrkflow_definition_id   BIGINT       NOT NULL REFERENCES wne.wrkflow_definitions(id) ON DELETE CASCADE,
    code                    VARCHAR(50)  NOT NULL,
    name                    VARCHAR(100) NOT NULL,
    is_initial              BOOLEAN      NOT NULL DEFAULT FALSE,
    is_final                BOOLEAN      NOT NULL DEFAULT FALSE,
    sla_hours               INT,
    sort_order              INT          NOT NULL DEFAULT 0,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (wrkflow_definition_id, code)
);

CREATE TABLE wne.wrkflow_transitions (
    id                      BIGSERIAL PRIMARY KEY,
    wrkflow_definition_id   BIGINT       NOT NULL REFERENCES wne.wrkflow_definitions(id) ON DELETE CASCADE,
    from_state_id           BIGINT       NOT NULL REFERENCES wne.wrkflow_states(id),
    to_state_id             BIGINT       NOT NULL REFERENCES wne.wrkflow_states(id),
    action_id               BIGINT       NOT NULL REFERENCES wne.actions(id),
    name                    VARCHAR(100),
    sort_order              INT          NOT NULL DEFAULT 0,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_wrkflow_transitions_def ON wne.wrkflow_transitions(wrkflow_definition_id);
CREATE INDEX idx_wrkflow_transitions_from ON wne.wrkflow_transitions(from_state_id);

CREATE TABLE wne.wrkflow_transition_conditions (
    id                      BIGSERIAL PRIMARY KEY,
    wrkflow_transition_id   BIGINT       NOT NULL REFERENCES wne.wrkflow_transitions(id) ON DELETE CASCADE,
    group_no                INT          NOT NULL DEFAULT 1,   -- same group = AND; different group = OR
    field                   VARCHAR(100) NOT NULL,
    operator                VARCHAR(20)  NOT NULL,             -- =, !=, >, >=, <, <=, in, not_in, contains
    value                   TEXT,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_wrkflow_conditions_transition ON wne.wrkflow_transition_conditions(wrkflow_transition_id);

CREATE TABLE wne.wrkflow_transition_approvers (
    id                      BIGSERIAL PRIMARY KEY,
    wrkflow_transition_id   BIGINT       NOT NULL REFERENCES wne.wrkflow_transitions(id) ON DELETE CASCADE,
    approver_type           VARCHAR(20)  NOT NULL,             -- user, role, dynamic, group
    approver_ref            VARCHAR(150) NOT NULL,             -- user_id / role_code / resolver key / group_id
    quorum_rule             VARCHAR(20)  NOT NULL DEFAULT 'any', -- any, all, majority
    sort_order              INT          NOT NULL DEFAULT 0,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_wrkflow_approvers_transition ON wne.wrkflow_transition_approvers(wrkflow_transition_id);

CREATE TABLE wne.wrkflow_instances (
    id                      BIGSERIAL PRIMARY KEY,
    tenant_id               BIGINT       NOT NULL,
    wrkflow_definition_id   BIGINT       NOT NULL REFERENCES wne.wrkflow_definitions(id),
    subject_type            VARCHAR(150) NOT NULL,             -- polymorphic: owning module's model class
    subject_id              BIGINT       NOT NULL,
    current_state_id        BIGINT       REFERENCES wne.wrkflow_states(id),
    initiator_id            BIGINT       NOT NULL,
    context                 JSONB        NOT NULL DEFAULT '{}',
    status                  VARCHAR(20)  NOT NULL DEFAULT 'in_progress', -- in_progress, approved, rejected, cancelled
    started_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    completed_at            TIMESTAMPTZ,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_wrkflow_instances_subject ON wne.wrkflow_instances(tenant_id, subject_type, subject_id);
CREATE INDEX idx_wrkflow_instances_status ON wne.wrkflow_instances(tenant_id, status);

CREATE TABLE wne.wrkflow_tasks (
    id                      BIGSERIAL PRIMARY KEY,
    wrkflow_instance_id     BIGINT       NOT NULL REFERENCES wne.wrkflow_instances(id) ON DELETE CASCADE,
    wrkflow_transition_id   BIGINT       REFERENCES wne.wrkflow_transitions(id),
    state_id                BIGINT       NOT NULL REFERENCES wne.wrkflow_states(id),
    assignee_type           VARCHAR(20)  NOT NULL,             -- user, role, group
    assignee_ref            VARCHAR(150) NOT NULL,
    status                  VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending, approved, rejected, delegated, expired
    due_at                  TIMESTAMPTZ,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_wrkflow_tasks_instance ON wne.wrkflow_tasks(wrkflow_instance_id);
CREATE INDEX idx_wrkflow_tasks_assignee ON wne.wrkflow_tasks(assignee_type, assignee_ref, status);

CREATE TABLE wne.wrkflow_task_actions (
    id                      BIGSERIAL PRIMARY KEY,
    wrkflow_task_id         BIGINT       NOT NULL REFERENCES wne.wrkflow_tasks(id) ON DELETE CASCADE,
    actor_id                BIGINT       NOT NULL,
    action_id               BIGINT       NOT NULL REFERENCES wne.actions(id),
    remarks                 TEXT,
    acted_at                TIMESTAMPTZ  NOT NULL DEFAULT now(),
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_wrkflow_task_actions_task ON wne.wrkflow_task_actions(wrkflow_task_id);

CREATE TABLE wne.wrkflow_delegations (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT       NOT NULL,
    delegator_id    BIGINT       NOT NULL,
    delegate_id     BIGINT       NOT NULL,
    start_date      DATE         NOT NULL,
    end_date        DATE         NOT NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_wrkflow_delegations_tenant ON wne.wrkflow_delegations(tenant_id, delegator_id);

-- =====================================================================
-- TRANSACTION TABLES — MESSAGING DOMAIN (msg_*)
-- =====================================================================

CREATE TABLE wne.msg_templates (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT       NOT NULL,
    event_id        BIGINT       NOT NULL REFERENCES wne.events(id),
    channel_id      BIGINT       NOT NULL REFERENCES wne.channels(id),
    locale          VARCHAR(10)  NOT NULL DEFAULT 'en',
    version         INT          NOT NULL DEFAULT 1,
    subject         VARCHAR(255),                              -- email only
    body            TEXT         NOT NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ,
    UNIQUE (tenant_id, event_id, channel_id, locale, version)
);
CREATE INDEX idx_msg_templates_tenant ON wne.msg_templates(tenant_id, event_id, channel_id);

CREATE TABLE wne.msg_channel_configs (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT       NOT NULL,
    channel_id      BIGINT       NOT NULL REFERENCES wne.channels(id),
    provider_id     BIGINT       NOT NULL REFERENCES wne.providers(id),
    credentials     JSONB        NOT NULL DEFAULT '{}',        -- encrypt at app layer before persisting
    sender_identity VARCHAR(150),
    rate_limit_per_min INT,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, channel_id)
);

CREATE TABLE wne.msg_routing_rules (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT       NOT NULL,
    event_id        BIGINT       NOT NULL REFERENCES wne.events(id),
    channel_id      BIGINT       NOT NULL REFERENCES wne.channels(id),
    recipient_type  VARCHAR(20)  NOT NULL,                     -- static_user, static_role, dynamic, workflow_approver, record_owner
    recipient_ref   VARCHAR(150),
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order      INT          NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_msg_routing_rules_tenant ON wne.msg_routing_rules(tenant_id, event_id);

CREATE TABLE wne.msg_user_preferences (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT       NOT NULL,
    user_id         BIGINT       NOT NULL,
    channel_id      BIGINT       NOT NULL REFERENCES wne.channels(id),
    is_opted_in     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, user_id, channel_id)
);

CREATE TABLE wne.msg_notification_log (
    id                      BIGSERIAL PRIMARY KEY,
    tenant_id               BIGINT       NOT NULL,
    event_id                BIGINT       NOT NULL REFERENCES wne.events(id),
    wrkflow_instance_id     BIGINT       REFERENCES wne.wrkflow_instances(id), -- optional link
    recipient_id            BIGINT,
    recipient_address       VARCHAR(255),                      -- email/phone/token snapshot at send time
    channel_id              BIGINT       NOT NULL REFERENCES wne.channels(id),
    template_id             BIGINT       REFERENCES wne.msg_templates(id),
    payload                 JSONB        NOT NULL DEFAULT '{}',
    status                  VARCHAR(20)  NOT NULL DEFAULT 'queued', -- queued, sent, failed, dead_letter
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_msg_notification_log_tenant ON wne.msg_notification_log(tenant_id, status);
CREATE INDEX idx_msg_notification_log_recipient ON wne.msg_notification_log(recipient_id);

CREATE TABLE wne.msg_notification_attempts (
    id                          BIGSERIAL PRIMARY KEY,
    msg_notification_log_id    BIGINT       NOT NULL REFERENCES wne.msg_notification_log(id) ON DELETE CASCADE,
    attempt_no                  INT          NOT NULL DEFAULT 1,
    provider_response           JSONB,
    status                       VARCHAR(20)  NOT NULL,        -- success, failed
    error_message                TEXT,
    attempted_at                 TIMESTAMPTZ  NOT NULL DEFAULT now(),
    created_at                   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at                   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_msg_notification_attempts_log ON wne.msg_notification_attempts(msg_notification_log_id);

-- =====================================================================
-- SEED — minimal master data to bootstrap the module
-- =====================================================================

INSERT INTO wne.channels (code, name, driver_class) VALUES
    ('email',  'Email',       'App\Modules\WNE\Drivers\EmailDriver'),
    ('sms',    'SMS',         'App\Modules\WNE\Drivers\SmsDriver'),
    ('whatsapp','WhatsApp',   'App\Modules\WNE\Drivers\WhatsAppDriver'),
    ('push',   'Push',        'App\Modules\WNE\Drivers\PushDriver'),
    ('inapp',  'In-App',      'App\Modules\WNE\Drivers\InAppDriver');

INSERT INTO wne.actions (code, name) VALUES
    ('approve',  'Approve'),
    ('reject',   'Reject'),
    ('revise',   'Request Revision'),
    ('escalate', 'Escalate'),
    ('delegate', 'Delegate');
