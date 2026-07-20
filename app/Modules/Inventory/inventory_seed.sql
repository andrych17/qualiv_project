-- ============================================================================
-- INVENTORY MODULE — SAMPLE / SEED DATA SCRIPT
-- Demonstrates: multi-warehouse, FIFO cost-layer consumption across multiple
-- receipts, batch tracking, serial tracking, transfer between warehouses,
-- a cycle-count-driven adjustment, reservations, picking, and shipping.
--
-- Explicit primary keys are used throughout for predictable cross-references
-- (matching the convention used in WNE/DMS/CRM/SCHEDULE seed scripts).
-- Sequences are reset at the end so future application inserts continue
-- cleanly from the last explicit ID.
--
-- Cross-module references: vendor/customer partner IDs (501-503, 601-602)
-- and subject_id 4821 (Legal case) are informational placeholders assumed
-- to exist in CRM.partners / LEGAL.case_hdrs respectively, consistent with
-- the CRM_SPECS.md worked example (case 4821) — adjust to real IDs once
-- loaded against a tenant DB that also has CRM/Legal seeded.
--
-- Run after inventory_schema.sql, against the same tenant database.
-- Target: PostgreSQL 16
-- ============================================================================

SET search_path TO "INVENTORY";

-- ----------------------------------------------------------------------------
-- 1. UoMs & conversions
-- ----------------------------------------------------------------------------
INSERT INTO uoms (id, code, name) VALUES
  (1, 'EA',  'Each'),
  (2, 'BOX', 'Box');

INSERT INTO uom_conversions (id, from_uom_id, to_uom_id, factor) VALUES
  (1, 2, 1, 12); -- 1 BOX = 12 EA

-- ----------------------------------------------------------------------------
-- 2. Product categories
-- ----------------------------------------------------------------------------
INSERT INTO product_categories (id, parent_category_id, name) VALUES
  (1, NULL, 'Stationery'),
  (2, NULL, 'IT Equipment'),
  (3, NULL, 'Case Supplies'),
  (4, NULL, 'Consumables');

-- ----------------------------------------------------------------------------
-- 3. Products
-- ----------------------------------------------------------------------------
INSERT INTO products
  (id, sku, name, description, category_id, base_uom_id, costing_method, tracking_mode,
   reorder_point, reorder_quantity, abc_class, is_active) VALUES
  (1, 'SKU-1001', 'A4 Copy Paper (Box of 12)', 'Standard 80gsm copy paper, 12 reams per box',
      1, 2, 'fifo',    'none',   20, 50, 'B', TRUE),
  (2, 'SKU-1002', 'Toner Cartridge - HP26A', 'Black toner cartridge for HP LaserJet Pro',
      2, 1, 'fifo',    'serial', 5,  10, 'A', TRUE),
  (3, 'SKU-1003', 'Evidence Storage Box', 'Archival-grade banker box for case file storage',
      3, 1, 'average', 'batch',  50, 100,'C', TRUE),
  (4, 'SKU-1004', 'Laptop - Dell Latitude 5440', 'Standard-issue staff laptop',
      2, 1, 'fifo',    'serial', 2,  5,  'A', TRUE),
  (5, 'SKU-1005', 'Sanitizer Refill (5L)', 'Refill for lobby/office dispensers',
      4, 1, 'fifo',    'batch',  10, 30, 'C', TRUE);

INSERT INTO product_barcodes (id, product_id, barcode, barcode_type, unit_multiplier) VALUES
  (1, 1, '8991002100011', 'primary', 1),
  (2, 2, '8991002100028', 'primary', 1),
  (3, 3, '8991002100035', 'primary', 1),
  (4, 4, '8991002100042', 'primary', 1),
  (5, 5, '8991002100059', 'primary', 1);

-- ----------------------------------------------------------------------------
-- 4. Warehouses & locations
-- ----------------------------------------------------------------------------
INSERT INTO warehouses (id, code, name, address, is_active) VALUES
  (1, 'WH-MAIN',   'Main Warehouse',   '12 Industrial Ave, Jakarta',      TRUE),
  (2, 'WH-BRANCH', 'Branch Warehouse', '45 Service Rd, Surabaya',         TRUE);

