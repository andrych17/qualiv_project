-- =====================================================================
-- WNE — Dummy / Seed Data
-- Scenario: two real-world workflows running side by side —
--   1) Purchase Order Approval  (tenant_id = 1, "Acme Corp")
--   2) Leave Request Approval   (tenant_id = 1, with one instance on
--      tenant_id = 2, "Brightleaf Studio", to show tenant isolation)
--
-- Assumes wne_schema.sql has already run, which seeds:
--   channels: 1=email, 2=sms, 3=whatsapp, 4=push, 5=inapp
--   actions : 1=approve, 2=reject, 3=revise, 4=escalate, 5=delegate
-- All rows below use EXPLICIT ids so foreign keys are predictable —
-- safe to run once against a freshly migrated schema.
-- =====================================================================

SET search_path TO wne;

-- =====================================================================
-- MASTER DATA (additional)
-- =====================================================================

INSERT INTO wne.providers (id, channel_id, code, name, is_active) VALUES
    (1, 1, 'ses',              'Amazon SES',                TRUE),
    (2, 1, 'smtp_backup',      'Backup SMTP Relay',         TRUE),
    (3, 2, 'twilio',           'Twilio SMS',                TRUE),
    (4, 3, 'whatsapp_business','WhatsApp Business API',     TRUE),
    (5, 4, 'fcm',              'Firebase Cloud Messaging',  TRUE),
    (6, 4, 'onesignal',        'OneSignal',                 FALSE),
    (7, 5, 'internal',         'Internal In-App Bus',       TRUE);
SELECT setval('wne.providers_id_seq', 7);

INSERT INTO wne.events (id, code, name, module, description, is_active) VALUES
    (1, 'po.created',            'Purchase Order Created',    'purchasing.po', 'Fired when a PO is submitted for approval', TRUE),
    (2, 'po.approved',           'Purchase Order Approved',   'purchasing.po', 'Fired when a PO reaches final approval', TRUE),
    (3, 'po.rejected',           'Purchase Order Rejected',   'purchasing.po', 'Fired when a PO is rejected at any stage', TRUE),
    (4, 'workflow.step_pending', 'Workflow Step Pending',     'wne',           'Generic: a workflow task is awaiting action', TRUE),
    (5, 'workflow.escalated',    'Workflow Step Escalated',   'wne',           'Generic: a task breached its SLA and escalated', TRUE),
    (6, 'leave.requested',       'Leave Request Submitted',   'hr.leave',      'Fired when an employee submits a leave request', TRUE),
    (7, 'leave.approved',        'Leave Request Approved',    'hr.leave',      'Fired when a leave request is fully approved', TRUE);
SELECT setval('wne.events_id_seq', 7);

-- =====================================================================
-- WORKFLOW DOMAIN
-- =====================================================================

INSERT INTO wne.wrkflow_definitions (id, tenant_id, code, name, module, description, version, is_active) VALUES
    (1, 1, 'po_approval',    'Purchase Order Approval', 'purchasing.po', 'Manager review, with Finance review above 10,000,000', 1, TRUE),
    (2, 1, 'leave_approval', 'Leave Request Approval',  'hr.leave',      'Manager review, HR committee sign-off',                 1, TRUE);
SELECT setval('wne.wrkflow_definitions_id_seq', 2);

INSERT INTO wne.wrkflow_states (id, wrkflow_definition_id, code, name, is_initial, is_final, sla_hours, sort_order) VALUES
    (1, 1, 'draft',           'Draft',            TRUE,  FALSE, NULL, 1),
    (2, 1, 'manager_review',  'Manager Review',   FALSE, FALSE, 24,   2),
    (3, 1, 'finance_review',  'Finance Review',   FALSE, FALSE, 48,   3),
    (4, 1, 'approved',        'Approved',         FALSE, TRUE,  NULL, 4),
    (5, 1, 'rejected',        'Rejected',         FALSE, TRUE,  NULL, 5),
    (6, 2, 'draft',           'Draft',            TRUE,  FALSE, NULL, 1),
    (7, 2, 'manager_review',  'Manager Review',   FALSE, FALSE, 24,   2),
    (8, 2, 'approved',        'Approved',         FALSE, TRUE,  NULL, 3),
    (9, 2, 'rejected',        'Rejected',         FALSE, TRUE,  NULL, 4);
SELECT setval('wne.wrkflow_states_id_seq', 9);

