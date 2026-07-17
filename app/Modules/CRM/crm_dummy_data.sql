-- =============================================================================
-- CRM — Dummy / Seed Data
-- Scenario: a Legal-vertical tenant (matches "Legal is first to ship" in
-- CLAUDE.md) with a realistic spread across every CRM engine:
--   - Companies + Contacts, some contacts employed by companies, one
--     standalone individual client.
--   - Roles: a partner holding more than one role at once (Client + Referral).
--   - Leads across every stage, including one converted lead and one
--     disqualified lead.
--   - After Sales Service cases, one pointing at a hypothetical LEGAL case
--     record via subject_type/subject_id (informational only, not an FK).
--   - Helpdesk tickets, including one from an unidentified requester.
--   - One partner_merge_log entry showing a duplicate contact merged away.
--
-- Assumes CRM_Schema.sql has already run (which seeds partner_role_types,
-- lead_sources, ticket_categories with is_system defaults) and that
-- public.users already has rows for the internal user ids referenced below
-- (210-215). Adjust those ids to match your actual users table, or seed a
-- handful of placeholder users first — see the block at the very top.
--
-- All rows use EXPLICIT ids so foreign keys are predictable — safe to run
-- once against a freshly migrated schema, matching the convention used in
-- wne_dummy_data.sql.
-- =============================================================================

SET search_path TO "CRM";

-- =====================================================================
-- Placeholder users (skip this block if public.users is already seeded —
-- these ids are referenced throughout as owners/agents/assignees).
-- =====================================================================

INSERT INTO public.users (id, name, email) VALUES
    (210, 'Rina Wijaya',   'rina.wijaya@lawfirm.test'),
    (211, 'Daniel Hartono','daniel.hartono@lawfirm.test'),
    (212, 'Amanda Putri',  'amanda.putri@lawfirm.test'),
    (213, 'Farid Hassan',  'farid.hassan@lawfirm.test'),
    (214, 'Siti Rahman',   'siti.rahman@lawfirm.test'),
    (215, 'Budi Santoso',  'budi.santoso@lawfirm.test')
ON CONFLICT (id) DO NOTHING;
SELECT setval('public.users_id_seq', GREATEST((SELECT MAX(id) FROM public.users), 215));

-- =====================================================================
-- MASTER DATA (tenant-added, alongside the is_system defaults from
-- CRM_Schema.sql)
-- =====================================================================

INSERT INTO "CRM".industries (id, code, label) VALUES
    (1, 'manufacturing', 'Manufacturing'),
    (2, 'real_estate',   'Real Estate'),
    (3, 'retail',        'Retail'),
    (4, 'professional_services', 'Professional Services');
SELECT setval('"CRM".industries_id_seq', 4);

-- =====================================================================
-- PARTNER REGISTRY — Companies first, then Contacts that reference them.
-- =====================================================================

-- --- Companies (type = organization) ---------------------------------
INSERT INTO "CRM".partners
    (id, type, name, trade_name, registration_no, industry_id, owner_id, source, notes) VALUES
    (1, 'organization', 'PT Meridian Manufacturing',      'Meridian Manufacturing', 'AHU-0011223.2019', 1, 211, 'manual',
        'Long-standing corporate client, primary contact is their in-house counsel.'),
    (2, 'organization', 'Apex Legal Supplies Sdn Bhd',    'Apex Legal Supplies',    'SSM-0442198-X',    4, 210, 'manual',
        'Office/document supplies vendor for the firm.'),
    (3, 'organization', 'Horizon Realty Group',           'Horizon Realty',         'AHU-0099441.2021', 2, 211, 'manual',
        'Property developer client, multiple ongoing matters.'),
    (4, 'organization', 'Chen & Partners Notary Office',  NULL,                     'NOT-2017-0456',    4, 210, 'manual',
        'Referral partner — sends notarization-adjacent clients our way.');
SELECT setval('"CRM".partners_id_seq', 4);

-- --- Contacts (type = individual) — some employed by the above -------
INSERT INTO "CRM".partners
    (id, type, parent_partner_id, name, first_name, last_name, position_title, owner_id, source, notes) VALUES
    (5, 'individual', 1, 'Siti Rahman',  'Siti',  'Rahman',  'In-House Legal Counsel', 211, 'manual', 'Primary contact for all Meridian matters.'),
    (6, 'individual', 2, 'David Tan',    'David', 'Tan',     'Account Manager',        210, 'manual', NULL),
    (7, 'individual', 3, 'Farid Hassan', 'Farid', 'Hassan',  'Legal & Compliance Manager', 211, 'manual', NULL),
    (8, 'individual', 4, 'Michael Chen', 'Michael','Chen',   'Managing Notary',         210, 'manual', NULL);
SELECT setval('"CRM".partners_id_seq', 8, true);

-- --- Standalone individual (no parent company) ------------------------
INSERT INTO "CRM".partners
    (id, type, name, first_name, last_name, owner_id, source, notes) VALUES
    (9, 'individual', 'Amanda Wong', 'Amanda', 'Wong', 212, 'manual', 'Sole proprietor, individual estate-planning client.');
