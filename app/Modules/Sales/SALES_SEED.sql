-- ============================================================================
-- SALES Module — Sample / Seed Data
--
-- Assumes: "SALES" schema already created (see SALES_SCHEMA.sql), and "CRM"
-- schema already deployed and seeded with, at minimum, these Customer-role
-- partners (per CRM_SPECS.md's own seed data — IDs shown for reference):
--   101 = Northwind Trading Co   (organization)
--   102 = Sarah Bennett          (individual)
--   103 = Meridian Property Group (organization)
-- and one open CRM Lead:
--   55  = "Harborview Consulting - inbound web inquiry"
--
-- Explicit IDs are used throughout (per project convention) so this data can
-- be cross-referenced predictably from other modules' seed scripts.
--
-- Story told by this data: a fulfilled + partially-returned/credited order
-- with a paid invoice, an active recurring retainer contract with one paid
-- and one overdue billing cycle, an open direct order mid-fulfillment, a
-- pre-conversion opportunity sourced from a CRM Lead, and two commission
-- settlements (one paid with a reversal line, one still in draft) — enough
-- surface area to exercise every engine in the spec.
-- ============================================================================

SET search_path TO "SALES", public;

-- ----------------------------------------------------------------------------
-- 1. MASTER / LOOKUP / CONFIG
-- ----------------------------------------------------------------------------

INSERT INTO territories (id, name, description) VALUES
    (1, 'North',         'North region accounts'),
    (2, 'South',         'South region accounts'),
    (3, 'International', 'Non-domestic accounts');
SELECT setval(pg_get_serial_sequence('territories','id'), 3, true);

INSERT INTO sales_teams (id, name, territory_id) VALUES
    (1, 'Enterprise Team', 1),
    (2, 'SMB Team',        2);
SELECT setval(pg_get_serial_sequence('sales_teams','id'), 2, true);

INSERT INTO sales_team_members (sales_team_id, user_id, role_in_team) VALUES
    (1, 1, 'lead'),
    (1, 2, 'member'),
    (2, 3, 'lead');

INSERT INTO items (id, sku, name, description, uom, item_type, default_price) VALUES
    (1, 'SVC-RETAINER-LEGAL', 'Consulting Retainer - Legal', 'Monthly recurring legal consulting retainer', 'month',  'service', 2000.00),
    (2, 'SVC-DOC-REVIEW',     'Document Review Package',     'Bulk document review engagement',              'package','service',  750.00),
    (3, 'SVC-ONBOARDING',     'Standard Onboarding',         'One-time client onboarding and setup',         'unit',   'service',  500.00),
    (4, 'SVC-SUPPORT-PREM',   'Premium Support Plan',        'Monthly recurring premium support',            'month',  'service',  750.00);
SELECT setval(pg_get_serial_sequence('items','id'), 4, true);

INSERT INTO price_lists (id, name, currency, territory_id, is_default, valid_from) VALUES
    (1, 'Standard Price List',   'USD', NULL, TRUE,  '2026-01-01'),
    (2, 'Enterprise Price List', 'USD', 1,    FALSE, '2026-01-01');
SELECT setval(pg_get_serial_sequence('price_lists','id'), 2, true);

INSERT INTO price_list_lines (price_list_id, item_id, unit_price) VALUES
    (1, 1, 2000.00), (1, 2, 750.00), (1, 3, 500.00), (1, 4, 750.00),
    (2, 1, 2000.00), (2, 2, 700.00), (2, 3, 500.00), (2, 4, 700.00);

INSERT INTO promo_codes (id, code, description, discount_type, discount_value, valid_from, valid_to, usage_limit, usage_count) VALUES
    (1, 'WELCOME10', 'New client 10% off first engagement', 'percentage', 10.00, '2026-01-01', '2026-12-31', 100, 5);
SELECT setval(pg_get_serial_sequence('promo_codes','id'), 1, true);

INSERT INTO commission_plans (id, name, basis, flat_percent, applies_to_type, applies_to_id, effective_from) VALUES
    (1, 'Standard Team Flat 5%', 'flat_percent', 5.000, 'team', 1, '2026-01-01'),
    (2, 'SMB Rep Tiered Plan',   'tiered',       NULL,  'rep',  3, '2026-01-01');
SELECT setval(pg_get_serial_sequence('commission_plans','id'), 2, true);

INSERT INTO commission_plan_tiers (commission_plan_id, min_amount, max_amount, percent) VALUES
    (2,    0.00, 1000.00, 3.000),
    (2, 1000.00,     NULL, 5.000);

INSERT INTO customer_sales_profiles (partner_id, territory_id, sales_team_id, price_list_id, assigned_rep_user_id) VALUES
    (101, 1, 1, 2, 1),
    (102, 2, 2, 1, 3),
    (103, 2, 2, 1, 3);

INSERT INTO customer_credit_profiles (partner_id, credit_limit, payment_terms_days, on_hold) VALUES
    (101, 50000.00, 30, FALSE),
    (102,  5000.00, 15, FALSE),
    (103, 20000.00, 30, FALSE);

INSERT INTO sales_portal_tokens (partner_id, expires_at) VALUES
    (101, '2027-01-01 00:00:00+00'),
    (103, '2027-01-01 00:00:00+00');

-- ----------------------------------------------------------------------------
-- 2. OPPORTUNITIES
-- ----------------------------------------------------------------------------

INSERT INTO opp_hdrs (id, name, customer_id, lead_id, stage, owner_user_id, sales_team_id, estimated_value, expected_close_date) VALUES
    (1, 'Northwind - Annual Retainer',        101,  NULL, 'Won',        1, 1, 2500.00, '2026-07-05'),
    (2, 'Sarah Bennett - Document Review',    102,  NULL, 'Quoted',     3, 2, 1500.00, '2026-07-25'),
    (3, 'Harborview Consulting - New Engagement', NULL, 55, 'Qualifying', 3, 2, 3000.00, '2026-08-15');
SELECT setval(pg_get_serial_sequence('opp_hdrs','id'), 3, true);

-- ----------------------------------------------------------------------------
-- 3. QUOTATIONS (revision example on quote 2 -> quote 3)
-- ----------------------------------------------------------------------------

INSERT INTO quot_hdrs (id, quote_no, customer_id, opportunity_id, price_list_id, revision_no, parent_quote_id, status, valid_until) VALUES
    (1, 'QUO-2026-0001',    101, 1, 2, 1, NULL, 'converted', '2026-07-10'),
    (2, 'QUO-2026-0002',    102, 2, 1, 1, NULL, 'declined',  '2026-07-20'),
    (3, 'QUO-2026-0002-R2', 102, 2, 1, 2, 2,    'accepted',  '2026-08-05');
SELECT setval(pg_get_serial_sequence('quot_hdrs','id'), 3, true);

INSERT INTO quot_lines (quot_hdr_id, line_no, item_id, description, qty, unit_price, tax_percent, line_total) VALUES
    (1, 1, 1, 'Consulting Retainer - Legal (first month)', 1, 2000.00, 0, 2000.00),
    (1, 2, 3, 'Standard Onboarding',                        1,  500.00, 0,  500.00),
    (2, 1, 2, 'Document Review Package',                    2,  750.00, 0, 1500.00),
    (3, 1, 2, 'Document Review Package (revised qty)',      2,  700.00, 0, 1400.00);

UPDATE quot_hdrs SET subtotal = 2500.00, grand_total = 2500.00 WHERE id = 1;
UPDATE quot_hdrs SET subtotal = 1500.00, grand_total = 1500.00 WHERE id = 2;
UPDATE quot_hdrs SET subtotal = 1400.00, grand_total = 1400.00 WHERE id = 3;

-- ----------------------------------------------------------------------------
-- 4. SALES ORDERS
-- ----------------------------------------------------------------------------

INSERT INTO so_hdrs (id, so_no, customer_id, quote_id, price_list_id, status, order_date) VALUES
    (1, 'SO-2026-0001', 101, 1, 2, 'fulfilled', '2026-07-05'),
    (2, 'SO-2026-0002', 102, 3, 1, 'confirmed', '2026-08-06');
SELECT setval(pg_get_serial_sequence('so_hdrs','id'), 2, true);

INSERT INTO so_lines (id, so_hdr_id, line_no, item_id, description, qty_ordered, qty_delivered, qty_invoiced, unit_price, line_total) VALUES
    (1, 1, 1, 1, 'Consulting Retainer - Legal (first month)', 1, 1, 1, 2000.00, 2000.00),
    (2, 1, 2, 3, 'Standard Onboarding',                        1, 1, 1,  500.00,  500.00),
    (3, 2, 1, 2, 'Document Review Package',                    2, 1, 0,  700.00, 1400.00);
SELECT setval(pg_get_serial_sequence('so_lines','id'), 3, true);

UPDATE so_hdrs SET subtotal = 2500.00, grand_total = 2500.00 WHERE id = 1;
UPDATE so_hdrs SET subtotal = 1400.00, grand_total = 1400.00, status = 'partially_fulfilled' WHERE id = 2;

UPDATE quot_hdrs SET converted_so_id = 1 WHERE id = 1;

-- ----------------------------------------------------------------------------
-- 5. DELIVERIES
-- ----------------------------------------------------------------------------

INSERT INTO dlv_hdrs (id, dlv_no, so_hdr_id, status, carrier, tracking_no, shipped_at, delivered_at) VALUES
    (1, 'DLV-2026-0001', 1, 'delivered', 'N/A (service delivery)', NULL, '2026-07-06 09:00:00+00', '2026-07-06 09:00:00+00'),
    (2, 'DLV-2026-0002', 2, 'shipped',   'N/A (service delivery)', NULL, '2026-08-10 14:00:00+00', NULL);
SELECT setval(pg_get_serial_sequence('dlv_hdrs','id'), 2, true);

INSERT INTO dlv_lines (dlv_hdr_id, so_line_id, qty_shipped) VALUES
    (1, 1, 1),
    (1, 2, 1),
    (2, 3, 1);

-- ----------------------------------------------------------------------------
-- 6. CONTRACTS & SUBSCRIPTIONS
-- ----------------------------------------------------------------------------

INSERT INTO contr_hdrs (id, contract_no, customer_id, name, term_start, term_end, auto_renew, price_list_id, status) VALUES
    (1, 'CTR-2026-0001', 101, 'Northwind Annual Retainer Agreement',  '2026-01-01', '2026-12-31', TRUE, 2, 'active'),
    (2, 'CTR-2026-0002', 103, 'Meridian Premium Support Agreement',   '2026-06-01', NULL,         TRUE, 1, 'active');
SELECT setval(pg_get_serial_sequence('contr_hdrs','id'), 2, true);

INSERT INTO contr_subscriptions (id, contr_hdr_id, item_id, description, recurring_amount, billing_interval, next_bill_date) VALUES
    (1, 1, 1, 'Monthly consulting retainer', 2000.00, 'monthly', '2026-08-01'),
    (2, 2, 4, 'Monthly premium support',      750.00, 'monthly', '2026-08-01');
SELECT setval(pg_get_serial_sequence('contr_subscriptions','id'), 2, true);

-- ----------------------------------------------------------------------------
-- 7. BILLING (invoices, payments, recurring schedule)
-- ----------------------------------------------------------------------------

INSERT INTO inv_hdrs (id, invoice_no, customer_id, so_hdr_id, contract_id, invoice_type, status, issue_date, due_date, subtotal, grand_total, amount_paid) VALUES
    (1, 'INV-2026-0001', 101, 1,    NULL, 'standard',    'paid',    '2026-07-05', '2026-07-20', 2500.00, 2500.00, 2500.00),
    (2, 'INV-2026-0002', 102, 2,    NULL, 'deposit',     'sent',    '2026-08-06', '2026-08-21',  450.00,  450.00,    0.00),
    (3, 'INV-2026-0003', 101, NULL,    1, 'recurring',   'paid',    '2026-07-01', '2026-07-15', 2000.00, 2000.00, 2000.00),
    (4, 'INV-2026-0004', 101, 1,    NULL, 'credit_note', 'paid',    '2026-07-18', NULL,         -500.00, -500.00, -500.00),
    (5, 'INV-2026-0005', 103, NULL,    2, 'recurring',   'overdue', '2026-07-01', '2026-07-15',  750.00,  750.00,    0.00);
SELECT setval(pg_get_serial_sequence('inv_hdrs','id'), 5, true);

INSERT INTO inv_lines (inv_hdr_id, line_no, so_line_id, description, qty, unit_price, line_total) VALUES
    (1, 1, 1, 'Consulting Retainer - Legal (first month)', 1,  2000.00,  2000.00),
    (1, 2, 2, 'Standard Onboarding',                        1,   500.00,   500.00),
    (2, 1, 3, 'Deposit - Document Review Package (30%)',    1,   450.00,   450.00),
    (3, 1, NULL, 'Monthly Retainer - July 2026',            1,  2000.00,  2000.00),
    (4, 1, 2, 'Credit - Standard Onboarding (Return RET-2026-0001)', -1, 500.00, -500.00),
    (5, 1, NULL, 'Premium Support Plan - July 2026',        1,   750.00,   750.00);

INSERT INTO inv_payments (inv_hdr_id, amount, method, reference, paid_at, recorded_by) VALUES
    (1, 2500.00, 'bank_transfer', 'WIRE-88231',              '2026-07-06 10:00:00+00', 1),
    (3, 2000.00, 'bank_transfer', 'WIRE-88512',               '2026-07-02 10:00:00+00', 1),
    (4, -500.00, 'bank_transfer', 'REFUND-RET-2026-0001',     '2026-07-19 11:00:00+00', 1);

INSERT INTO recurring_billing_schedules (id, contract_id, subscription_line_id, customer_id, amount, billing_interval, next_bill_date, last_invoice_id) VALUES
    (1, 1, 1, 101, 2000.00, 'monthly', '2026-08-01', 3),
    (2, 2, 2, 103,  750.00, 'monthly', '2026-08-01', 5);
SELECT setval(pg_get_serial_sequence('recurring_billing_schedules','id'), 2, true);

-- ----------------------------------------------------------------------------
-- 8. RETURNS
-- ----------------------------------------------------------------------------

INSERT INTO ret_hdrs (id, ret_no, so_hdr_id, inv_hdr_id, customer_id, reason_code, status) VALUES
    (1, 'RET-2026-0001', 1, 1, 101, 'change_of_scope', 'refunded');
SELECT setval(pg_get_serial_sequence('ret_hdrs','id'), 1, true);

INSERT INTO ret_lines (ret_hdr_id, so_line_id, qty_returned, condition_notes) VALUES
    (1, 2, 1, 'Client requested scope reduction after project kickoff; onboarding not performed.');

-- ----------------------------------------------------------------------------
-- 9. COMMISSIONS
-- ----------------------------------------------------------------------------

INSERT INTO comm_settlements (id, period_start, period_end, sales_team_id, rep_user_id, status, total_amount, approved_by, approved_at, paid_at) VALUES
    (1, '2026-07-01', '2026-07-31', 1,    NULL, 'paid',  200.00, 1, '2026-08-03 09:00:00+00', '2026-08-05 09:00:00+00'),
    (2, '2026-08-01', '2026-08-31', NULL, 3,    'draft',  55.00, NULL, NULL, NULL);
SELECT setval(pg_get_serial_sequence('comm_settlements','id'), 2, true);

INSERT INTO comm_settlement_lines (id, comm_settlement_id, inv_hdr_id, commission_plan_id, base_amount, commission_amount, is_reversal, reversal_of_line_id) VALUES
    (1, 1, 1, 1, 2500.00, 125.00, FALSE, NULL),
    (2, 1, 4, 1, -500.00, -25.00, TRUE,  1),
    (3, 1, 3, 1, 2000.00, 100.00, FALSE, NULL);
SELECT setval(pg_get_serial_sequence('comm_settlement_lines','id'), 3, true);

INSERT INTO comm_settlement_lines (comm_settlement_id, inv_hdr_id, commission_plan_id, base_amount, commission_amount, is_reversal) VALUES
    (2, 2, 2, 1500.00, 55.00, FALSE);

-- ----------------------------------------------------------------------------
-- Sanity checks (fail the whole script under ON_ERROR_STOP if these drift)
-- ----------------------------------------------------------------------------

DO $$
DECLARE v_total NUMERIC;
BEGIN
    SELECT SUM(commission_amount) INTO v_total FROM comm_settlement_lines WHERE comm_settlement_id = 1;
    IF v_total <> 200.00 THEN
        RAISE EXCEPTION 'Settlement 1 line total (%) does not match header total_amount (200.00)', v_total;
    END IF;
END $$;

-- ============================================================================
-- End of SALES seed data
-- ============================================================================