INSERT INTO wne.wrkflow_transitions (id, wrkflow_definition_id, from_state_id, to_state_id, action_id, name, sort_order) VALUES
    (1, 1, 1, 2, 1, 'Submit for Review',           1),
    (2, 1, 2, 3, 1, 'Approve (route to Finance)',  2),
    (3, 1, 2, 4, 1, 'Approve (auto-final)',        3),
    (4, 1, 2, 5, 2, 'Reject by Manager',           4),
    (5, 1, 3, 4, 1, 'Approve by Finance',          5),
    (6, 1, 3, 5, 2, 'Reject by Finance',           6),
    (7, 2, 6, 7, 1, 'Submit for Review',           1),
    (8, 2, 7, 8, 1, 'Approve Leave',               2),
    (9, 2, 7, 9, 2, 'Reject Leave',                3);
SELECT setval('wne.wrkflow_transitions_id_seq', 9);

-- group_no: same group = AND, different group = OR
INSERT INTO wne.wrkflow_transition_conditions (id, wrkflow_transition_id, group_no, field, operator, value) VALUES
    (1, 1, 1, 'amount',           '>',  '0'),
    (2, 2, 1, 'amount',           '>',  '10000000'),
    (3, 3, 1, 'amount',           '<=', '10000000'),
    (4, 5, 1, 'budget_code',      'is_not_null', ''),
    (5, 8, 1, 'days_requested',   '<=', '14'),
    (6, 8, 2, 'has_hr_flag',      '=',  'false');
SELECT setval('wne.wrkflow_transition_conditions_id_seq', 6);

INSERT INTO wne.wrkflow_transition_approvers (id, wrkflow_transition_id, approver_type, approver_ref, quorum_rule, sort_order) VALUES
    (1, 1, 'dynamic', 'requester_manager',    'any',      1),
    (2, 2, 'role',    'department_manager',   'any',      1),
    (3, 3, 'role',    'department_manager',   'any',      1),
    (4, 5, 'role',    'finance_controller',   'any',      1),
    (5, 6, 'role',    'finance_controller',   'any',      1),
    (6, 7, 'dynamic', 'requester_manager',    'any',      1),
    (7, 8, 'group',   'hr_leave_committee',   'majority', 1);
SELECT setval('wne.wrkflow_transition_approvers_id_seq', 7);

INSERT INTO wne.wrkflow_instances (id, tenant_id, wrkflow_definition_id, subject_type, subject_id, current_state_id, initiator_id, context, status, started_at, completed_at) VALUES
    (1, 1, 1, 'App\Modules\Purchasing\Models\PurchaseOrder', 142, 2, 501, '{"amount": 8500000, "budget_code": "OPEX-2026-04"}',  'in_progress', '2026-07-01 09:00:00+00', NULL),
    (2, 1, 1, 'App\Modules\Purchasing\Models\PurchaseOrder', 143, 3, 502, '{"amount": 18500000, "budget_code": "CAPEX-2026-02"}','in_progress', '2026-07-05 08:30:00+00', NULL),
    (3, 1, 1, 'App\Modules\Purchasing\Models\PurchaseOrder', 138, 4, 503, '{"amount": 3200000, "budget_code": "OPEX-2026-03"}',  'approved',    '2026-06-20 10:15:00+00', '2026-06-22 14:40:00+00'),
    (4, 1, 1, 'App\Modules\Purchasing\Models\PurchaseOrder', 139, 5, 504, '{"amount": 45000000}',                                 'rejected',    '2026-06-18 11:00:00+00', '2026-06-19 09:20:00+00'),
    (5, 1, 2, 'App\Modules\HR\Models\LeaveRequest',           87, 7, 505, '{"days_requested": 5, "has_hr_flag": false}',          'in_progress', '2026-07-10 07:45:00+00', NULL),
    (6, 2, 2, 'App\Modules\HR\Models\LeaveRequest',           85, 8, 506, '{"days_requested": 3, "has_hr_flag": false}',          'approved',    '2026-06-25 08:00:00+00', '2026-06-26 16:10:00+00');
SELECT setval('wne.wrkflow_instances_id_seq', 6);

