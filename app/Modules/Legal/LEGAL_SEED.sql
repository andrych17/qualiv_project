-- =====================================================================
-- LEGAL Module — Sample / Seed Data
-- Demonstrates: a full property-purchase matter (due diligence -> tax ->
-- AJB signing -> BPN registration -> field visit), a company incorporation
-- deed, and a will with DPW registration.
--
-- Explicit PKs are used throughout for cross-reference predictability,
-- matching the convention used in CRM/DMS/WNE/Schedule seed data.
--
-- Depends on: LEGAL_SCHEMA.sql, plus CRM.partners / CRM.leads and
-- SCHEDULE.sched_items already existing (per CRM_SPECS.md / SCHEDULE_SPECS.md).
-- This script also seeds minimal CRM.partners rows for standalone testing;
-- remove that block if seeding into a tenant DB where CRM already has data.
--
-- Run with: psql -v ON_ERROR_STOP=1 -f LEGAL_SEED.sql
-- =====================================================================

BEGIN;

SET search_path TO "LEGAL", public;

-- ---------------------------------------------------------------------
-- 0. Minimal CRM partner fixtures (skip/adjust if CRM already has data)
-- ---------------------------------------------------------------------
INSERT INTO "CRM".partners (id, type, name, is_active) VALUES
    (101, 'individual',   'Budi Santoso',            true),  -- seller
    (102, 'individual',   'Siti Aminah',              true),  -- buyer
    (103, 'organization',  'PT Sejahtera Abadi',       true),  -- company being incorporated
    (104, 'individual',   'Hendra Wijaya',            true),  -- founder / testator
    (105, 'individual',   'Dewi Lestari',              true)   -- witness / ahli waris
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('"CRM".partners', 'id'), 200, false);

INSERT INTO "SCHEDULE".sched_items (id, type, title) VALUES
    (301, 'event', 'Site visit — Jl. Merdeka No. 12 land parcel'),
    (302, 'event', 'AJB signing appointment — Budi/Siti')
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('"SCHEDULE".sched_items', 'id'), 400, false);

-- ---------------------------------------------------------------------
-- 1. Lookups
-- ---------------------------------------------------------------------
INSERT INTO deed_types (id, code, name, category, requires_tax, requires_bpn_registration, default_protocol_book_type) VALUES
    (1,  'akta_perjanjian',        'Akta Perjanjian',                         'notary', false, false, 'repertorium'),
    (2,  'akta_kuasa',             'Akta Kuasa',                              'notary', false, false, 'repertorium'),
    (3,  'akta_pendirian_pt',      'Akta Pendirian Perseroan Terbatas',       'notary', false, false, 'repertorium'),
    (4,  'wasiat',                 'Akta Wasiat',                             'notary', false, false, 'daftar_wasiat'),
    (5,  'legalisasi',             'Legalisasi',                              'notary', false, false, 'legalisasi'),
    (6,  'waarmerking',            'Waarmerking',                             'notary', false, false, 'waarmerking'),
    (7,  'ajb',                    'Akta Jual Beli',                          'ppat',   true,  true,  'repertorium'),
    (8,  'hibah',                  'Akta Hibah',                              'ppat',   true,  true,  'repertorium'),
    (9,  'tukar_menukar',          'Akta Tukar Menukar',                      'ppat',   true,  true,  'repertorium'),
    (10, 'pemasukan_perusahaan',   'Akta Pemasukan ke dalam Perusahaan',      'ppat',   true,  true,  'repertorium'),
    (11, 'pembagian_hak_bersama',  'Akta Pembagian Hak Bersama',              'ppat',   true,  true,  'repertorium'),
    (12, 'apht',                   'Akta Pemberian Hak Tanggungan',           'ppat',   false, true,  'repertorium'),
    (13, 'pemberian_hgb_hak_pakai','Akta Pemberian HGB/Hak Pakai atas Tanah', 'ppat',   true,  true,  'repertorium'),
    (14, 'pelepasan_hak',          'Akta Pelepasan Hak',                      'ppat',   false, true,  'repertorium')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('deed_types', 'id'), 100, false);

INSERT INTO party_role_types (id, code, name) VALUES
    (1, 'penghadap',      'Penghadap'),
    (2, 'pihak_pertama',  'Pihak Pertama'),
    (3, 'pihak_kedua',    'Pihak Kedua'),
    (4, 'saksi',          'Saksi'),
    (5, 'kuasa',          'Penerima Kuasa'),
    (6, 'ahli_waris',     'Ahli Waris'),
    (7, 'penjual',        'Penjual'),
    (8, 'pembeli',        'Pembeli'),
    (9, 'testator',       'Pewasiat'),
    (10,'pendiri',        'Pendiri Perusahaan')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('party_role_types', 'id'), 100, false);