INSERT INTO locations (id, warehouse_id, parent_location_id, code, name, location_type) VALUES
  (1, 1, NULL, 'ZONE-A',   'Zone A',           'zone'),
  (2, 1, 1,    'A-01',     'Zone A / Bin 01',  'bin'),
  (3, 1, 1,    'A-02',     'Zone A / Bin 02',  'bin'),
  (4, 1, NULL, 'STAGING',  'Staging Area',     'staging'),
  (5, 1, NULL, 'DOCK-1',   'Dock Door 1',      'dock'),
  (6, 2, NULL, 'ZONE-B',   'Zone B',           'zone'),
  (7, 2, 6,    'B-01',     'Zone B / Bin 01',  'bin');

INSERT INTO location_barcodes (id, location_id, barcode) VALUES
  (1, 2, 'LOC-A01-BC'),
  (2, 3, 'LOC-A02-BC'),
  (3, 7, 'LOC-B01-BC');

-- ----------------------------------------------------------------------------
-- 5. Adjustment reasons & put-away rules
-- ----------------------------------------------------------------------------
INSERT INTO adjustment_reasons (id, code, label) VALUES
  (1, 'count_variance', 'Cycle count variance'),
  (2, 'damage',         'Damaged goods'),
  (3, 'expiry',         'Expired stock'),
  (4, 'theft_loss',     'Theft or loss'),
  (5, 'correction',     'Data entry correction'),
  (6, 'other',          'Other');

INSERT INTO putaway_rules (id, warehouse_id, category_id, product_id, target_location_id, priority) VALUES
  (1, 1, 1, NULL, 2, 10), -- Stationery -> A-01
  (2, 1, 2, NULL, 3, 10); -- IT Equipment -> A-02

-- ----------------------------------------------------------------------------
-- 6. Batches & serials
-- ----------------------------------------------------------------------------
INSERT INTO stock_batches (id, product_id, batch_number, expiry_date, supplier_reference) VALUES
  (1, 3, 'EB-2026-07', NULL,         'Acme Packaging'),
  (2, 5, 'SAN-0726',   '2027-07-01', 'CleanCo Distribution');

INSERT INTO stock_serials (id, product_id, serial_number, status, current_location_id) VALUES
  (1, 2, 'SN-HP26A-0001',   'in_stock', 3),
  (2, 2, 'SN-HP26A-0002',   'in_stock', 3),
  (3, 2, 'SN-HP26A-0003',   'in_stock', 3),
  (4, 4, 'LAT5440-0001',    'in_stock', 3),
  (5, 4, 'LAT5440-0002',    'in_stock', 3),
  (6, 4, 'LAT5440-0003',    'in_stock', 3),
  (7, 4, 'LAT5440-0004',    'in_stock', 3),
  (8, 4, 'LAT5440-0005',    'in_stock', 3);

-- ----------------------------------------------------------------------------
-- 7. Goods Receipts (4 receipts, 2 for SKU-1001 at different costs — sets up
--    the FIFO demonstration below)
-- ----------------------------------------------------------------------------
INSERT INTO goods_receipts
  (id, receipt_number, warehouse_id, receipt_date, vendor_partner_id, reference_number, status, posted_at, created_by) VALUES
  (1, 'GR-0001', 1, '2026-07-01', 501, 'PO-INV-1001', 'posted', '2026-07-01 09:15:00', 12),
  (2, 'GR-0002', 1, '2026-07-05', 501, 'PO-INV-1004', 'posted', '2026-07-05 10:40:00', 12),
  (3, 'GR-0003', 1, '2026-07-06', 502, 'PO-INV-1005', 'posted', '2026-07-06 14:05:00', 12),
  (4, 'GR-0004', 1, '2026-07-07', 503, 'PO-INV-1006', 'posted', '2026-07-07 11:20:00', 12);

