-- =============================================================================
-- PERFORMANCE_SEED.sql
-- Sample data for the Performance Management module (schema "PERF")
--
-- Demonstrates:
--   - Multi-level KPI assignment (same "Revenue" KPI at Company + Department level)
--   - Multi-level OKR alignment (Company -> Department -> Individual, via
--     parent_okr_id, 3 levels deep)
--   - Budget vs Actual vs Forecast comparable against the same period grid
--   - A breached target and an on-track target, so Variance Analysis /
--     Status Rail rendering has both colors to demo
--   - A Scorecard composing both KPI and OKR items across perspectives
--   - Achievements auto-logged against a hit target and a completed OKR
--
-- subject_type / subject_id are the standard polymorphic seam (no hard FK,
-- per WNE/DMS/CRM/Schedule convention). This seed uses illustrative subject
-- types for organizational levels:
--   'org.company'    subject_id 1  = "Nusa Legal Group" (the tenant's own org)
--   'org.department' subject_id 1  = "Litigation Department"
--   'org.department' subject_id 2  = "Corporate Advisory Department"
--   'core.user'      subject_id 1  = owner/admin user (e.g. Simon)
--   'core.user'      subject_id 2  = a department lead
-- One row also cross-references 'crm.partners' subject_id 1 to demonstrate
-- the optional CRM integration noted in PERFORMANCE_SPECS.md §5 (account
-- scorecard for a key client) — mirrors the same cross-module seed pattern
-- used in DMS_SEED (referencing WNE workflow instance 2 / PO 143).
--
-- Explicit IDs are used throughout for referential predictability across
-- statements, consistent with every other module's seed convention.
--
-- Run with:
--   psql "<conn>" -v ON_ERROR_STOP=1 -f PERFORMANCE_SEED.sql
-- =============================================================================

BEGIN;

-- =============================================================================
-- 1. PERIODS — FY2026 year, Q1-Q4, and Jan-Sep months (enough to demo)
-- =============================================================================

INSERT INTO "PERF".periods (id, code, period_type, start_date, end_date, parent_period_id) VALUES
    (1, '2026',    'year',    '2026-01-01', '2026-12-31', NULL);

INSERT INTO "PERF".periods (id, code, period_type, start_date, end_date, parent_period_id) VALUES
    (2, '2026-Q1', 'quarter', '2026-01-01', '2026-03-31', 1),
    (3, '2026-Q2', 'quarter', '2026-04-01', '2026-06-30', 1),
    (4, '2026-Q3', 'quarter', '2026-07-01', '2026-09-30', 1),
    (5, '2026-Q4', 'quarter', '2026-10-01', '2026-12-31', 1);

INSERT INTO "PERF".periods (id, code, period_type, start_date, end_date, parent_period_id) VALUES
    (6,  '2026-07', 'month', '2026-07-01', '2026-07-31', 4),
    (7,  '2026-08', 'month', '2026-08-01', '2026-08-31', 4),
    (8,  '2026-09', 'month', '2026-09-01', '2026-09-30', 4);

SELECT setval('"PERF".periods_id_seq', 8);

-- =============================================================================
-- 2. PERSPECTIVES — classic Balanced Scorecard set
-- =============================================================================

INSERT INTO "PERF".perspectives (id, code, name, sort_order) VALUES
    (1, 'financial',   'Financial',           1),
    (2, 'customer',    'Customer',            2),
    (3, 'process',     'Internal Process',    3),
    (4, 'learning',    'Learning & Growth',   4);

SELECT setval('"PERF".perspectives_id_seq', 4);

-- =============================================================================
-- 3. KPI DEFINITIONS
-- =============================================================================

INSERT INTO "PERF".kpi_definitions (id, code, name, description, unit, direction, perspective_id) VALUES
    (1, 'revenue',            'Revenue',                  'Total billed revenue for the period',            'currency', 'higher_is_better', 1),
    (2, 'client_csat',        'Client Satisfaction',      'Average post-matter client satisfaction score',   'percent',  'higher_is_better', 2),
    (3, 'case_cycle_time',    'Case Cycle Time (days)',   'Average days from case open to close',            'number',   'lower_is_better',  3),
    (4, 'billable_utilization','Billable Utilization',    'Percent of staff hours that are billable',        'percent',  'higher_is_better', 3),
    (5, 'training_hours',     'Training Hours per Staff', 'Average professional-development hours per staff','number',   'higher_is_better', 4);

SELECT setval('"PERF".kpi_definitions_id_seq', 5);

-- =============================================================================
-- 4. OKR CYCLES
-- =============================================================================

INSERT INTO "PERF".okr_cycles (id, code, name, start_date, end_date) VALUES
    (1, '2026-Q3', '2026 Q3 OKR Cycle', '2026-07-01', '2026-09-30');

SELECT setval('"PERF".okr_cycles_id_seq', 1);

-- =============================================================================
-- 5. BADGE DEFINITIONS
-- =============================================================================

INSERT INTO "PERF".badge_definitions (id, code, name, description, trigger_type, trigger_params, icon) VALUES
    (1, 'target_hit',        'Target Hit',            'Awarded when an actual value meets or beats its target for a period.', 'target_hit',      '{}'::jsonb,                          'target'),
    (2, 'okr_completed',     'OKR Completed',         'Awarded when an Objective reaches completed status.',                  'okr_completed',   '{}'::jsonb,                          'flag'),
    (3, 'streak_3_quarters', '3-Quarter On-Track Streak', 'Awarded after 3 consecutive quarters on-track for the same KPI.',  'streak_on_track', '{"streak_length": 3}'::jsonb,        'flame');

SELECT setval('"PERF".badge_definitions_id_seq', 3);

-- =============================================================================
-- 6. BUDGETING — Litigation Department, FY2026, Q3 lines
-- =============================================================================

INSERT INTO "PERF".budget_hdrs (id, subject_type, subject_id, name, period_id, status, version, owner_id, created_by) VALUES
    (1, 'org.department', 1, 'Litigation Department FY2026 Budget', 1, 'approved', 1, 2, 1);

SELECT setval('"PERF".budget_hdrs_id_seq', 1);

INSERT INTO "PERF".budget_lines (id, budget_id, category, period_slice_id, amount_planned) VALUES
    (1, 1, 'Payroll',   6, 45000.00),
    (2, 1, 'Payroll',   7, 45000.00),
    (3, 1, 'Payroll',   8, 45000.00),
    (4, 1, 'Marketing', 6,  3000.00),
    (5, 1, 'Marketing', 7,  3000.00),
    (6, 1, 'Marketing', 8,  3000.00);

SELECT setval('"PERF".budget_lines_id_seq', 6);

-- =============================================================================
-- 7. TARGETS — Revenue assigned at BOTH Company level and Department level
-- (this is the "multi-level KPI" mechanism in action — same KPI, two subjects)
-- =============================================================================

INSERT INTO "PERF".targets (id, kpi_id, subject_type, subject_id, period_id, target_value, stretch_value, created_by) VALUES
    (1, 1, 'org.company',    1, 4, 500000.00, 550000.00, 1),  -- Company-level Q3 Revenue target
    (2, 1, 'org.department', 1, 4, 180000.00, 200000.00, 1),  -- Litigation Dept Q3 Revenue target (rolls into company)
    (3, 2, 'org.department', 1, 4,      90.00,       95.00, 1),  -- Client Satisfaction target, 90%
    (4, 4, 'org.department', 1, 4,      75.00,       85.00, 1);  -- Billable Utilization target, 75%

SELECT setval('"PERF".targets_id_seq', 4);

-- =============================================================================
-- 8. KPI ACTUALS — one hit target (CSAT, on-track) and one missed target
-- (Billable Utilization, breach), so Variance Analysis has both to show
-- =============================================================================

INSERT INTO "PERF".kpi_values (id, kpi_id, subject_type, subject_id, period_id, actual_value, source, entered_by) VALUES
    (1, 1, 'org.company',    1, 4, 512000.00, 'manual', 1),  -- Company Revenue: beat target (512k vs 500k target)
    (2, 1, 'org.department', 1, 4, 176500.00, 'manual', 2),  -- Dept Revenue: just under target (176.5k vs 180k) -> warning
    (3, 2, 'org.department', 1, 4,     93.00, 'manual', 2),  -- CSAT: beat target (93% vs 90%) -> on_track
    (4, 4, 'org.department', 1, 4,     61.00, 'manual', 2);  -- Billable Utilization: well under target (61% vs 75%) -> breach

SELECT setval('"PERF".kpi_values_id_seq', 4);

-- =============================================================================
-- 9. OKRs — 3-level alignment: Company -> Department -> Individual
-- =============================================================================

-- Level 1: Company Objective
INSERT INTO "PERF".okr_objectives (id, cycle_id, subject_type, subject_id, parent_okr_id, objective_text, status, owner_id) VALUES
    (1, 1, 'org.company', 1, NULL, 'Grow firm revenue while protecting client satisfaction', 'on_track', 1);

-- Level 2: Department Objective, aligned under the Company Objective
INSERT INTO "PERF".okr_objectives (id, cycle_id, subject_type, subject_id, parent_okr_id, objective_text, status, owner_id) VALUES
    (2, 1, 'org.department', 1, 1, 'Litigation Dept: expand billable capacity without sacrificing case quality', 'at_risk', 2);

-- Level 3: Individual Objective, aligned under the Department Objective
INSERT INTO "PERF".okr_objectives (id, cycle_id, subject_type, subject_id, parent_okr_id, objective_text, status, owner_id) VALUES
    (3, 1, 'core.user', 2, 2, 'Reduce my average case cycle time and complete Q3 training plan', 'completed', 2);

SELECT setval('"PERF".okr_objectives_id_seq', 3);

INSERT INTO "PERF".okr_key_results (id, okr_id, description, metric_type, start_value, current_value, target_value, weight) VALUES
    -- Key Results for Company Objective (1)
    (1, 1, 'Reach $500,000 in Q3 firm-wide revenue', 'numeric', 0, 512000, 500000, 60),
    (2, 1, 'Maintain client satisfaction at or above 90%', 'percent', 0, 93, 90, 40),
    -- Key Results for Department Objective (2)
    (3, 2, 'Raise billable utilization to 75%', 'percent', 55, 61, 75, 70),
    (4, 2, 'Keep average case cycle time under 60 days', 'numeric', 68, 64, 60, 30),
    -- Key Results for Individual Objective (3)
    (5, 3, 'Cut personal average case cycle time to 55 days', 'numeric', 70, 54, 55, 50),
    (6, 3, 'Complete 20 hours of Q3 professional-development training', 'numeric', 0, 22, 20, 50);

SELECT setval('"PERF".okr_key_results_id_seq', 6);

-- =============================================================================
-- 10. FORECAST — rolling forecast against the Litigation Dept budget, v1
-- =============================================================================

INSERT INTO "PERF".forecast_hdrs (id, subject_type, subject_id, budget_id, period_id, version, method, created_by) VALUES
    (1, 'org.department', 1, 1, 4, 1, 'manual', 2);

SELECT setval('"PERF".forecast_hdrs_id_seq', 1);

INSERT INTO "PERF".forecast_lines (id, forecast_id, period_slice_id, forecast_value) VALUES
    (1, 1, 6, 58000.00),
    (2, 1, 7, 59500.00),
    (3, 1, 8, 61000.00);

SELECT setval('"PERF".forecast_lines_id_seq', 3);

-- =============================================================================
-- 11. SCORECARD — Litigation Department, Q3 2026, composes KPIs + one OKR
-- across three perspectives
-- =============================================================================

INSERT INTO "PERF".scorecard_hdrs (id, name, subject_type, subject_id, period_id, created_by) VALUES
    (1, 'Litigation Department Q3 2026 Scorecard', 'org.department', 1, 4, 1);

SELECT setval('"PERF".scorecard_hdrs_id_seq', 1);

INSERT INTO "PERF".scorecard_items
    (id, scorecard_id, perspective_id, metric_type, kpi_id, okr_id, weight, actual_value, target_value, score, status) VALUES
    (1, 1, 1, 'kpi', 1, NULL, 40, 176500.00, 180000.00, 88.00, 'warning'),  -- Financial: Revenue
    (2, 1, 2, 'kpi', 2, NULL, 25,     93.00,     90.00, 100.00, 'on_track'), -- Customer: CSAT
    (3, 1, 3, 'kpi', 4, NULL, 20,     61.00,     75.00,  73.00, 'breach'),   -- Process: Billable Utilization
    (4, 1, 3, 'okr', NULL, 2, 15,       NULL,      NULL,  70.00, 'warning'); -- Process: Dept OKR progress

SELECT setval('"PERF".scorecard_items_id_seq', 4);

-- =============================================================================
-- 12. ACHIEVEMENTS — auto-logged: CSAT target hit, individual OKR completed
-- =============================================================================

INSERT INTO "PERF".achievements (id, subject_type, subject_id, badge_id, trigger_ref_type, trigger_ref_id, awarded_by, notes) VALUES
    (1, 'org.department', 1, 1, 'kpi_value',      3, NULL, 'Client Satisfaction hit 93% against a 90% target for 2026-Q3.'),
    (2, 'core.user',      2, 2, 'okr_objective',  3, NULL, 'Completed Q3 individual OKR: cycle time reduction + training plan.');

SELECT setval('"PERF".achievements_id_seq', 2);

COMMIT;

-- =============================================================================
-- End of PERFORMANCE_SEED.sql
-- =============================================================================