INSERT INTO field_visit_types (id, code, name, default_checklist) VALUES
    (1, 'site_survey',      'Site Survey',
        '["Confirm parcel boundaries match certificate", "Photograph all four corners", "Check for occupants/disputes on site"]'),
    (2, 'bpn_office_visit', 'BPN Office Visit',
        '["Submit registration documents", "Collect tracking receipt", "Verify PNBP payment recorded"]'),
    (3, 'document_pickup',  'Document Pickup',
        '["Verify document matches expected type", "Photograph document before transport", "Confirm recipient signature"]'),
    (4, 'signing_witness',  'Signing Witness',
        '["Verify identity of all signing parties", "Confirm tax clearance status", "Witness signature"]')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('field_visit_types', 'id'), 100, false);

-- ---------------------------------------------------------------------
-- 2. Notary Protocol books (year 2026, volume 1, notary user id 1)
-- ---------------------------------------------------------------------
INSERT INTO protocol_books (id, book_type, year, volume, notary_id, status, opened_at) VALUES
    (1, 'repertorium',   2026, 1, 1, 'active', '2026-01-02'),
    (2, 'legalisasi',    2026, 1, 1, 'active', '2026-01-02'),
    (3, 'waarmerking',   2026, 1, 1, 'active', '2026-01-02'),
    (4, 'daftar_wasiat', 2026, 1, 1, 'active', '2026-01-02')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('protocol_books', 'id'), 100, false);

-- ---------------------------------------------------------------------
-- 3. Land object — the parcel in the AJB scenario
-- ---------------------------------------------------------------------
INSERT INTO land_objects (id, certificate_type, certificate_number, nib, address, area_m2, njop_reference, current_owner_id, status) VALUES
    (1, 'SHM', 'SHM-3201-004521', 'NIB-32.01.05.10.4.00521',
        'Jl. Merdeka No. 12, Kel. Sukamaju, Kec. Bogor Tengah, Kota Bogor',
        250.00, 950000000.00, 101, 'in_transaction')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('land_objects', 'id'), 100, false);

-- ---------------------------------------------------------------------
-- 4. Matters
-- ---------------------------------------------------------------------
INSERT INTO matters (id, title, matter_type, partner_id, assigned_to, status, opened_at, target_close_at) VALUES
    (1, 'Jual Beli Tanah — Jl. Merdeka No. 12', 'Property Purchase',    102, 1, 'in_progress', '2026-06-01', '2026-08-15'),
    (2, 'Pendirian PT Sejahtera Abadi',          'Company Incorporation',103, 1, 'in_progress', '2026-06-10', '2026-07-31'),
    (3, 'Perencanaan Wasiat — Hendra Wijaya',    'Estate Planning',      104, 1, 'open',        '2026-07-01', NULL)
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('matters', 'id'), 100, false);

-- ---------------------------------------------------------------------
-- 5. Due diligence checks on the land object (matter 1)
-- ---------------------------------------------------------------------
INSERT INTO due_diligence_checks (id, land_object_id, check_type, status, checked_by, checked_at, result_notes) VALUES
    (1, 1, 'sertifikat_validity', 'clear',   1, '2026-06-05 09:00+07', 'SKPT confirms certificate valid, no encumbrance flag at Kantor Pertanahan Kota Bogor.'),
    (2, 1, 'pbb_payment_status',  'clear',   1, '2026-06-05 09:30+07', 'PBB 2026 paid in full, no arrears.'),
    (3, 1, 'blokir_sengketa',     'clear',   1, '2026-06-06 10:00+07', 'No blokir or sengketa recorded.'),
    (4, 1, 'zona_nilai_tanah',    'clear',   1, '2026-06-06 10:15+07', 'ZNT reference consistent with NJOP on file.')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('due_diligence_checks', 'id'), 100, false);

-- ---------------------------------------------------------------------
-- 6. Deeds
-- ---------------------------------------------------------------------

-- 6a. AJB (matter 1) — starts as draft, will move through the tax gate below
INSERT INTO deeds (id, matter_id, deed_type_id, category, land_object_id, deed_number, status, signing_date, minuta_reference, transaction_value, summary) VALUES
    (1, 1, 7, 'ppat', 1, NULL, 'ready_for_signing', NULL, NULL, 1000000000.00,
        'Jual beli SHM-3201-004521 antara Budi Santoso (penjual) dan Siti Aminah (pembeli).')
ON CONFLICT (id) DO NOTHING;

-- 6b. Akta Pendirian PT (matter 2) — already signed, demonstrates protocol numbering + immutability
INSERT INTO deeds (id, matter_id, deed_type_id, category, deed_number, status, signing_date, minuta_reference, summary) VALUES
    (2, 2, 3, 'notary', 'Rep-2026-0001-Bogor', 'signed', '2026-07-05', 'Minuta/2026/07/PT-SA-001',
        'Akta Pendirian PT Sejahtera Abadi, modal dasar Rp 1.000.000.000.')
ON CONFLICT (id) DO NOTHING;

-- 6c. Wasiat (matter 3) — signed, DPW registration tracked separately
INSERT INTO deeds (id, matter_id, deed_type_id, category, deed_number, status, signing_date, minuta_reference, summary) VALUES
    (3, 3, 4, 'notary', 'Wasiat-2026-0001', 'signed', '2026-07-10', 'Minuta/2026/07/WSY-001',
        'Akta wasiat Hendra Wijaya, ahli waris tunggal Dewi Lestari.')
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('deeds', 'id'), 100, false);

