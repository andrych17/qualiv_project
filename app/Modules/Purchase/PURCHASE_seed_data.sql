-- =====================================================================
-- PURCHASE MODULE — SAMPLE / SEED DATA SCRIPT
-- Demonstrates the full Requisition -> RFQ -> PO -> Receipt -> Invoice
-- -> Match flow, plus catalog, contracts, exceptions, and budgets.
--
-- Explicit primary key IDs are used throughout for referential
-- predictability and cross-module composition (same convention as
-- WNE/DMS/CRM/Schedule seed scripts). ID ranges below are chosen to
-- avoid collision with those modules' own seed data:
--   public.users            700-709
--   "CRM".partners          700-709 (vendors) / 710 (internal buyer's firm, unused)
--   "DMS".documents         700-719
--   "PURCHASE".* tables     start at 1 (own schema, own sequence space)
--
-- Run AFTER purchase_schema.sql. Requires the prerequisite stub tables
-- (public.users, "CRM".partners/partner_role_types/partner_roles,
-- "DMS".documents) to exist — either from the stub section of the
-- schema script, or from the real CRM/DMS module seed data if this is
-- run inside a fuller tenant DB.
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- 0. Prerequisite reference data (users, CRM vendor partners, DMS docs)
-- ---------------------------------------------------------------------

INSERT INTO public.users (id, name, email) VALUES
    (700, 'Amelia Santoso',  'amelia.santoso@firm.example'),   -- requester / paralegal
    (701, 'Budi Prakoso',    'budi.prakoso@firm.example'),     -- buyer / procurement lead
    (702, 'Citra Wulandari', 'citra.wulandari@firm.example'),  -- approver / office manager
    (703, 'Dimas Hartono',   'dimas.hartono@firm.example')     -- warehouse/receiving clerk
ON CONFLICT (id) DO NOTHING;
SELECT setval('public.users_id_seq', GREATEST((SELECT MAX(id) FROM public.users), 1));

INSERT INTO "CRM".partner_role_types (id, code, label) VALUES
    (1, 'vendor', 'Vendor')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"CRM".partner_role_types_id_seq', GREATEST((SELECT MAX(id) FROM "CRM".partner_role_types), 1));

INSERT INTO "CRM".partners (id, type, name, is_active) VALUES
    (700, 'organization', 'Nusantara Office Supplies Pte',      true),
    (701, 'organization', 'Prima Legal Print & Binding Co.',    true),
    (702, 'organization', 'Cakrawala IT Solutions',             true),
    (703, 'organization', 'Sejahtera Facilities Maintenance',   true),
    (704, 'organization', 'Global Discovery Software Inc.',     true)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"CRM".partners_id_seq', GREATEST((SELECT MAX(id) FROM "CRM".partners), 1));

INSERT INTO "CRM".partner_roles (partner_id, role_type_id, assigned_by) VALUES
    (700, 1, 701), (701, 1, 701), (702, 1, 701), (703, 1, 701), (704, 1, 701)
ON CONFLICT DO NOTHING;

INSERT INTO "DMS".documents (id, title, owning_module) VALUES
    (700, 'PO-2026-0001.pdf',                         'Purchase'),
    (701, 'PO-2026-0002.pdf',                         'Purchase'),
    (702, 'Nusantara Office Supplies - COI 2026.pdf',  'Purchase'),
    (703, 'Cakrawala IT - Master Services Agreement.pdf', 'Purchase'),
    (704, 'INV-NOS-88213.pdf',                        'Purchase'),
    (705, 'INV-CIT-4471.pdf',                         'Purchase'),
    (706, 'GR-0002 delivery photo.jpg',                'Purchase')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"DMS".documents_id_seq', GREATEST((SELECT MAX(id) FROM "DMS".documents), 1));

-- ---------------------------------------------------------------------
-- 1. Lookups: categories, cost centers
-- ---------------------------------------------------------------------

INSERT INTO "PURCHASE".categories (id, code, name, spend_type, capex_opex) VALUES
    (1, 'OFFICE_SUPPLIES', 'Office Supplies',        'indirect', 'opex'),
    (2, 'PRINT_BINDING',   'Printing & Binding',     'direct',   'opex'),
    (3, 'IT_SOFTWARE',     'IT & Software',          'indirect', 'opex'),
    (4, 'FACILITIES',      'Facilities Maintenance', 'indirect', 'opex'),
    (5, 'IT_HARDWARE',     'IT Hardware',            'indirect', 'capex')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".categories_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".categories), 1));