INSERT INTO goods_receipt_lines
  (id, goods_receipt_id, line_no, product_id, batch_id, quantity, uom_id, unit_cost, destination_location_id) VALUES
  (1, 1, 1, 1, NULL, 100, 2, 45.00,   2), -- GR-0001: SKU-1001, 100 BOX @ 45.00
  (2, 1, 2, 2, NULL, 3,   1, 350.00,  3), -- GR-0001: SKU-1002, 3 EA @ 350.00 (serialized)
  (3, 2, 1, 1, NULL, 50,  2, 47.50,   2), -- GR-0002: SKU-1001, 50 BOX @ 47.50 (price increase)
  (4, 3, 1, 3, 1,    200, 1, 8.00,    3), -- GR-0003: SKU-1003, 200 EA @ 8.00, batch EB-2026-07
  (5, 3, 2, 5, 2,    50,  1, 12.00,   3), -- GR-0003: SKU-1005, 50 EA @ 12.00, batch SAN-0726
  (6, 4, 1, 4, NULL, 5,   1, 1200.00, 3); -- GR-0004: SKU-1004, 5 EA @ 1200.00 (serialized)

-- Ledger: one row per unit for serial-tracked receipts (clean per-unit cost
-- history), one row per line for everything else.
INSERT INTO stock_ledger
  (id, product_id, warehouse_id, location_id, batch_id, serial_id, movement_type,
   quantity, uom_id, unit_cost, total_cost, reference_type, reference_id, movement_date) VALUES
  (1,  1, 1, 2, NULL, NULL, 'receipt', 100, 2, 45.00,   4500.00,  'goods_receipt', 1, '2026-07-01 09:15:00'),
  (2,  2, 1, 3, NULL, 1,    'receipt', 1,   1, 350.00,  350.00,   'goods_receipt', 1, '2026-07-01 09:15:00'),
  (3,  2, 1, 3, NULL, 2,    'receipt', 1,   1, 350.00,  350.00,   'goods_receipt', 1, '2026-07-01 09:15:00'),
  (4,  2, 1, 3, NULL, 3,    'receipt', 1,   1, 350.00,  350.00,   'goods_receipt', 1, '2026-07-01 09:15:00'),
  (5,  1, 1, 2, NULL, NULL, 'receipt', 50,  2, 47.50,   2375.00,  'goods_receipt', 2, '2026-07-05 10:40:00'),
  (6,  3, 1, 3, 1,    NULL, 'receipt', 200, 1, 8.00,    1600.00,  'goods_receipt', 3, '2026-07-06 14:05:00'),
  (7,  5, 1, 3, 2,    NULL, 'receipt', 50,  1, 12.00,   600.00,   'goods_receipt', 3, '2026-07-06 14:05:00'),
  (8,  4, 1, 3, NULL, 4,    'receipt', 1,   1, 1200.00, 1200.00,  'goods_receipt', 4, '2026-07-07 11:20:00'),
  (9,  4, 1, 3, NULL, 5,    'receipt', 1,   1, 1200.00, 1200.00,  'goods_receipt', 4, '2026-07-07 11:20:00'),
  (10, 4, 1, 3, NULL, 6,    'receipt', 1,   1, 1200.00, 1200.00,  'goods_receipt', 4, '2026-07-07 11:20:00'),
  (11, 4, 1, 3, NULL, 7,    'receipt', 1,   1, 1200.00, 1200.00,  'goods_receipt', 4, '2026-07-07 11:20:00'),
  (12, 4, 1, 3, NULL, 8,    'receipt', 1,   1, 1200.00, 1200.00,  'goods_receipt', 4, '2026-07-07 11:20:00');