INSERT INTO wne.wrkflow_tasks (id, wrkflow_instance_id, wrkflow_transition_id, state_id, assignee_type, assignee_ref, status, due_at) VALUES
    (1, 1, 1, 2, 'user',  '210', 'pending',  '2026-07-03 09:00:00+00'),
    (2, 2, 2, 3, 'role',  'finance_controller', 'pending', '2026-07-07 08:30:00+00'),
    (3, 3, 1, 2, 'user',  '211', 'approved', '2026-06-21 10:15:00+00'),
    (4, 3, 3, 4, 'user',  '211', 'approved', '2026-06-22 14:40:00+00'),
    (5, 4, 4, 2, 'user',  '212', 'rejected', '2026-06-19 09:20:00+00'),
    (6, 5, 7, 7, 'user',  '213', 'pending',  '2026-07-11 07:45:00+00'),
    (7, 6, 7, 7, 'user',  '214', 'approved', '2026-06-25 12:00:00+00'),
    (8, 6, 8, 8, 'group', 'hr_leave_committee', 'approved', '2026-06-26 16:10:00+00');
SELECT setval('wne.wrkflow_tasks_id_seq', 8);

INSERT INTO wne.wrkflow_task_actions (id, wrkflow_task_id, actor_id, action_id, remarks, acted_at) VALUES
    (1, 3, 211, 1, 'Looks good, forwarding for final approval.',      '2026-06-21 10:15:00+00'),
    (2, 4, 211, 1, 'Within department budget, approved.',             '2026-06-22 14:40:00+00'),
    (3, 5, 212, 2, 'Amount exceeds department budget cap for Q3.',    '2026-06-19 09:20:00+00'),
    (4, 7, 214, 1, 'Coverage confirmed with team, approved.',         '2026-06-25 12:00:00+00'),
    (5, 8, 215, 1, 'HR committee sign-off (1 of 2).',                 '2026-06-26 16:05:00+00'),
    (6, 8, 216, 1, 'HR committee sign-off (2 of 2), majority reached.','2026-06-26 16:10:00+00');
SELECT setval('wne.wrkflow_task_actions_id_seq', 6);

INSERT INTO wne.wrkflow_delegations (id, tenant_id, delegator_id, delegate_id, start_date, end_date, is_active) VALUES
    (1, 1, 210, 217, '2026-07-14', '2026-07-21', TRUE),
    (2, 1, 211, 218, '2026-08-01', '2026-08-10', TRUE),
    (3, 1, 213, 219, '2026-07-15', '2026-07-18', TRUE),
    (4, 1, 214, 220, '2026-09-01', '2026-09-05', FALSE),
    (5, 2, 506, 521, '2026-07-20', '2026-07-25', TRUE);
SELECT setval('wne.wrkflow_delegations_id_seq', 5);

-- =====================================================================
-- MESSAGING DOMAIN
-- =====================================================================

INSERT INTO wne.msg_templates (id, tenant_id, event_id, channel_id, locale, version, subject, body, is_active) VALUES
    (1, 1, 4, 5, 'en', 1, NULL,
        'You have a new approval task: {{workflow_name}} for {{subject_reference}}.', TRUE),
    (2, 1, 4, 1, 'en', 1, 'Approval needed: {{workflow_name}}',
        'Hi {{approver_name}}, {{requester_name}} submitted {{subject_reference}} for your review. Amount: {{amount}}. Review here: {{approval_link}}', TRUE),
    (3, 1, 5, 2, 'en', 1, NULL,
        'URGENT: {{workflow_name}} for {{subject_reference}} is overdue and has been escalated to you.', TRUE),
    (4, 1, 2, 1, 'en', 1, 'Your PO {{subject_reference}} was approved',
        'Good news {{requester_name}}, your purchase order {{subject_reference}} has been fully approved.', TRUE),
    (5, 1, 7, 1, 'en', 1, 'Leave request approved',
        'Hi {{requester_name}}, your leave request for {{days_requested}} day(s) has been approved.', TRUE),
    (6, 1, 6, 5, 'en', 1, NULL,
        '{{requester_name}} requested {{days_requested}} day(s) of leave, pending your review.', TRUE);
SELECT setval('wne.msg_templates_id_seq', 6);

INSERT INTO wne.msg_channel_configs (id, tenant_id, channel_id, provider_id, credentials, sender_identity, rate_limit_per_min, is_active) VALUES
    (1, 1, 1, 1, '{"region": "ap-southeast-1"}',         'no-reply@acmecorp.com',    300,  TRUE),
    (2, 1, 2, 3, '{"account_sid": "AC_redacted"}',       '+16505551234',             60,   TRUE),
    (3, 1, 3, 4, '{"waba_id": "waba_redacted"}',         '+16505555678',             60,   TRUE),
    (4, 1, 4, 5, '{"project_id": "acme-erp-prod"}',      NULL,                       500,  TRUE),
    (5, 1, 5, 7, '{}',                                    NULL,                       NULL, TRUE),
    (6, 2, 1, 2, '{"host": "smtp.brightleaf.internal"}', 'no-reply@brightleaf.io',   150,  TRUE);