INSERT INTO "PURCHASE".cost_centers (id, code, name) VALUES
    (1, 'CC-ADMIN',   'Administration'),
    (2, 'CC-LITIG',   'Litigation Practice Group'),
    (3, 'CC-IT',      'IT & Systems')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".cost_centers_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".cost_centers), 1));

-- ---------------------------------------------------------------------
-- 2. Vendor profiles (extends CRM partners 700-704)
-- ---------------------------------------------------------------------

INSERT INTO "PURCHASE".vendor_profiles
    (id, partner_id, payment_terms, incoterms, preferred_currency, tax_registration_no,
     preferred_status, onboarding_status) VALUES
    (1, 700, 'Net 30', 'DAP', 'IDR', 'NPWP-700-NOS', true,  'active'),
    (2, 701, 'Net 14', 'DAP', 'IDR', 'NPWP-701-PLB', true,  'active'),
    (3, 702, 'Net 30', NULL,  'USD', 'NPWP-702-CIT', false, 'active'),
    (4, 703, 'Net 30', 'DAP', 'IDR', 'NPWP-703-SFM', false, 'active'),
    (5, 704, 'Net 45', NULL,  'USD', 'EIN-704-GDS',  false, 'pending')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".vendor_profiles_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".vendor_profiles), 1));

INSERT INTO "PURCHASE".pur_vendor_documents
    (id, vendor_profile_id, doc_type, document_id, expiry_date, status) VALUES
    (1, 1, 'insurance_coi',    702, '2026-12-31', 'valid'),
    (2, 3, 'msa_contract',     703, '2027-06-30', 'valid'),
    (3, 4, 'business_license', NULL, '2026-08-15', 'expiring_soon')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_vendor_documents_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_vendor_documents), 1));

-- ---------------------------------------------------------------------
-- 3. Catalog items
-- ---------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_catalog_items
    (id, item_code, description, category_id, unit_of_measure, preferred_partner_id,
     negotiated_price, currency, price_valid_from, price_valid_to, source) VALUES
    (1, 'OS-A4-PAPER-80G',  'A4 Copy Paper 80gsm, 5-ream box', 1, 'BOX', 700, 185000, 'IDR', '2026-01-01', '2026-12-31', 'manual'),
    (2, 'OS-TONER-HP26A',   'HP 26A Compatible Toner Cartridge', 1, 'EA', 700, 420000, 'IDR', '2026-01-01', '2026-12-31', 'manual'),
    (3, 'PB-CASEBIND-STD',  'Standard Case Binding, per volume', 2, 'EA', 701, 65000, 'IDR', '2026-01-01', '2026-12-31', 'rfx_award'),
    (4, 'IT-SAAS-DISCOVERY','Global Discovery SaaS seat, monthly', 3, 'EA', 704, 45, 'USD', '2026-01-01', '2026-12-31', 'manual')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_catalog_items_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_catalog_items), 1));

-- ---------------------------------------------------------------------
-- 4. Budgets (soft check, MVP)
-- ---------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_budgets
    (id, cost_center_id, category_id, period_start, period_end, budget_amount, currency, consumed_amount) VALUES
    (1, 1, 1, '2026-01-01', '2026-12-31', 30000000, 'IDR', 4250000),
    (2, 2, 2, '2026-01-01', '2026-12-31', 20000000, 'IDR', 6500000),
    (3, 3, 3, '2026-01-01', '2026-12-31', 15000,    'USD', 12800)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_budgets_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_budgets), 1));