SELECT setval('"CRM".partners_id_seq', 9, true);

-- --- A partner created via lead conversion (see leads section below) --
INSERT INTO "CRM".partners
    (id, type, name, trade_name, owner_id, source, notes) VALUES
    (10, 'organization', 'Halim Trading Co.', 'Halim Trading', 213, 'lead_conversion', 'Converted from Lead #3 after a trade-dispute intake call.');
SELECT setval('"CRM".partners_id_seq', 10, true);

-- --- A duplicate contact, later merged into Amanda Wong (id 9) --------
INSERT INTO "CRM".partners
    (id, type, name, first_name, last_name, owner_id, source, is_active, merged_into_partner_id) VALUES
    (11, 'individual', 'A. Wong', 'A.', 'Wong', 212, 'manual', false, 9);
SELECT setval('"CRM".partners_id_seq', 11, true);

-- =====================================================================
-- ADDRESSES
-- =====================================================================

INSERT INTO "CRM".addresses (partner_id, type, line1, city, state_province, postal_code, country_code, is_primary) VALUES
    (1, 'office',   'Jl. Industri Raya No. 45', 'Bekasi',   'West Java',    '17530', 'ID', true),
    (3, 'office',   'Menara Horizon, Lt. 12',   'Jakarta',  'DKI Jakarta',  '12190', 'ID', true),
    (9, 'billing',  'Jl. Kenanga No. 8',        'Lumajang', 'East Java',    '67312', 'ID', true);

-- =====================================================================
-- CONTACT POINTS
-- =====================================================================

INSERT INTO "CRM".contact_points (partner_id, type, value, is_primary) VALUES
    (1, 'email', 'legal@meridianmfg.test',        true),
    (5, 'email', 'siti.rahman@meridianmfg.test',  true),
    (5, 'mobile','+62-812-3456-7801',              false),
    (6, 'email', 'david.tan@apexlegalsupplies.test', true),
    (7, 'email', 'farid.hassan@horizonrealty.test', true),
    (9, 'email', 'amanda.wong@example.test',       true),
    (9, 'mobile','+62-813-9988-1122',              false);

-- =====================================================================
-- PARTNER ROLES — note Chen & Partners holds two roles at once.
-- =====================================================================

INSERT INTO "CRM".partner_roles (partner_id, role_type_id, assigned_by) VALUES
    (1,  3, 211),  -- Meridian Manufacturing -> Client
    (2,  2, 210),  -- Apex Legal Supplies    -> Vendor
    (3,  3, 211),  -- Horizon Realty         -> Client
    (4,  5, 210),  -- Chen & Partners        -> Referral Partner
    (4,  3, 210),  -- Chen & Partners        -> also a Client (two roles at once)
    (9,  3, 212),  -- Amanda Wong            -> Client
    (10, 3, 213);  -- Halim Trading          -> Client (post lead-conversion)

-- =====================================================================
-- PARTNER RELATIONSHIPS (generalized, beyond parent_partner_id)
-- =====================================================================

INSERT INTO "CRM".partner_relationships (partner_id, related_partner_id, relationship_type, notes) VALUES
    (10, 4, 'referred_by', 'Halim Trading was referred to the firm by Chen & Partners Notary Office.');

-- =====================================================================
-- LEADS — spanning every stage.
-- =====================================================================

INSERT INTO "CRM".leads
    (id, name, company_name, source_id, stage, owner_id, estimated_value, next_action_at, converted_partner_id, disqualify_reason) VALUES
    (1, 'Robert Halim',  'Halim Trading Co.', 3, 'converted', 213, 25000000, NULL, 10, NULL),
    (2, 'Lina Kusuma',   NULL,                 2, 'new',      212, NULL,     '2026-07-22 09:00:00+00', NULL, NULL),
    (3, 'Budi Santoso',  'PT Sinar Jaya',      1, 'qualified',215, 40000000, '2026-07-20 14:00:00+00', NULL, NULL),
    (4, 'Grace Tanuwijaya', 'Tanuwijaya & Co.',4, 'disqualified', 210, NULL, NULL, NULL, 'no_budget');
SELECT setval('"CRM".leads_id_seq', 4);

INSERT INTO "CRM".lead_activities (lead_id, activity_type, body, logged_by, logged_at) VALUES
    (1, 'call',   'Initial intake call regarding a trade dispute with a supplier.', 213, '2026-06-28 10:00:00+00'),
    (1, 'meeting','In-person meeting, scope agreed, proceeding to conversion.',      213, '2026-07-02 15:30:00+00'),
    (1, 'stage_change', 'Converted to Partner #10 (Halim Trading Co.).',             213, '2026-07-02 16:00:00+00'),
    (2, 'email',  'Submitted inquiry via website contact form.',                     212, '2026-07-14 08:15:00+00'),
    (3, 'call',   'Discussed potential corporate restructuring matter.',             215, '2026-07-10 11:00:00+00'),
    (3, 'note',   'Qualified — budget confirmed, awaiting proposal meeting.',        215, '2026-07-11 09:00:00+00'),
    (4, 'stage_change', 'Disqualified — prospect confirmed no budget this fiscal year.', 210, '2026-07-05 13:00:00+00');

