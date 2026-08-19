# Seeded Dev Accounts

> Local dev only — `DatabaseSeeder` refuses to run in production
> (`app()->isProduction()` guard). Never rely on these outside a local/dev environment.

## Central Admin (platform-level, `nusaevo` central DB)

URL: `/central/login`

| Email | Password | Notes |
|---|---|---|
| `admin@nusaevo.com` (or `CENTRAL_ADMIN_EMAIL` env) | `password` (or `CENTRAL_ADMIN_PASSWORD` env) | Seeded by `CentralSeeder`. Separate `central_admin` guard — only account for central platform admin. |

## Regular tenant login (login-bound, per `CLAUDE.md` §4)

URL: `/login`

Same email/password works across every tenant the user is a member of (except `viewer`,
seeded into `001` only) — the sidebar tenant dropdown switches between them after login.

| Email | Password | Tenant 001 (Nusaevo) | Tenant 002 (Demo Legal) |
|---|---|---|---|
| `admin@nusaevo.com` | `password` | ✅ Admin User | ✅ Admin User (B) |
| `staff@nusaevo.com` | `password` | ✅ Staff User | ✅ Staff User (B) |
| `viewer@nusaevo.com` | `password` | ✅ Viewer User | — |
| `andry@nusaevo.com` | `password` | ✅ Andry Huang | ✅ Andry Huang (B) |
| `tirta@nusaevo.com` | `password` | ✅ Tirta | ✅ Tirta (B) |
| `simon@nusaevo.com` | `password` | ✅ Simon | ✅ Simon (B) |

## Tenants

| ID | Name | Plan |
|---|---|---|
| `001` | Nusaevo | `internal` |
| `002` | Demo Legal | `legal` |

Seeded by `database/seeders/DatabaseSeeder.php` (users/tenants) and
`database/seeders/CentralSeeder.php` (central admin + plan catalog). Re-run with:

```bash
docker compose exec app php artisan migrate:fresh --seed
```