-- ---------------------------------------------------------------------
-- 5. Requisition 1 (routine, catalog-based, no RFQ) -> PO 1
-- ---------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_requisition_hdrs
    (id, requester_id, cost_center_id, needed_by, status, wne_workflow_instance_id) VALUES
    (1, 700, 1, '2026-08-01', 'converted', 2)  -- references WNE workflow_instance 2, mirrors DMS's cross-module seed convention
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_requisition_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_requisition_hdrs), 1));

INSERT INTO "PURCHASE".pur_requisition_lines
    (id, requisition_id, line_no, catalog_item_id, description, category_id, quantity, estimated_unit_price) VALUES
    (1, 1, 1, 1, 'A4 Copy Paper 80gsm, 5-ream box', 1, 10, 185000),
    (2, 1, 2, 2, 'HP 26A Compatible Toner Cartridge', 1, 4, 420000)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_requisition_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_requisition_lines), 1));

INSERT INTO "PURCHASE".pur_order_hdrs
    (id, po_number, supplier_partner_id, requisition_id, currency, payment_terms, status,
     wne_workflow_instance_id, revision_no, document_id, created_by) VALUES
    (1, 'PO-2026-0001', 700, 1, 'IDR', 'Net 30', 'received', 3, 0, 700, 701)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_order_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_order_hdrs), 1));

INSERT INTO "PURCHASE".pur_order_lines
    (id, order_id, line_no, catalog_item_id, description, quantity, unit_price, expected_delivery_date, category_id) VALUES
    (1, 1, 1, 1, 'A4 Copy Paper 80gsm, 5-ream box', 10, 185000, '2026-07-25', 1),
    (2, 1, 2, 2, 'HP 26A Compatible Toner Cartridge', 4, 420000, '2026-07-25', 1)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_order_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_order_lines), 1));

-- Fully received, on time
INSERT INTO "PURCHASE".pur_receipt_hdrs (id, order_id, receiver_id, received_at, notes) VALUES
    (1, 1, 703, '2026-07-24 10:15:00+07', 'Delivered complete, no damage.')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_receipt_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_receipt_hdrs), 1));

INSERT INTO "PURCHASE".pur_receipt_lines (id, receipt_id, order_line_id, quantity_received) VALUES
    (1, 1, 1, 10),
    (2, 1, 2, 4)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_receipt_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_receipt_lines), 1));

INSERT INTO "PURCHASE".pur_invoice_hdrs
    (id, order_id, supplier_invoice_no, supplier_invoice_date, currency, amount, document_id,
     submission_channel, status, wne_workflow_instance_id) VALUES
    (1, 1, 'INV-NOS-88213', '2026-07-25', 'IDR', 3530000, 704, 'supplier_link', 'approved_for_payment', 4)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_invoice_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_invoice_hdrs), 1));

INSERT INTO "PURCHASE".pur_invoice_lines (id, invoice_id, order_line_id, quantity, unit_price) VALUES
    (1, 1, 1, 10, 185000),
    (2, 1, 2, 4, 420000)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_invoice_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_invoice_lines), 1));

INSERT INTO "PURCHASE".pur_invoice_matches
    (id, invoice_id, order_line_id, receipt_line_id, qty_variance, price_variance, within_tolerance) VALUES
    (1, 1, 1, 1, 0, 0, true),
    (2, 1, 2, 2, 0, 0, true)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_invoice_matches_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_invoice_matches), 1));

-- ---------------------------------------------------------------------
-- 6. Requisition 2 -> RFQ -> awarded PO 2 (sourcing flow, with a
--    price-variance exception on receipt/invoice for demo purposes)
-- ---------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_requisition_hdrs
    (id, requester_id, cost_center_id, needed_by, status) VALUES
    (2, 700, 2, '2026-08-15', 'converted')