-- Valuation layers opened by each receipt ledger entry.
INSERT INTO stock_valuation_layers
  (id, product_id, warehouse_id, batch_id, source_ledger_id, received_quantity, remaining_quantity, unit_cost) VALUES
  (1,  1, 1, NULL, 1,  100, 100, 45.00),  -- SKU-1001 layer @45.00 (GR-0001)
  (2,  2, 1, NULL, 2,  1,   1,   350.00), -- SKU-1002 serial 1
  (3,  2, 1, NULL, 3,  1,   1,   350.00), -- SKU-1002 serial 2
  (4,  2, 1, NULL, 4,  1,   1,   350.00), -- SKU-1002 serial 3
  (5,  1, 1, NULL, 5,  50,  50,  47.50),  -- SKU-1001 layer @47.50 (GR-0002)
  (6,  3, 1, 1,    6,  200, 200, 8.00),   -- SKU-1003 batch EB-2026-07
  (7,  5, 1, 2,    7,  50,  50,  12.00),  -- SKU-1005 batch SAN-0726
  (8,  4, 1, NULL, 8,  1,   1,   1200.00),
  (9,  4, 1, NULL, 9,  1,   1,   1200.00),
  (10, 4, 1, NULL, 10, 1,   1,   1200.00),
  (11, 4, 1, NULL, 11, 1,   1,   1200.00),
  (12, 4, 1, NULL, 12, 1,   1,   1200.00);

-- ----------------------------------------------------------------------------
-- 8. Goods Issues — demonstrates FIFO consumption spanning two cost layers
-- ----------------------------------------------------------------------------
INSERT INTO goods_issues
  (id, issue_number, warehouse_id, issue_date, customer_partner_id, reason_code, status, posted_at, created_by) VALUES
  (1, 'GI-0001', 1, '2026-07-10', 601, NULL, 'posted', '2026-07-10 13:00:00', 15),
  (2, 'GI-0002', 1, '2026-07-12', 602, NULL, 'posted', '2026-07-12 09:30:00', 15),
  (3, 'GI-0003', 1, '2026-07-13', 601, NULL, 'posted', '2026-07-13 16:45:00', 15);

INSERT INTO goods_issue_lines
  (id, goods_issue_id, line_no, product_id, batch_id, quantity, uom_id, source_location_id) VALUES
  (1, 1, 1, 1, NULL, 30, 2, 2), -- GI-0001: SKU-1001, 30 BOX
  (2, 2, 1, 1, NULL, 90, 2, 2), -- GI-0002: SKU-1001, 90 BOX (spans two FIFO layers)
  (3, 3, 1, 2, NULL, 2,  1, 3); -- GI-0003: SKU-1002, 2 EA (serials 1 & 2)

-- GI-0001: 30 BOX fully consumed from layer 1 (@45.00). Layer 1 remaining: 100-30=70.
-- GI-0002: 90 BOX = 70 remaining from layer 1 (@45.00, exhausts it) + 20 from layer 5 (@47.50).
--          Layer 5 remaining: 50-20=30.
-- GI-0003: 2 EA serial-specific — serials 1 & 2 (layers 2 & 3), each exhausted.
INSERT INTO stock_ledger
  (id, product_id, warehouse_id, location_id, batch_id, serial_id, movement_type,
   quantity, uom_id, unit_cost, total_cost, reference_type, reference_id, movement_date) VALUES
  (13, 1, 1, 2, NULL, NULL, 'issue', -30, 2, 45.00,  -1350.00, 'goods_issue', 1, '2026-07-10 13:00:00'),
  (14, 1, 1, 2, NULL, NULL, 'issue', -70, 2, 45.00,  -3150.00, 'goods_issue', 2, '2026-07-12 09:30:00'),
  (15, 1, 1, 2, NULL, NULL, 'issue', -20, 2, 47.50,  -950.00,  'goods_issue', 2, '2026-07-12 09:30:00'),
  (16, 2, 1, 3, NULL, 1,    'issue', -1,  1, 350.00, -350.00,  'goods_issue', 3, '2026-07-13 16:45:00'),
  (17, 2, 1, 3, NULL, 2,    'issue', -1,  1, 350.00, -350.00,  'goods_issue', 3, '2026-07-13 16:45:00');

