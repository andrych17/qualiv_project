-- =============================================================================
-- WNE Module — Workflow & Notification Engine
-- Sample / Seed Data — PostgreSQL 16
--
-- Demonstrates, with explicit cross-referenced IDs:
--   1. hcm.leave_approval   — a simple sequential approval (one completed run,
--      one running/SLA-breached run with an escalation log entry)
--   2. purchase.po_approval — a conditional branch on order amount (running,
--      routed to the higher approval tier)
--   3. purchase.invoice_webhook_sync — an outbound webhook step followed by a
--      wait_for_callback pause (demonstrates 3G's inbound/outbound seam)
--   4. Notification delivery lifecycle: a fully delivered multi-channel
--      notification, a failed-then-dead-lettered one, and a quiet-hours
--      deferred one.
--
-- Run after wne_schema.sql:
--   psql -h localhost -p 5435 -U postgres -d tenant_001 -v ON_ERROR_STOP=1 -f wne_seed.sql
--
-- Note: user_id / assigned_to_user / actor_user_id / recipient_user_id values
-- below (5, 10, 11, 12, 20) are illustrative references into the platform's
-- central users table, which is out of scope for this module's schema.
-- =============================================================================

BEGIN;

SET search_path TO "WNE";

-- =============================================================================
-- 1. MASTER / LOOKUP DATA
-- =============================================================================

INSERT INTO "WNE".wrkflow_categories (id, code, name, description) VALUES
    (1, 'HR_APPROVALS',          'HR Approvals',          'Leave, attendance correction, and other HR-related approval chains.'),
    (2, 'PROCUREMENT_APPROVALS', 'Procurement Approvals',  'Purchase requisition, PO, and invoice-related approval and integration chains.');

INSERT INTO "WNE".channel_types (id, code, name) VALUES
    (1, 'email',   'Email'),
    (2, 'in_app',  'In-App'),
    (3, 'sms',     'SMS'),
    (4, 'push',    'Push Notification'),
    (5, 'webhook', 'Webhook');

INSERT INTO "WNE".msg_categories (id, code, name, description, is_mandatory, bypass_quiet_hours, digestible, default_channels) VALUES
    (1, 'security_alert',   'Security Alert',        'Password resets, new-device logins, and other account-security events.', TRUE,  TRUE,  FALSE, '["email","in_app"]'::jsonb),
    (2, 'leave_approval',   'Leave Approval',        'Leave request submitted/approved/rejected notices.',                     FALSE, FALSE, FALSE, '["email","in_app"]'::jsonb),
    (3, 'po_approval',      'Purchase Order Approval','PO submitted/approved/rejected notices.',                                FALSE, FALSE, FALSE, '["email","in_app"]'::jsonb),
    (4, 'general_reminder', 'General Reminder',      'Low-priority reminders and informational notices.',                      FALSE, FALSE, TRUE,  '["in_app"]'::jsonb);

INSERT INTO "WNE".msg_channel_configs (id, channel_type_id, provider_code, credentials, max_attempts, backoff_schedule_seconds, is_enabled) VALUES
    (1, 1, 'smtp',     '{"host":"smtp.tenant-mail.test","port":587,"encrypted":true}'::jsonb, 4, ARRAY[60,300,1800,7200], TRUE),
    (2, 2, 'internal',  '{}'::jsonb,                                                          3, ARRAY[30,120,600],      TRUE),
    (3, 3, 'twilio',    '{"account_sid":"__encrypted__","auth_token":"__encrypted__"}'::jsonb, 3, ARRAY[60,300,1800],     FALSE),
    (4, 4, 'fcm',       '{"server_key":"__encrypted__"}'::jsonb,                               3, ARRAY[60,300,1800],     FALSE),
    (5, 5, 'internal',  '{}'::jsonb,                                                           4, ARRAY[60,300,1800,7200],TRUE);

INSERT INTO "WNE".msg_templates (id, category_id, channel_type_id, locale, subject, body, variables) VALUES
    (1, 2, 1, 'id', 'Pengajuan Cuti Anda Telah Disetujui',
        'Halo {{employee_name}}, pengajuan cuti {{leave_type}} Anda selama {{days}} hari telah disetujui oleh {{approver_name}}.',
        '["employee_name","leave_type","days","approver_name"]'::jsonb),
    (2, 2, 2, 'id', NULL,
        'Cuti {{leave_type}} Anda ({{days}} hari) telah {{status}}.',
        '["leave_type","days","status"]'::jsonb),
    (3, 3, 1, 'id', 'Persetujuan PO #{{po_number}} Diperlukan',
        'PO #{{po_number}} senilai {{amount}} menunggu persetujuan Anda. Buka WNE untuk meninjau.',
        '["po_number","amount"]'::jsonb),
    (4, 1, 1, 'id', 'Permintaan Reset Kata Sandi',
        'Kami menerima permintaan reset kata sandi untuk akun Anda pada {{requested_at}}. Abaikan email ini jika bukan Anda.',
        '["requested_at"]'::jsonb),
    (5, 4, 2, 'id', NULL,
        'Pengingat: {{message}}',
        '["message"]'::jsonb);

-- =============================================================================
-- 2. WORKFLOW DEFINITION A — hcm.leave_approval (simple sequential)
-- =============================================================================

INSERT INTO "WNE".wrkflow_definitions (id, code, name, description, category_id, status, created_by) VALUES
    (1, 'hcm.leave_approval', 'Leave Request Approval', 'Single-step manager approval for employee leave requests.', 1, 'published', 20);

INSERT INTO "WNE".wrkflow_versions (id, definition_id, version_no, is_published, published_by, published_at) VALUES
    (1, 1, 1, TRUE, 20, now() - INTERVAL '30 days');

UPDATE "WNE".wrkflow_definitions SET published_version_id = 1 WHERE id = 1;

INSERT INTO "WNE".wrkflow_steps (id, version_id, step_key, name, type, config, is_entry_step) VALUES
    (1, 1, 'start',             'Start',              'start',    '{}'::jsonb, TRUE),
    (2, 1, 'manager_approval',  'Manager Approval',   'approval', '{"assignee_rule":"role","role":"manager"}'::jsonb, FALSE),
    (3, 1, 'notify_result',     'Notify Employee',    'notify',   '{"category_code":"leave_approval"}'::jsonb, FALSE),
    (4, 1, 'end',               'End',                'end',      '{}'::jsonb, FALSE);

INSERT INTO "WNE".wrkflow_transitions (id, version_id, from_step_id, to_step_id, condition_expression, is_default, sort_order) VALUES
    (1, 1, 1, 2, NULL, TRUE, 1),
    (2, 1, 2, 3, NULL, TRUE, 1),
    (3, 1, 3, 4, NULL, TRUE, 1);

INSERT INTO "WNE".wrkflow_sla_rules (id, step_id, version_id, sla_hours, escalation_action, escalation_target) VALUES
    (1, 2, 1, 24, 'reassign_to_role', 'hr_director');

-- =============================================================================
-- 3. WORKFLOW DEFINITION B — purchase.po_approval (conditional branch)
-- =============================================================================

INSERT INTO "WNE".wrkflow_definitions (id, code, name, description, category_id, status, created_by) VALUES
    (2, 'purchase.po_approval', 'Purchase Order Approval', 'Amount-based routing: manager approval under threshold, finance director above.', 2, 'published', 20);

INSERT INTO "WNE".wrkflow_versions (id, definition_id, version_no, is_published, published_by, published_at) VALUES
    (2, 2, 1, TRUE, 20, now() - INTERVAL '20 days');

UPDATE "WNE".wrkflow_definitions SET published_version_id = 2 WHERE id = 2;

INSERT INTO "WNE".wrkflow_steps (id, version_id, step_key, name, type, config, is_entry_step) VALUES
    (5,  2, 'start',              'Start',                'start',          '{}'::jsonb, TRUE),
    (6,  2, 'amount_check',       'Amount Threshold Check','condition',      '{"field":"amount","threshold":50000000}'::jsonb, FALSE),
    (7,  2, 'manager_approval',   'Purchasing Manager Approval', 'approval', '{"assignee_rule":"role","role":"purchasing_manager"}'::jsonb, FALSE),
    (8,  2, 'director_approval',  'Finance Director Approval',   'approval', '{"assignee_rule":"role","role":"finance_director"}'::jsonb, FALSE),
    (9,  2, 'notify_result',      'Notify Requester',     'notify',         '{"category_code":"po_approval"}'::jsonb, FALSE),
    (10, 2, 'end',                'End',                   'end',            '{}'::jsonb, FALSE);

INSERT INTO "WNE".wrkflow_transitions (id, version_id, from_step_id, to_step_id, condition_expression, is_default, sort_order) VALUES
    (4, 2, 5, 6,  NULL,                                                      TRUE,  1),
    (5, 2, 6, 7,  '{"field":"amount","op":"<=","value":50000000}'::jsonb,    TRUE,  1), -- default/else path
    (6, 2, 6, 8,  '{"field":"amount","op":">","value":50000000}'::jsonb,     FALSE, 2),
    (7, 2, 7, 9,  NULL,                                                      TRUE,  1),
    (8, 2, 8, 9,  NULL,                                                      TRUE,  1),
    (9, 2, 9, 10, NULL,                                                      TRUE,  1);

INSERT INTO "WNE".wrkflow_sla_rules (id, step_id, version_id, sla_hours, escalation_action, escalation_target) VALUES
    (2, 7, 2, 48, 'notify_role', 'purchasing_manager'),
    (3, 8, 2, 48, 'reassign_to_role', 'cfo');

-- =============================================================================
-- 4. WORKFLOW DEFINITION C — purchase.invoice_webhook_sync (webhook + callback)
-- =============================================================================

INSERT INTO "WNE".wrkflow_webhooks (id, name, url, http_method, payload_template, is_active) VALUES
    (1, 'Accounting GL Sync', 'https://tenant-erp.example.test/webhooks/gl-sync', 'POST',
        '{"invoice_id":"{{subject_id}}","amount":"{{amount}}"}'::jsonb, TRUE);

INSERT INTO "WNE".wrkflow_definitions (id, code, name, description, category_id, status, created_by) VALUES
    (3, 'purchase.invoice_webhook_sync', 'Invoice External Sync', 'Pushes a matched invoice to an external accounting endpoint and waits for its acknowledgment callback before closing.', 2, 'published', 20);

INSERT INTO "WNE".wrkflow_versions (id, definition_id, version_no, is_published, published_by, published_at) VALUES
    (3, 3, 1, TRUE, 20, now() - INTERVAL '10 days');

UPDATE "WNE".wrkflow_definitions SET published_version_id = 3 WHERE id = 3;

INSERT INTO "WNE".wrkflow_steps (id, version_id, step_key, name, type, config, is_entry_step) VALUES
    (11, 3, 'start',              'Start',                 'start',              '{}'::jsonb, TRUE),
    (12, 3, 'push_to_accounting', 'Push to Accounting',    'webhook_call',        '{"webhook_id":1}'::jsonb, FALSE),
    (13, 3, 'wait_ack',           'Wait for Acknowledgment','wait_for_callback',  '{"timeout_hours":24}'::jsonb, FALSE),
    (14, 3, 'notify_done',        'Notify Sync Complete',  'notify',              '{"category_code":"general_reminder"}'::jsonb, FALSE),
    (15, 3, 'end',                'End',                    'end',                '{}'::jsonb, FALSE);

INSERT INTO "WNE".wrkflow_transitions (id, version_id, from_step_id, to_step_id, condition_expression, is_default, sort_order) VALUES
    (10, 3, 11, 12, NULL, TRUE, 1),
    (11, 3, 12, 13, NULL, TRUE, 1),
    (12, 3, 13, 14, NULL, TRUE, 1),
    (13, 3, 14, 15, NULL, TRUE, 1);

-- =============================================================================
-- 5. WORKFLOW INSTANCES
-- =============================================================================

-- --- Instance 1: leave_approval, COMPLETED end-to-end -----------------------
INSERT INTO "WNE".wrkflow_instances (id, definition_id, definition_version_id, subject_type, subject_id, status, payload, started_by, started_at, ended_at) VALUES
    (1, 1, 1, 'hcm.leave_requests', 501, 'completed',
        '{"employee_name":"Siti Rahma","leave_type":"Annual","days":3}'::jsonb,
        5, now() - INTERVAL '5 days', now() - INTERVAL '4 days 20 hours');

INSERT INTO "WNE".wrkflow_instance_steps (id, instance_id, step_id, status, assigned_to_user, idempotency_key, started_at, completed_at, decision, comment) VALUES
    (1, 1, 1, 'completed', NULL, 'inst1-step1-attempt1', now() - INTERVAL '5 days', now() - INTERVAL '5 days', NULL, NULL),
    (2, 1, 2, 'completed', 10,   'inst1-step2-attempt1', now() - INTERVAL '5 days', now() - INTERVAL '4 days 22 hours', 'approve', 'Approved, coverage confirmed with the team.'),
    (3, 1, 3, 'completed', NULL, 'inst1-step3-attempt1', now() - INTERVAL '4 days 22 hours', now() - INTERVAL '4 days 20 hours', NULL, NULL),
    (4, 1, 4, 'completed', NULL, 'inst1-step4-attempt1', now() - INTERVAL '4 days 20 hours', now() - INTERVAL '4 days 20 hours', NULL, NULL);

INSERT INTO "WNE".wrkflow_audit_logs (instance_id, instance_step_id, action, actor_user_id, detail, occurred_at) VALUES
    (1, 1, 'instance_started', 5,  '{"subject_type":"hcm.leave_requests","subject_id":501}'::jsonb, now() - INTERVAL '5 days'),
    (1, 2, 'decision_made',    10, '{"decision":"approve"}'::jsonb,                                  now() - INTERVAL '4 days 22 hours'),
    (1, 4, 'instance_completed', NULL, '{}'::jsonb,                                                  now() - INTERVAL '4 days 20 hours');

-- --- Instance 2: leave_approval, RUNNING and SLA-BREACHED -------------------
INSERT INTO "WNE".wrkflow_instances (id, definition_id, definition_version_id, subject_type, subject_id, status, payload, started_by, started_at) VALUES
    (2, 1, 1, 'hcm.leave_requests', 502, 'running',
        '{"employee_name":"Budi Santoso","leave_type":"Sick","days":1}'::jsonb,
        6, now() - INTERVAL '2 days');

INSERT INTO "WNE".wrkflow_instance_steps (id, instance_id, step_id, status, assigned_to_user, assigned_to_role, idempotency_key, due_at, started_at) VALUES
    (5, 2, 1, 'completed',    NULL, NULL,       'inst2-step1-attempt1', NULL, now() - INTERVAL '2 days'),
    (6, 2, 2, 'in_progress',  11,   'manager',  'inst2-step2-attempt1', now() - INTERVAL '1 day', now() - INTERVAL '2 days');

UPDATE "WNE".wrkflow_instance_steps SET completed_at = now() - INTERVAL '2 days' WHERE id = 5;

INSERT INTO "WNE".wrkflow_escalation_log (id, instance_step_id, sla_rule_id, action_applied, escalated_to, escalated_at) VALUES
    (1, 6, 1, 'reassign_to_role', 'hr_director', now() - INTERVAL '12 hours');

INSERT INTO "WNE".wrkflow_audit_logs (instance_id, instance_step_id, action, actor_user_id, detail, occurred_at) VALUES
    (2, 6, 'instance_started', 6,    '{"subject_type":"hcm.leave_requests","subject_id":502}'::jsonb, now() - INTERVAL '2 days'),
    (2, 6, 'escalated',        NULL, '{"escalated_to":"hr_director","sla_rule_id":1}'::jsonb,          now() - INTERVAL '12 hours');

-- --- Instance 3: po_approval, RUNNING, routed to director tier --------------
INSERT INTO "WNE".wrkflow_instances (id, definition_id, definition_version_id, subject_type, subject_id, status, payload, started_by, started_at) VALUES
    (3, 2, 2, 'purchase.pur_order_hdrs', 9001, 'running',
        '{"po_number":"PO-2026-0142","amount":75000000,"requester":"Andi Wijaya"}'::jsonb,
        12, now() - INTERVAL '3 days');

INSERT INTO "WNE".wrkflow_instance_steps (id, instance_id, step_id, status, assigned_to_user, assigned_to_role, idempotency_key, due_at, started_at, completed_at, decision) VALUES
    (7, 3, 5, 'completed',   NULL, NULL,                'inst3-step5-attempt1', NULL,                          now() - INTERVAL '3 days', now() - INTERVAL '3 days', NULL),
    (8, 3, 6, 'completed',   NULL, NULL,                'inst3-step6-attempt1', NULL,                          now() - INTERVAL '3 days', now() - INTERVAL '3 days', NULL),
    (9, 3, 8, 'pending',     NULL, 'finance_director',   'inst3-step8-attempt1', now() - INTERVAL '3 days' + INTERVAL '48 hours', now() - INTERVAL '3 days', NULL, NULL);

INSERT INTO "WNE".wrkflow_audit_logs (instance_id, instance_step_id, action, actor_user_id, detail, occurred_at) VALUES
    (3, 7, 'instance_started', 12, '{"subject_type":"purchase.pur_order_hdrs","subject_id":9001}'::jsonb, now() - INTERVAL '3 days'),
    (3, 8, 'condition_evaluated', NULL, '{"field":"amount","value":75000000,"branch":"director_approval"}'::jsonb, now() - INTERVAL '3 days');

-- --- Instance 4: invoice_webhook_sync, RUNNING, waiting on external callback -
INSERT INTO "WNE".wrkflow_instances (id, definition_id, definition_version_id, subject_type, subject_id, status, payload, started_by, started_at) VALUES
    (4, 3, 3, 'accounting.ar_invoices', 7788, 'running',
        '{"amount":15250000,"invoice_no":"INV-2026-3311"}'::jsonb,
        20, now() - INTERVAL '23 hours');

INSERT INTO "WNE".wrkflow_instance_steps (id, instance_id, step_id, status, idempotency_key, started_at, completed_at) VALUES
    (10, 4, 11, 'completed',        'inst4-step11-attempt1', now() - INTERVAL '23 hours', now() - INTERVAL '23 hours'),
    (11, 4, 12, 'completed',        'inst4-step12-attempt1', now() - INTERVAL '23 hours', now() - INTERVAL '22 hours 55 minutes'),
    (12, 4, 13, 'waiting_external', 'inst4-step13-attempt1', now() - INTERVAL '22 hours 55 minutes', NULL);

INSERT INTO "WNE".wrkflow_callbacks (id, instance_step_id, token, expires_at) VALUES
    (1, 12, 'cbk_9f2a1e7c4d3b4a8f9e0a1b2c3d4e5f60', now() + INTERVAL '1 hour');

INSERT INTO "WNE".wrkflow_audit_logs (instance_id, instance_step_id, action, actor_user_id, detail, occurred_at) VALUES
    (4, 11, 'webhook_dispatched',  NULL, '{"webhook_id":1,"url":"https://tenant-erp.example.test/webhooks/gl-sync"}'::jsonb, now() - INTERVAL '22 hours 55 minutes'),
    (4, 12, 'awaiting_callback',   NULL, '{"token_expires_at":null}'::jsonb, now() - INTERVAL '22 hours 55 minutes');

-- =============================================================================
-- 6. USER NOTIFICATION PREFERENCES
-- =============================================================================

INSERT INTO "WNE".msg_user_preferences (id, user_id, category_id, preferred_channels, opted_out, quiet_hours_start, quiet_hours_end, timezone) VALUES
    (1, 5,  2, '["email","in_app"]'::jsonb, FALSE, '21:00', '07:00', 'Asia/Jakarta'),
    (2, 5,  1, '["email","in_app"]'::jsonb, FALSE, NULL,    NULL,    'Asia/Jakarta'), -- security_alert: mandatory, quiet hours bypassed regardless
    (3, 11, 4, '["in_app"]'::jsonb,         FALSE, '20:00', '08:00', 'Asia/Jakarta'),
    (4, 12, 3, '["email"]'::jsonb,          FALSE, NULL,    NULL,    'Asia/Jakarta');

-- =============================================================================
-- 7. NOTIFICATIONS & DELIVERY LIFECYCLE
-- =============================================================================

-- --- Notification 1: leave approved — fully delivered on two channels -------
INSERT INTO "WNE".msg_notifications (id, category_id, subject_type, subject_id, recipient_user_id, payload, priority, triggered_by_instance_step_id, created_at) VALUES
    (1, 2, 'hcm.leave_requests', 501, 5,
        '{"employee_name":"Siti Rahma","leave_type":"Annual","days":3,"status":"approved","approver_name":"Ratna Dewi"}'::jsonb,
        'normal', 3, now() - INTERVAL '4 days 22 hours');

INSERT INTO "WNE".msg_notification_deliveries (id, notification_id, channel_type_id, template_id, status, provider_message_id, attempt_count, sent_at, delivered_at) VALUES
    (1, 1, 1, 1, 'delivered', 'smtp-9a7c1e2f', 1, now() - INTERVAL '4 days 21 hours 58 minutes', now() - INTERVAL '4 days 21 hours 55 minutes'),
    (2, 1, 2, 2, 'delivered', NULL,             1, now() - INTERVAL '4 days 21 hours 59 minutes', now() - INTERVAL '4 days 21 hours 59 minutes');

INSERT INTO "WNE".msg_delivery_events (delivery_id, event_type, provider_payload, occurred_at) VALUES
    (1, 'created',   '{}'::jsonb, now() - INTERVAL '4 days 22 hours'),
    (1, 'queued',    '{}'::jsonb, now() - INTERVAL '4 days 22 hours'),
    (1, 'sending',   '{}'::jsonb, now() - INTERVAL '4 days 21 hours 58 minutes'),
    (1, 'sent',      '{"provider":"smtp"}'::jsonb, now() - INTERVAL '4 days 21 hours 58 minutes'),
    (1, 'delivered', '{"provider":"smtp","code":250}'::jsonb, now() - INTERVAL '4 days 21 hours 55 minutes'),
    (2, 'created',   '{}'::jsonb, now() - INTERVAL '4 days 22 hours'),
    (2, 'queued',    '{}'::jsonb, now() - INTERVAL '4 days 22 hours'),
    (2, 'sending',   '{}'::jsonb, now() - INTERVAL '4 days 21 hours 59 minutes'),
    (2, 'sent',      '{}'::jsonb, now() - INTERVAL '4 days 21 hours 59 minutes'),
    (2, 'delivered', '{"channel":"in_app"}'::jsonb, now() - INTERVAL '4 days 21 hours 59 minutes');

-- --- Notification 2: security alert — exhausted retries, dead-lettered ------
INSERT INTO "WNE".msg_notifications (id, category_id, subject_type, subject_id, recipient_user_id, payload, priority, created_at) VALUES
    (2, 1, NULL, NULL, 5, '{"event":"password_reset_requested","requested_at":"2026-07-24 09:12:00+07"}'::jsonb, 'urgent', now() - INTERVAL '1 day');

INSERT INTO "WNE".msg_notification_deliveries (id, notification_id, channel_type_id, template_id, status, attempt_count, next_retry_at, sent_at, error_detail) VALUES
    (3, 2, 1, 4, 'dead_lettered', 4, NULL, NULL, 'Provider SMTP connection timed out after 4 attempts.');

INSERT INTO "WNE".msg_delivery_events (delivery_id, event_type, provider_payload, occurred_at) VALUES
    (3, 'created',        '{}'::jsonb, now() - INTERVAL '1 day'),
    (3, 'queued',         '{}'::jsonb, now() - INTERVAL '1 day'),
    (3, 'sending',        '{}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '1 minute'),
    (3, 'failed',         '{"error":"connection_timeout"}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '1 minute'),
    (3, 'retrying',       '{"attempt":2}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '6 minutes'),
    (3, 'failed',         '{"error":"connection_timeout"}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '6 minutes'),
    (3, 'retrying',       '{"attempt":3}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '36 minutes'),
    (3, 'failed',         '{"error":"connection_timeout"}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '36 minutes'),
    (3, 'retrying',       '{"attempt":4}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '2 hours 6 minutes'),
    (3, 'failed',         '{"error":"connection_timeout"}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '2 hours 6 minutes'),
    (3, 'dead_lettered',  '{"max_attempts_reached":true}'::jsonb, now() - INTERVAL '1 day' + INTERVAL '2 hours 6 minutes');

INSERT INTO "WNE".msg_dead_letters (id, delivery_id, message_snapshot, failure_history, resolved_action, resolved_by, resolved_at) VALUES
    (1, 3,
        '{"to_user_id":5,"channel":"email","subject":"Permintaan Reset Kata Sandi","body":"Kami menerima permintaan reset kata sandi untuk akun Anda pada 2026-07-24 09:12:00+07. Abaikan email ini jika bukan Anda."}'::jsonb,
        '[{"attempt":1,"error":"connection_timeout"},{"attempt":2,"error":"connection_timeout"},{"attempt":3,"error":"connection_timeout"},{"attempt":4,"error":"connection_timeout"}]'::jsonb,
        NULL, NULL, NULL);

-- --- Notification 3: low-priority reminder — deferred during quiet hours ----
INSERT INTO "WNE".msg_notifications (id, category_id, subject_type, subject_id, recipient_user_id, payload, priority, created_at) VALUES
    (3, 4, 'purchase.pur_order_hdrs', 9001, 11, '{"message":"3 PO menunggu persetujuan Anda"}'::jsonb, 'low', now() - INTERVAL '10 hours');

INSERT INTO "WNE".msg_notification_deliveries (id, notification_id, channel_type_id, template_id, status, attempt_count, next_retry_at) VALUES
    (4, 3, 2, 5, 'deferred', 0, (current_date + INTERVAL '1 day' + TIME '08:00')::timestamptz);

INSERT INTO "WNE".msg_delivery_events (delivery_id, event_type, provider_payload, occurred_at) VALUES
    (4, 'created', '{}'::jsonb, now() - INTERVAL '10 hours'),
    (4, 'queued',  '{"deferred_reason":"recipient_quiet_hours"}'::jsonb, now() - INTERVAL '10 hours');

-- =============================================================================
-- 8. RESET SEQUENCES (explicit IDs were used above)
-- =============================================================================

SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_categories', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_categories));
SELECT setval(pg_get_serial_sequence('"WNE".channel_types', 'id'), (SELECT MAX(id) FROM "WNE".channel_types));
SELECT setval(pg_get_serial_sequence('"WNE".msg_categories', 'id'), (SELECT MAX(id) FROM "WNE".msg_categories));
SELECT setval(pg_get_serial_sequence('"WNE".msg_channel_configs', 'id'), (SELECT MAX(id) FROM "WNE".msg_channel_configs));
SELECT setval(pg_get_serial_sequence('"WNE".msg_templates', 'id'), (SELECT MAX(id) FROM "WNE".msg_templates));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_definitions', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_definitions));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_versions', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_versions));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_steps', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_steps));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_transitions', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_transitions));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_sla_rules', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_sla_rules));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_webhooks', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_webhooks));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_instances', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_instances));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_instance_steps', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_instance_steps));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_escalation_log', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_escalation_log));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_callbacks', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_callbacks));
SELECT setval(pg_get_serial_sequence('"WNE".wrkflow_audit_logs', 'id'), (SELECT MAX(id) FROM "WNE".wrkflow_audit_logs));
SELECT setval(pg_get_serial_sequence('"WNE".msg_user_preferences', 'id'), (SELECT MAX(id) FROM "WNE".msg_user_preferences));
SELECT setval(pg_get_serial_sequence('"WNE".msg_notifications', 'id'), (SELECT MAX(id) FROM "WNE".msg_notifications));
SELECT setval(pg_get_serial_sequence('"WNE".msg_notification_deliveries', 'id'), (SELECT MAX(id) FROM "WNE".msg_notification_deliveries));
SELECT setval(pg_get_serial_sequence('"WNE".msg_dead_letters', 'id'), (SELECT MAX(id) FROM "WNE".msg_dead_letters));

COMMIT;

-- =============================================================================
-- End of WNE seed data
-- =============================================================================