ON CONFLICT (id) DO NOTHING;

INSERT INTO "PURCHASE".pur_requisition_lines
    (id, requisition_id, line_no, description, category_id, quantity, estimated_unit_price) VALUES
    (3, 2, 1, 'Case binding for trial exhibit volumes (approx. 40 volumes)', 2, 40, 65000)
ON CONFLICT (id) DO NOTHING;

INSERT INTO "PURCHASE".pur_rfx_hdrs (id, requisition_id, rfx_type, title, due_at, status, buyer_id) VALUES
    (1, 2, 'rfq', 'RFQ - Trial Exhibit Binding Aug 2026', '2026-07-20 17:00:00+07', 'awarded', 701)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_rfx_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_rfx_hdrs), 1));

INSERT INTO "PURCHASE".pur_rfx_lines (id, rfx_id, line_no, description, quantity) VALUES
    (1, 1, 1, 'Standard case binding, per volume', 40)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_rfx_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_rfx_lines), 1));

INSERT INTO "PURCHASE".pur_rfx_invitations (id, rfx_id, partner_id, invited_at, responded_at) VALUES
    (1, 1, 701, '2026-07-14 09:00:00+07', '2026-07-16 14:20:00+07'),  -- Prima Legal Print (won)
    (2, 1, 700, '2026-07-14 09:00:00+07', NULL)                        -- Nusantara Office Supplies (no response)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_rfx_invitations_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_rfx_invitations), 1));

INSERT INTO "PURCHASE".pur_rfx_responses (id, rfx_invitation_id, submitted_at, notes) VALUES
    (1, 1, '2026-07-16 14:20:00+07', 'Can turn around in 3 business days for volumes over 20.')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_rfx_responses_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_rfx_responses), 1));

INSERT INTO "PURCHASE".pur_rfx_response_lines (id, rfx_response_id, rfx_line_id, unit_price, lead_time_days) VALUES
    (1, 1, 1, 65000, 3)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_rfx_response_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_rfx_response_lines), 1));

INSERT INTO "PURCHASE".pur_order_hdrs
    (id, po_number, supplier_partner_id, rfx_id, requisition_id, currency, payment_terms, status,
     wne_workflow_instance_id, revision_no, document_id, created_by) VALUES
    (2, 'PO-2026-0002', 701, 1, 2, 'IDR', 'Net 14', 'partially_received', 5, 0, 701, 701)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_order_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_order_hdrs), 1));

INSERT INTO "PURCHASE".pur_order_lines
    (id, order_id, line_no, catalog_item_id, description, quantity, unit_price, expected_delivery_date, category_id) VALUES
    (3, 2, 1, 3, 'Standard case binding, per volume', 40, 65000, '2026-08-10', 2)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_order_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_order_lines), 1));

-- Partial, slightly late receipt -> feeds the late_delivery exception below
INSERT INTO "PURCHASE".pur_receipt_hdrs (id, order_id, receiver_id, received_at, notes) VALUES
    (2, 2, 703, '2026-08-13 16:40:00+07', 'First batch of 25 volumes received; remainder due next week.')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_receipt_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_receipt_hdrs), 1));

INSERT INTO "PURCHASE".pur_receipt_lines (id, receipt_id, order_line_id, quantity_received, photo_document_id) VALUES
    (3, 2, 3, 25, 706)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_receipt_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_receipt_lines), 1));

-- Invoice for the partial batch, priced above the awarded RFQ rate -> price variance
INSERT INTO "PURCHASE".pur_invoice_hdrs
    (id, order_id, supplier_invoice_no, supplier_invoice_date, currency, amount, document_id,
     submission_channel, status) VALUES
    (2, 2, 'INV-CIT-4471', '2026-08-14', 'IDR', 1750000, 705, 'manual', 'mismatched')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_invoice_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_invoice_hdrs), 1));

