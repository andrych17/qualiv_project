-- =====================================================================
-- DMS — Dummy / Seed Data
-- Scenario: tenant 1 "Acme Corp" using DMS both (a) standalone as a
--   general document library and (b) attached to other modules —
--   a Legal case file, and the same Purchase Order (id 142) that
--   already exists in wne_dummy_data.sql's workflow scenario, to show
--   a document riding along with an approval flow.
--   Tenant 2 "Brightleaf Studio" gets one document to demonstrate
--   tenant isolation.
--
-- Assumes dms_schema.sql has already run. All rows use EXPLICIT ids so
-- foreign keys are predictable — safe to run once against a freshly
-- migrated schema.
-- =====================================================================

SET search_path TO "DMS";

-- =====================================================================
-- MASTER / CONFIG DATA (tenant-defined)
-- =====================================================================

INSERT INTO "DMS".doc_types (id, tenant_id, code, name, description, is_active) VALUES
    (1, 1, 'contract',       'Contract',             'Signed contracts and agreements',            TRUE),
    (2, 1, 'court_filing',   'Court Filing',          'Filings submitted to or received from court', TRUE),
    (3, 1, 'correspondence', 'Correspondence',        'Letters and client/counterparty emails',      TRUE),
    (4, 1, 'po_attachment',  'PO Attachment',         'Quotes/invoices attached to a purchase order', TRUE),
    (5, 1, 'general',        'General Document',      'Uncategorized standalone document',           TRUE),
    (6, 2, 'contract',       'Contract',              'Signed contracts and agreements',             TRUE);
SELECT setval('"DMS".doc_types_id_seq', 6);

INSERT INTO "DMS".retention_policies (id, tenant_id, doc_type_id, name, retention_period_days, action_on_expiry, legal_hold_overridable, is_active) VALUES
    (1, 1, 1, 'Contracts — 7 year retention',        2555, 'archive',     TRUE,  TRUE),
    (2, 1, 2, 'Court filings — 10 year retention',   3650, 'notify_only', TRUE,  TRUE),
    (3, 1, 4, 'PO attachments — 5 year retention',   1825, 'archive',     TRUE,  TRUE),
    (4, 1, NULL, 'General default — 3 year retention', 1095, 'notify_only', TRUE, TRUE),
    (5, 2, 6, 'Contracts — 7 year retention',        2555, 'archive',     TRUE,  TRUE);
SELECT setval('"DMS".retention_policies_id_seq', 5);

-- back-fill doc_types.default_retention_policy_id now that policies exist
UPDATE "DMS".doc_types SET default_retention_policy_id = 1 WHERE id = 1;
UPDATE "DMS".doc_types SET default_retention_policy_id = 2 WHERE id = 2;
UPDATE "DMS".doc_types SET default_retention_policy_id = 3 WHERE id = 4;
UPDATE "DMS".doc_types SET default_retention_policy_id = 4 WHERE id = 5;
UPDATE "DMS".doc_types SET default_retention_policy_id = 5 WHERE id = 6;

INSERT INTO "DMS".folders (id, tenant_id, parent_id, name, access_level, default_doc_type_id, default_retention_policy_id, created_by) VALUES
    (1, 1, NULL, 'Legal Cases',   'team',   2, 2, 501),
    (2, 1, NULL, 'Purchasing',    'team',   4, 3, 210),
    (3, 1, NULL, 'General',       'tenant', 5, 4, 501),
    (4, 1, 1,    'Case #2026-041 — Hartono v. Meridian', 'private', 1, 1, 501),
    (5, 2, NULL, 'Contracts',     'team',   6, 5, 601);
SELECT setval('"DMS".folders_id_seq', 5);

INSERT INTO "DMS".tags (id, tenant_id, code, name) VALUES
    (1, 1, 'confidential', 'Confidential'),
    (2, 1, 'urgent',       'Urgent'),
    (3, 1, 'signed',       'Signed'),
    (4, 1, 'litigation',   'Litigation'),
    (5, 2, 'confidential', 'Confidential');
SELECT setval('"DMS".tags_id_seq', 5);

-- =====================================================================
-- DOCUMENT DOMAIN
-- =====================================================================