-- =====================================================================
-- AFTER SALES SERVICE — one case links to a hypothetical LEGAL case
-- record via subject_type/subject_id (informational pointer only).
-- =====================================================================

INSERT INTO "CRM".svc_cases
    (id, partner_id, subject, category_id, priority, status, assigned_to, sla_due_at, subject_type, subject_id) VALUES
    (1, 1, 'Request for certified copies of Q2 contract filings', 2, 'normal', 'in_progress', 211, '2026-07-21 17:00:00+00', 'legal.case_hdrs', 4821),
    (2, 3, 'Status update requested on zoning appeal',            3, 'high',   'open',        211, '2026-07-19 17:00:00+00', 'legal.case_hdrs', 4790),
    (3, 1, 'Prior year invoice discrepancy',                      1, 'normal', 'resolved',    210, '2026-07-05 17:00:00+00', NULL, NULL);
SELECT setval('"CRM".svc_cases_id_seq', 3);

UPDATE "CRM".svc_cases SET closed_at = '2026-07-04 16:20:00+00' WHERE id = 3;

INSERT INTO "CRM".svc_case_activities (case_id, activity_type, body, logged_by, logged_at) VALUES
    (1, 'note',          'Requested documents identified, awaiting notarization before release.', 211, '2026-07-15 09:00:00+00'),
    (2, 'note',          'Escalated internally — appeal hearing date confirmed for next week.',    211, '2026-07-16 10:30:00+00'),
    (2, 'status_change', 'Priority raised to High due to upcoming hearing date.',                  211, '2026-07-16 10:31:00+00'),
    (3, 'note',          'Confirmed discrepancy was a duplicate line item, credit note issued.',    210, '2026-07-04 16:00:00+00'),
    (3, 'status_change', 'Case resolved and closed.',                                               210, '2026-07-04 16:20:00+00');

-- =====================================================================
-- HELPDESK — including one ticket from an unidentified requester.
-- =====================================================================

INSERT INTO "CRM".hd_tickets
    (id, partner_id, requester_name, requester_contact, subject, category_id, priority, status, assigned_to, sla_due_at, channel) VALUES
    (1, 9, NULL, NULL, 'Question about upcoming invoice due date', 1, 'low', 'resolved', 210, '2026-07-10 17:00:00+00', 'email'),
    (2, NULL, 'Unknown Caller', '+62-811-0000-2233', 'General question about firm services', 5, 'normal', 'open', 212, '2026-07-20 17:00:00+00', 'phone'),
    (3, 6, NULL, NULL, 'Portal login not working', 4, 'high', 'in_progress', 210, '2026-07-18 12:00:00+00', 'web_form');
SELECT setval('"CRM".hd_tickets_id_seq', 3);

UPDATE "CRM".hd_tickets SET closed_at = '2026-07-09 14:00:00+00' WHERE id = 1;

INSERT INTO "CRM".hd_ticket_messages
    (ticket_id, direction, body, sender_partner_id, sender_user_id, sender_free_text, sent_at) VALUES
    (1, 'inbound',  'Can you confirm when this month''s invoice is due?', 9,    NULL, NULL, '2026-07-08 09:00:00+00'),
    (1, 'outbound', 'Confirmed — due on the 15th, sent to your email on file.', NULL, 210, NULL, '2026-07-08 09:45:00+00'),
    (2, 'inbound',  'Caller asked general questions about corporate services offered.', NULL, NULL, 'Unknown Caller', '2026-07-14 11:00:00+00'),
    (2, 'internal_note', 'Following up by email once caller provides an address.', NULL, 212, NULL, '2026-07-14 11:05:00+00'),
    (3, 'inbound',  'Getting an error when trying to log into the client portal.', 6, NULL, NULL, '2026-07-17 08:30:00+00'),
    (3, 'outbound', 'Password reset link sent, please confirm once resolved.',      NULL, 210, NULL, '2026-07-17 09:10:00+00');

-- =====================================================================
-- PARTNER MERGE LOG — duplicate contact (id 11) merged into Amanda Wong (id 9)
-- =====================================================================

INSERT INTO "CRM".partner_merge_log
    (merged_from_partner_id, merged_into_partner_id, merged_by, merged_at, field_conflicts) VALUES
    (11, 9, 212, '2026-07-12 10:00:00+00',
     '{"name": {"kept": "Amanda Wong", "discarded": "A. Wong"}, "reason": "duplicate manual entry from a Helpdesk intake form"}'::jsonb);

-- =====================================================================
-- End of crm_dummy_data.sql
-- =====================================================================
