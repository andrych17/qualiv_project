# Migration Patch Notes

Operational notes for applying migrations to an existing (non-fresh) staging or production
database. Not a full runbook — just the checks that can't be encoded as automatic, safe
migration steps.

## `2026_08_18_000005_add_unique_billing_period_to_central_invoices.php`

Adds a unique constraint on `central_invoices (tenant_id, plan_code, billing_period_start)`.
If any pre-existing rows already violate this (e.g. two invoices manually created for the same
tenant + plan + billing period before this patch), the migration will fail outright and block
the deploy.

**Before migrating**, run against the target database:

```sql
SELECT tenant_id, plan_code, billing_period_start, COUNT(*)
FROM central_invoices
GROUP BY tenant_id, plan_code, billing_period_start
HAVING COUNT(*) > 1;
```

If this returns any rows: resolve the duplicates manually before migrating. This schema
deliberately has no automated de-dup policy (invoices are voided, never deleted, per
`CENTRAL_SPECS.md` §3E) — pick which duplicate is canonical and void the other(s)
(`status = 'void'`) so only one row remains per `(tenant_id, plan_code, billing_period_start)`.

## `2026_08_18_000006_add_review_fields_to_central_payments.php`

Backfills every pre-existing `central_payments` row to `status = 'confirmed'`,
`reviewed_at = COALESCE(paid_at, created_at)` — safe and automatic, no operator action needed.
Documented here only so it's clear this is intentional: those rows predate the §3F
submit/review flow and were always created under the old "record = already paid" behavior.

## `2026_08_18_000004_add_provisioning_fields_to_tenants_table.php`

Backfills every pre-existing `tenants` row's `tenant_db_name` to `'tenant_' || id` and
`provisioned_at` to that row's own `created_at` — safe and automatic, no operator action needed.

## `2026_08_18_000009_seed_platform_default_dunning_policy.php`

Idempotent (`insertOrIgnore`) insert of the required `platform_default` dunning policy row.
Needed because `CentralSeeder` (which also inserts this row) never runs in production —
`DatabaseSeeder` guards itself out of production before calling it. This migration is the only
guaranteed source of this row outside of local/staging dev seeding.