-- (1) Legal case contract — embedded in a Legal case, under legal hold
--     because the case is in active litigation.
-- (2) PO attachment — rides along with wrkflow_instances.id = 2 /
--     subject_id 143 from wne_dummy_data.sql (the same PO awaiting
--     Finance review), proving DMS + WNE compose cleanly.
-- (3) Standalone general document — no owning module, library-only use.
-- (4) Tenant 2 contract — isolation check.
INSERT INTO "DMS".documents (id, tenant_id, folder_id, doc_type_id, subject_type, subject_id, title, description, status, legal_hold, retention_policy_id, effective_date, expiry_date, search_text, created_by, updated_by) VALUES
    (1, 1, 4, 1, 'App\Modules\Legal\Models\LegalCase', 2041,
        'Settlement Agreement — Hartono v. Meridian',
        'Draft settlement agreement pending client signature.',
        'active', TRUE, 1, '2026-07-01', NULL,
        'settlement agreement hartono meridian litigation', 501, 501),
    (2, 1, 2, 4, 'App\Modules\Purchasing\Models\PurchaseOrder', 143,
        'Vendor Quote — PO-2026-0143',
        'Vendor quote attached to PO 143, currently in Finance review.',
        'active', FALSE, 3, '2026-07-05', NULL,
        'vendor quote po-2026-0143 finance review', 210, 210),
    (3, 1, 3, 5, NULL, NULL,
        'Employee Handbook 2026',
        'Standalone reference document, not tied to any module record.',
        'active', FALSE, 4, '2026-01-15', NULL,
        'employee handbook 2026 policies', 501, 501),
    (4, 2, 5, 6, NULL, NULL,
        'Studio Lease Agreement — Brightleaf HQ',
        'Signed lease for Brightleaf Studio headquarters.',
        'active', FALSE, 5, '2026-03-01', NULL,
        'studio lease agreement brightleaf hq signed', 601, 601);
SELECT setval('"DMS".documents_id_seq', 4);

INSERT INTO "DMS".document_versions (id, document_id, version_no, storage_key, file_name, mime_type, size_bytes, checksum_sha256, upload_note, uploaded_by) VALUES
    (1, 1, 1, 'tenant_001/DMS/Legal/2026/07/doc-0001/v1.docx', 'settlement_agreement_draft_v1.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 48210,
        'a1b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff', 'Initial draft from counsel.', 501),
    (2, 1, 2, 'tenant_001/DMS/Legal/2026/07/doc-0001/v2.docx', 'settlement_agreement_draft_v2.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 49875,
        'b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff01', 'Revised after opposing counsel comments.', 501),
    (3, 2, 1, 'tenant_001/DMS/Purchasing/2026/07/doc-0002/v1.pdf', 'vendor_quote_po143.pdf',
        'application/pdf', 122048,
        'c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff0102', 'Original quote from vendor.', 210),
    (4, 3, 1, 'tenant_001/DMS/General/2026/01/doc-0003/v1.pdf', 'employee_handbook_2026.pdf',
        'application/pdf', 890234,
        'd4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff010203', 'Annual handbook refresh.', 501),
    (5, 4, 1, 'tenant_002/DMS/Contracts/2026/03/doc-0004/v1.pdf', 'brightleaf_hq_lease_signed.pdf',
        'application/pdf', 305112,
        'e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff01020304', 'Countersigned copy.', 601);
SELECT setval('"DMS".document_versions_id_seq', 5);

UPDATE "DMS".documents SET current_version_id = 2 WHERE id = 1;
UPDATE "DMS".documents SET current_version_id = 3 WHERE id = 2;
UPDATE "DMS".documents SET current_version_id = 4 WHERE id = 3;
UPDATE "DMS".documents SET current_version_id = 5 WHERE id = 4;

-- Relation is between two distinct documents (self-relations are blocked
-- by the CHECK constraint on purpose). Document 3 (handbook) referenced
-- as background reading related to the settlement case file.
INSERT INTO "DMS".document_relations (id, source_document_id, target_document_id, relation_type, created_by) VALUES
    (1, 3, 1, 'related_to', 501);
SELECT setval('"DMS".document_relations_id_seq', 1);

INSERT INTO "DMS".document_tags (id, document_id, tag_id) VALUES
    (1, 1, 1),  -- settlement agreement: confidential
    (2, 1, 4),  -- settlement agreement: litigation
    (3, 1, 2),  -- settlement agreement: urgent
    (4, 2, 3),  -- vendor quote: signed (vendor-signed quote)
    (5, 4, 1);  -- lease: confidential
SELECT setval('"DMS".document_tags_id_seq', 5);

-- =====================================================================
-- AUDIT TRAIL
-- =====================================================================

INSERT INTO "DMS".access_logs (id, tenant_id, document_id, document_version_id, actor_id, action, ip_address, metadata) VALUES
    (1, 1, 1, 1, 501, 'upload',          '10.0.4.12', '{"source": "web"}'),
    (2, 1, 1, 1, 502, 'view',            '10.0.4.19', '{}'),
    (3, 1, 1, 2, 501, 'version_upload',  '10.0.4.12', '{"note": "revised after opposing counsel comments"}'),
    (4, 1, 1, 2, 501, 'hold_applied',    '10.0.4.12', '{"reason": "active litigation"}'),
    (5, 1, 2, 3, 210, 'upload',          '10.0.2.44', '{"source": "purchasing.po", "subject_id": 143}'),
    (6, 1, 2, 3, 205, 'download',        '10.0.2.51', '{}'),
    (7, 1, 3, 4, 501, 'upload',          '10.0.4.12', '{}'),
    (8, 2, 4, 5, 601, 'upload',          '10.0.9.03', '{}');
SELECT setval('"DMS".access_logs_id_seq', 8);