-- Protocol entries for the two already-signed deeds (sequence auto-assigned)
INSERT INTO protocol_entries (book_id, deed_id, entry_date) VALUES
    (1, 2, '2026-07-05'),   -- repertorium, PT incorporation
    (4, 3, '2026-07-10');   -- daftar wasiat

-- ---------------------------------------------------------------------
-- 7. Deed parties (identity snapshots as of signing/drafting)
-- ---------------------------------------------------------------------
INSERT INTO deed_parties (deed_id, partner_id, role_type_id, identity_snapshot) VALUES
    (1, 101, 7, '{"name": "Budi Santoso", "nik": "3201010101800001", "address": "Jl. Melati No. 5, Bogor"}'),
    (1, 102, 8, '{"name": "Siti Aminah",  "nik": "3201010101850002", "address": "Jl. Kenanga No. 8, Bogor"}'),
    (2, 103, 1, '{"name": "PT Sejahtera Abadi (dalam pendirian)", "npwp": "01.234.567.8-411.000"}'),
    (2, 104, 10,'{"name": "Hendra Wijaya", "nik": "3201010101750003", "address": "Jl. Anggrek No. 3, Bogor"}'),
    (3, 104, 9, '{"name": "Hendra Wijaya", "nik": "3201010101750003", "address": "Jl. Anggrek No. 3, Bogor"}'),
    (3, 105, 6, '{"name": "Dewi Lestari",  "nik": "3201010101900004", "address": "Jl. Anggrek No. 3, Bogor"}');

-- ---------------------------------------------------------------------
-- 8. Will — DPW registration record for deed 3
-- ---------------------------------------------------------------------
INSERT INTO wills (id, deed_id, testator_id, dpw_reg_number, dpw_registered_at, status) VALUES
    (1, 3, 104, 'DPW-2026-JB-000117', '2026-07-11', 'dpw_registered')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('wills', 'id'), 100, false);

-- ---------------------------------------------------------------------
-- 9. Deed taxes (AJB, deed 1) — both cleared, satisfying the signing gate
-- ---------------------------------------------------------------------
INSERT INTO deed_taxes (id, deed_id, tax_type, taxpayer_partner_id, transaction_amount, njop_amount, base_amount, rate_percent, npoptkp_applied, computed_amount, billing_code, ntpn, status, paid_at, validated_at) VALUES
    (1, 1, 'pph_final', 101, 1000000000.00, 950000000.00, 1000000000.00, 2.500, 0.00,
        25000000.00, 'BC-2026-411128402-00981', 'NTPN-2026070500123', 'validated', '2026-07-03', '2026-07-04'),
    (2, 1, 'bphtb',     102, 1000000000.00, 950000000.00, 1000000000.00, 5.000, 80000000.00,
        46000000.00, 'SSPD-BGR-2026-005512',    'NTPN-2026070500456', 'validated', '2026-07-03', '2026-07-04')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('deed_taxes', 'id'), 100, false);

-- Now that tax is validated and due diligence is clear, sign the AJB and
-- assign its protocol number (mirrors what the app's DeedService would do).
UPDATE deeds SET status = 'signed', signing_date = '2026-07-15', deed_number = 'Rep-2026-0002-Bogor'
    WHERE id = 1;
INSERT INTO protocol_entries (book_id, deed_id, entry_date) VALUES (1, 1, '2026-07-15');

-- ---------------------------------------------------------------------
-- 10. BPN submission for the now-signed AJB
-- ---------------------------------------------------------------------
INSERT INTO bpn_submissions (id, deed_id, submission_type, submitted_at, tracking_number, pnbp_amount, status) VALUES
    (1, 1, 'balik_nama', '2026-07-16', 'BPN-BGR-2026-0044219', 1050000.00, 'in_process')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('bpn_submissions', 'id'), 100, false);

-- ---------------------------------------------------------------------
-- 11. Field visits
-- ---------------------------------------------------------------------
INSERT INTO field_visits (id, matter_id, land_object_id, deed_id, visit_type_id, assigned_to, schedule_item_id, status, checked_in_at, gps_lat, gps_lng, checklist_result, notes) VALUES
    (1, 1, 1, NULL, 1, 2, 301, 'completed', '2026-06-05 08:45+07', -6.5971000, 106.8060000,
        '[{"item": "Confirm parcel boundaries match certificate", "done": true},
          {"item": "Photograph all four corners", "done": true},
          {"item": "Check for occupants/disputes on site", "done": true}]',
        'Site clear, boundaries confirmed against SHM-3201-004521, photos uploaded to DMS.'),
    (2, 1, NULL, 1, 2, 3, NULL, 'scheduled', NULL, NULL, NULL, '[]',
        'Follow-up visit to Kantor Pertanahan Kota Bogor to submit the balik nama registration package.')
ON CONFLICT (id) DO NOTHING;

COMMIT;