UPDATE stock_valuation_layers SET remaining_quantity = 0  WHERE id = 1;  -- SKU-1001 @45.00, exhausted
UPDATE stock_valuation_layers SET remaining_quantity = 30 WHERE id = 5;  -- SKU-1001 @47.50, 50-20
UPDATE stock_valuation_layers SET remaining_quantity = 0  WHERE id = 2;  -- serial 1, issued
UPDATE stock_valuation_layers SET remaining_quantity = 0  WHERE id = 3;  -- serial 2, issued

UPDATE stock_serials SET status = 'issued', current_location_id = NULL WHERE id IN (1, 2);

-- ----------------------------------------------------------------------------
-- 9. Transfer — SKU-1003 (batch-tracked, average cost) moved cross-warehouse.
--    Cost basis moves with the stock: source layer decremented, a new layer
--    opened at the destination anchored to the transfer_in ledger entry.
-- ----------------------------------------------------------------------------
INSERT INTO transfers
  (id, transfer_number, source_warehouse_id, source_location_id,
   destination_warehouse_id, destination_location_id, transfer_date, status, posted_at, created_by) VALUES
  (1, 'TRF-0001', 1, 3, 2, 7, '2026-07-14', 'completed', '2026-07-14 08:00:00', 12);

INSERT INTO transfer_lines (id, transfer_id, line_no, product_id, batch_id, quantity, uom_id) VALUES
  (1, 1, 1, 3, 1, 50, 1);

INSERT INTO stock_ledger
  (id, product_id, warehouse_id, location_id, batch_id, serial_id, movement_type,
   quantity, uom_id, unit_cost, total_cost, reference_type, reference_id, movement_date) VALUES
  (18, 3, 1, 3, 1, NULL, 'transfer_out', -50, 1, 8.00, -400.00, 'transfer', 1, '2026-07-14 08:00:00'),
  (19, 3, 2, 7, 1, NULL, 'transfer_in',  50,  1, 8.00, 400.00,  'transfer', 1, '2026-07-14 08:00:00');

UPDATE stock_valuation_layers SET remaining_quantity = 150 WHERE id = 6; -- source layer: 200-50

INSERT INTO stock_valuation_layers
  (id, product_id, warehouse_id, batch_id, source_ledger_id, received_quantity, remaining_quantity, unit_cost) VALUES
  (13, 3, 2, 1, 19, 50, 50, 8.00); -- new layer opened at destination, same cost basis

-- ----------------------------------------------------------------------------
-- 10. Cycle Count + resulting Adjustment — SKU-1005 short by 2 units (damage)
-- ----------------------------------------------------------------------------
INSERT INTO cycle_counts
  (id, count_number, warehouse_id, location_id, scheduled_date, assigned_to, status) VALUES
  (1, 'CC-0001', 1, 3, '2026-07-15', 12, 'completed');

INSERT INTO cycle_count_lines
  (id, cycle_count_id, product_id, batch_id, system_quantity, counted_quantity, counted_at) VALUES
  (1, 1, 5, 2, 50, 48, '2026-07-15 10:00:00');

INSERT INTO adjustments
  (id, adjustment_number, warehouse_id, location_id, adjustment_date, reason_id,
   reference_type, reference_id, status, posted_at, created_by) VALUES
  (1, 'ADJ-0001', 1, 3, '2026-07-15', 2, 'cycle_count', 1, 'posted', '2026-07-15 11:00:00', 12);

INSERT INTO adjustment_lines
  (id, adjustment_id, line_no, product_id, batch_id, system_quantity, counted_quantity, unit_cost) VALUES
  (1, 1, 1, 5, 2, 50, 48, 12.00);

INSERT INTO stock_ledger
  (id, product_id, warehouse_id, location_id, batch_id, serial_id, movement_type,
   quantity, uom_id, unit_cost, total_cost, reference_type, reference_id, movement_date) VALUES
  (20, 5, 1, 3, 2, NULL, 'adjustment', -2, 1, 12.00, -24.00, 'adjustment', 1, '2026-07-15 11:00:00');