INSERT INTO "PURCHASE".pur_invoice_lines (id, invoice_id, order_line_id, quantity, unit_price) VALUES
    (3, 2, 3, 25, 70000)  -- invoiced at 70,000 vs. awarded 65,000/unit
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_invoice_lines_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_invoice_lines), 1));

INSERT INTO "PURCHASE".pur_invoice_matches
    (id, invoice_id, order_line_id, receipt_line_id, qty_variance, price_variance, within_tolerance) VALUES
    (3, 2, 3, 3, 0, 5000, false)
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_invoice_matches_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_invoice_matches), 1));

-- ---------------------------------------------------------------------
-- 7. Contract (Cakrawala IT — MSA, expiring soon to demo the reminder)
-- ---------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_contract_hdrs
    (id, supplier_partner_id, title, contract_type, contract_value, currency,
     start_date, end_date, auto_renewal, renewal_notice_days, document_id, status,
     owner_id, sched_item_id) VALUES
    (1, 702, 'Cakrawala IT Solutions - Master Services Agreement', 'framework',
     240000000, 'IDR', '2025-09-01', '2026-08-31', true, 90, 703, 'expiring_soon', 701, 14)
     -- sched_item_id references a SCHEDULE.sched_items row for the renewal reminder
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_contract_hdrs_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_contract_hdrs), 1));

-- ---------------------------------------------------------------------
-- 8. Exceptions (mirrors the mismatches/flags seeded above)
-- ---------------------------------------------------------------------

INSERT INTO "PURCHASE".pur_exceptions
    (id, exception_type, subject_type, subject_id, detected_at, status, notes) VALUES
    (1, 'unmatched_invoice', 'invoice', 2, '2026-08-14 09:05:00+07', 'open',
        'Invoiced unit price 70,000 exceeds awarded RFQ rate of 65,000 by more than 5% tolerance.'),
    (2, 'price_variance', 'invoice', 2, '2026-08-14 09:05:00+07', 'open',
        'Line 1: price variance of IDR 5,000/unit against PO-2026-0002.'),
    (3, 'late_delivery', 'order', 2, '2026-08-13 16:45:00+07', 'acknowledged',
        'Remaining 15 of 40 volumes still outstanding past first expected delivery window.')
ON CONFLICT (id) DO NOTHING;
SELECT setval('"PURCHASE".pur_exceptions_id_seq', GREATEST((SELECT MAX(id) FROM "PURCHASE".pur_exceptions), 1));

COMMIT;

-- =====================================================================
-- Sanity check counts
-- =====================================================================
SELECT 'vendor_profiles' AS table_name, COUNT(*) FROM "PURCHASE".vendor_profiles
UNION ALL SELECT 'pur_catalog_items', COUNT(*) FROM "PURCHASE".pur_catalog_items
UNION ALL SELECT 'pur_requisition_hdrs', COUNT(*) FROM "PURCHASE".pur_requisition_hdrs
UNION ALL SELECT 'pur_rfx_hdrs', COUNT(*) FROM "PURCHASE".pur_rfx_hdrs
UNION ALL SELECT 'pur_order_hdrs', COUNT(*) FROM "PURCHASE".pur_order_hdrs
UNION ALL SELECT 'pur_receipt_hdrs', COUNT(*) FROM "PURCHASE".pur_receipt_hdrs
UNION ALL SELECT 'pur_invoice_hdrs', COUNT(*) FROM "PURCHASE".pur_invoice_hdrs
UNION ALL SELECT 'pur_contract_hdrs', COUNT(*) FROM "PURCHASE".pur_contract_hdrs
UNION ALL SELECT 'pur_exceptions', COUNT(*) FROM "PURCHASE".pur_exceptions
UNION ALL SELECT 'pur_budgets', COUNT(*) FROM "PURCHASE".pur_budgets;