SELECT setval('wne.msg_channel_configs_id_seq', 6);

INSERT INTO wne.msg_routing_rules (id, tenant_id, event_id, channel_id, recipient_type, recipient_ref, is_active, sort_order) VALUES
    (1, 1, 4, 5, 'workflow_approver', NULL,               TRUE, 1),
    (2, 1, 4, 1, 'workflow_approver', NULL,               TRUE, 2),
    (3, 1, 5, 2, 'dynamic',           'approver_manager',  TRUE, 1),
    (4, 1, 2, 1, 'record_owner',      NULL,                TRUE, 1),
    (5, 1, 7, 1, 'record_owner',      NULL,                TRUE, 1),
    (6, 1, 6, 5, 'workflow_approver', NULL,                TRUE, 1);
SELECT setval('wne.msg_routing_rules_id_seq', 6);

INSERT INTO wne.msg_user_preferences (id, tenant_id, user_id, channel_id, is_opted_in) VALUES
    (1, 1, 210, 2, FALSE),
    (2, 1, 211, 2, TRUE),
    (3, 1, 501, 4, TRUE),
    (4, 1, 502, 3, FALSE),
    (5, 1, 213, 2, FALSE),
    (6, 2, 506, 1, TRUE);
SELECT setval('wne.msg_user_preferences_id_seq', 6);

INSERT INTO wne.msg_notification_log (id, tenant_id, event_id, wrkflow_instance_id, recipient_id, recipient_address, channel_id, template_id, payload, status) VALUES
    (1, 1, 4, 1, 210, 'daniel.hartono@acmecorp.com', 1, 2, '{"subject_reference": "PO-2026-0142", "amount": 8500000}', 'sent'),
    (2, 1, 4, 1, 210, 'inapp:210',                    5, 1, '{"subject_reference": "PO-2026-0142"}',                    'sent'),
    (3, 1, 5, 2, 205, '+16505559012',                 2, 3, '{"subject_reference": "PO-2026-0143"}',                    'sent'),
    (4, 1, 2, 3, 503, 'rina.wijaya@acmecorp.com',     1, 4, '{"subject_reference": "PO-2026-0138"}',                    'sent'),
    (5, 2, 7, 6, 506, 'employee85@brightleaf.io',     1, 5, '{"days_requested": 3}',                                    'sent'),
    (6, 1, 6, 5, 213, 'inapp:213',                    5, 6, '{"days_requested": 5}',                                    'queued'),
    (7, 1, 4, 2, 205, 'daniel2@acmecorp.com',         1, 2, '{"subject_reference": "PO-2026-0143"}',                    'dead_letter');
SELECT setval('wne.msg_notification_log_id_seq', 7);

INSERT INTO wne.msg_notification_attempts (id, msg_notification_log_id, attempt_no, provider_response, status, error_message, attempted_at) VALUES
    (1, 1, 1, '{"message_id": "ses-8841"}',        'success', NULL,                                     '2026-07-01 09:03:00+00'),
    (2, 2, 1, '{"delivered": true}',                'success', NULL,                                     '2026-07-01 09:03:05+00'),
    (3, 3, 1, NULL,                                  'failed',  'Twilio: invalid destination number',    '2026-07-05 10:00:00+00'),
    (4, 3, 2, '{"sid": "SM_redacted"}',             'success', NULL,                                     '2026-07-05 10:05:00+00'),
    (5, 4, 1, '{"message_id": "ses-9012"}',        'success', NULL,                                     '2026-06-22 14:41:00+00'),
    (6, 6, 1, '{"queued": true}',                   'success', NULL,                                     '2026-07-10 07:46:00+00'),
    (7, 7, 1, NULL,                                  'failed',  'SMTP 550: mailbox unavailable',         '2026-07-05 11:00:00+00'),
    (8, 7, 2, NULL,                                  'failed',  'SMTP 550: mailbox unavailable (retry)', '2026-07-05 12:00:00+00');
SELECT setval('wne.msg_notification_attempts_id_seq', 8);