UPDATE stock_valuation_layers SET remaining_quantity = 48 WHERE id = 7; -- SKU-1005: 50-2

-- ----------------------------------------------------------------------------
-- 11. Stock balances — denormalized cache, reconstructable from stock_ledger.
--     Values below match the ledger by construction (see steps 7-10).
-- ----------------------------------------------------------------------------
INSERT INTO stock_balances (id, product_id, warehouse_id, location_id, batch_id, qty_on_hand, avg_cost) VALUES
  (1, 1, 1, 2, NULL, 30,  47.50),  -- SKU-1001 @ WH-MAIN/A-01: 150 received - 120 issued
  (2, 2, 1, 3, NULL, 1,   350.00), -- SKU-1002 @ WH-MAIN/A-02: 3 received - 2 issued (serial 3 remains)
  (3, 3, 1, 3, 1,    150, 8.00),   -- SKU-1003 @ WH-MAIN/A-02: 200 received - 50 transferred out
  (4, 3, 2, 7, 1,     50, 8.00),   -- SKU-1003 @ WH-BRANCH/B-01: 50 transferred in
  (5, 4, 1, 3, NULL,   5, 1200.00),-- SKU-1004 @ WH-MAIN/A-02: 5 received, none issued yet
  (6, 5, 1, 3, 2,     48, 12.00);  -- SKU-1005 @ WH-MAIN/A-02: 50 received - 2 adjusted out

-- ----------------------------------------------------------------------------
-- 12. Reservation, picking, packing & shipping (Operational tier demo)
--     subject_id 4821 mirrors the Legal case referenced in CRM_SPECS.md's
--     worked example, to keep cross-module demo data internally consistent.
-- ----------------------------------------------------------------------------
INSERT INTO stock_reservations
  (id, product_id, batch_id, serial_id, warehouse_id, location_id, quantity,
   subject_type, subject_id, status, created_at) VALUES
  (1, 1, NULL, NULL, 1, NULL, 25, 'legal.case_hdrs', 4821, 'active',    '2026-07-16 09:00:00'),
  (2, 2, NULL, NULL, 1, 3,    1,  'legal.case_hdrs', 4821, 'active',    '2026-07-16 09:05:00');

INSERT INTO pick_lists (id, pick_list_number, warehouse_id, assigned_to, status) VALUES
  (1, 'PICK-0001', 1, 15, 'picking');

INSERT INTO pick_list_lines
  (id, pick_list_id, reservation_id, product_id, batch_id, serial_id, location_id,
   quantity, picked_quantity, status) VALUES
  (1, 1, 2, 2, NULL, 3, 3, 1, 0, 'pending');

INSERT INTO shipments
  (id, shipment_number, warehouse_id, customer_partner_id, carrier, tracking_number, status) VALUES
  (1, 'SHIP-0001', 1, 601, 'JNE Logistics', 'JNE1234567890', 'pending');

INSERT INTO pack_lists
  (id, pack_list_number, shipment_id, package_code, weight, weight_uom, status) VALUES
  (1, 'PACK-0001', 1, 'PKG-0001', 5.2, 'kg', 'packed');

-- ----------------------------------------------------------------------------
-- 13. Reset sequences so future application inserts continue past these
--     explicit IDs without collision.
-- ----------------------------------------------------------------------------
SELECT setval(pg_get_serial_sequence('"INVENTORY".uoms', 'id'), (SELECT MAX(id) FROM uoms));
SELECT setval(pg_get_serial_sequence('"INVENTORY".uom_conversions', 'id'), (SELECT MAX(id) FROM uom_conversions));
SELECT setval(pg_get_serial_sequence('"INVENTORY".product_categories', 'id'), (SELECT MAX(id) FROM product_categories));
SELECT setval(pg_get_serial_sequence('"INVENTORY".products', 'id'), (SELECT MAX(id) FROM products));
SELECT setval(pg_get_serial_sequence('"INVENTORY".product_barcodes', 'id'), (SELECT MAX(id) FROM product_barcodes));
SELECT setval(pg_get_serial_sequence('"INVENTORY".warehouses', 'id'), (SELECT MAX(id) FROM warehouses));
SELECT setval(pg_get_serial_sequence('"INVENTORY".locations', 'id'), (SELECT MAX(id) FROM locations));
SELECT setval(pg_get_serial_sequence('"INVENTORY".location_barcodes', 'id'), (SELECT MAX(id) FROM location_barcodes));
SELECT setval(pg_get_serial_sequence('"INVENTORY".adjustment_reasons', 'id'), (SELECT MAX(id) FROM adjustment_reasons));
SELECT setval(pg_get_serial_sequence('"INVENTORY".putaway_rules', 'id'), (SELECT MAX(id) FROM putaway_rules));
SELECT setval(pg_get_serial_sequence('"INVENTORY".stock_batches', 'id'), (SELECT MAX(id) FROM stock_batches));
SELECT setval(pg_get_serial_sequence('"INVENTORY".stock_serials', 'id'), (SELECT MAX(id) FROM stock_serials));
SELECT setval(pg_get_serial_sequence('"INVENTORY".goods_receipts', 'id'), (SELECT MAX(id) FROM goods_receipts));
SELECT setval(pg_get_serial_sequence('"INVENTORY".goods_receipt_lines', 'id'), (SELECT MAX(id) FROM goods_receipt_lines));
SELECT setval(pg_get_serial_sequence('"INVENTORY".goods_issues', 'id'), (SELECT MAX(id) FROM goods_issues));
SELECT setval(pg_get_serial_sequence('"INVENTORY".goods_issue_lines', 'id'), (SELECT MAX(id) FROM goods_issue_lines));
SELECT setval(pg_get_serial_sequence('"INVENTORY".transfers', 'id'), (SELECT MAX(id) FROM transfers));
SELECT setval(pg_get_serial_sequence('"INVENTORY".transfer_lines', 'id'), (SELECT MAX(id) FROM transfer_lines));
SELECT setval(pg_get_serial_sequence('"INVENTORY".adjustments', 'id'), (SELECT MAX(id) FROM adjustments));
SELECT setval(pg_get_serial_sequence('"INVENTORY".adjustment_lines', 'id'), (SELECT MAX(id) FROM adjustment_lines));
SELECT setval(pg_get_serial_sequence('"INVENTORY".stock_ledger', 'id'), (SELECT MAX(id) FROM stock_ledger));
SELECT setval(pg_get_serial_sequence('"INVENTORY".stock_valuation_layers', 'id'), (SELECT MAX(id) FROM stock_valuation_layers));
SELECT setval(pg_get_serial_sequence('"INVENTORY".stock_balances', 'id'), (SELECT MAX(id) FROM stock_balances));
SELECT setval(pg_get_serial_sequence('"INVENTORY".stock_reservations', 'id'), (SELECT MAX(id) FROM stock_reservations));
SELECT setval(pg_get_serial_sequence('"INVENTORY".pick_lists', 'id'), (SELECT MAX(id) FROM pick_lists));
SELECT setval(pg_get_serial_sequence('"INVENTORY".pick_list_lines', 'id'), (SELECT MAX(id) FROM pick_list_lines));
SELECT setval(pg_get_serial_sequence('"INVENTORY".shipments', 'id'), (SELECT MAX(id) FROM shipments));
SELECT setval(pg_get_serial_sequence('"INVENTORY".pack_lists', 'id'), (SELECT MAX(id) FROM pack_lists));
SELECT setval(pg_get_serial_sequence('"INVENTORY".cycle_counts', 'id'), (SELECT MAX(id) FROM cycle_counts));
SELECT setval(pg_get_serial_sequence('"INVENTORY".cycle_count_lines', 'id'), (SELECT MAX(id) FROM cycle_count_lines));

RESET search_path;
