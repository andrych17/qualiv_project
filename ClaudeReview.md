# Review of CLAUDE.md

Overall, this is a **solid `CLAUDE.md`**—better than most architecture guidance documents. The business context, the *"would a solo developer still be able to reason about this in 6 months?"* heuristic, and the explicit **Core vs. Vertical dependency rule** are excellent guidelines that help prevent architectural mistakes, especially when working with AI coding agents.

That said, there are several inconsistencies and documentation gaps that should be addressed.

---

# High Priority

## 1. API Architecture Contradicts Itself

The document currently describes two different frontend architectures:

- **Section 3** states that Vue should act as a client of Laravel's API (REST or GraphQL/RPC).
- **Section 10** instructs controllers to return Inertia responses.
- The technology stack also lists **Inertia.js**.

These approaches are fundamentally different.

**REST approach**

```
Vue
   ↓
REST API
   ↓
Laravel
```

**Inertia approach**

```
Browser
   ↓
Laravel Route
   ↓
Controller
   ↓
Inertia::render(...)
   ↓
Vue Component
```

An AI coding agent may switch between creating REST APIs and Inertia pages depending on which section it references.

### Recommendation

Choose one architectural direction and document it explicitly.

For example:

> Web applications use Inertia.js.
>
> Business logic must always live inside Service classes.
>
> Future Mobile/Desktop clients should communicate through versioned REST APIs that reuse the same Services, avoiding duplicated business logic.

This also resolves the ambiguity introduced by Section 2:

> Separate Back-end from Front-end for Web/Mobile/Tablet.

---

## 2. Multi-Tenancy Strategy Is Mentioned but Not Defined

Section 4 explains that every table should have:

- `tenant_id`
- global scopes

However, it never defines the actual tenancy strategy.

Important architectural decisions are still missing:

- Single database with `tenant_id`
- Database per tenant
- Schema per tenant
- Using `stancl/tenancy`
- Tenant resolution strategy
    - login-bound
    - subdomain
    - path
- Whether PostgreSQL Row-Level Security (RLS) will be used

This is one of the most important decisions in a SaaS product because changing tenancy architecture later is extremely expensive.

### Recommendation

Explicitly document:

- tenancy model
- tenant resolution mechanism
- isolation strategy
- future scalability considerations

---

## 3. Development Commands Are Likely Incorrect

The current commands rely on:

```bash
composer:latest
```

However, the Composer image is primarily intended to run Composer itself.

It often does **not** include required PHP extensions such as:

- pdo_pgsql
- redis
- pgsql

As a result, commands like:

```bash
php artisan migrate
php artisan serve
```

may fail when connecting to PostgreSQL.

Additionally:

- PostgreSQL container isn't documented.
- Redis container isn't documented.
- Queue worker isn't started.

### Recommendation

Use a proper `docker-compose.yml` (or `docker compose`) containing:

- PHP runtime
- PostgreSQL
- Redis
- Queue Worker

This provides a reproducible development environment instead of many standalone `docker run` commands.

---

# Medium Priority

## 4. Coding Conventions Are Split Across Two Sections

Section 6 and Section 10 both describe coding conventions.

Over time they may diverge and contradict each other.

### Recommendation

Either:

- Merge them into one section

or

- Keep Section 6 as architectural principles
- Keep Section 10 as concrete implementation conventions

with a clear cross-reference.

---

## 5. Checked Items Without Recorded Decisions

Section 8 marks several items as completed:

- API contract
- Billing ownership

However, the actual decisions are never written.

For example:

Current:

```text
[x] API contract style
```

Better:

```text
[x] API Contract
Decision:
- Web uses Inertia.
- REST API will be provided only for external/mobile clients.
```

A checked checkbox without the recorded decision loses valuable architectural context.

---

## 6. Authentication Should Be Prioritized

Authentication is still marked as undecided.

However, many other architectural decisions depend on it:

- tenant resolution
- authorization
- feature flags
- policies

For an Inertia-based Laravel application, **Laravel Sanctum** is likely the appropriate choice over Passport.

This decision should move higher in priority.

---

## 7. Missing Definition of Done

There is no documented completion checklist.

Claude Code benefits from explicit acceptance criteria.

Example:

- Laravel Pint passes
- TypeScript passes
- PHPUnit passes
- Services are tested
- Tenant scoping verified
- No business logic inside controllers

Also consider adding:

```bash
npm run typecheck
```

instead of relying on:

```bash
npm run build
```

for TypeScript validation, since it provides much faster feedback.

---

# Minor Issues

## 8. Empty Heading

Section 9 contains:

```text
## 9. Development

## Build & Run Commands
```

The first heading is empty and should either contain content or be removed.

---

## 9. Missing Database Conventions

The document doesn't specify:

- bigint vs UUID vs ULID
- naming conventions
- index conventions
- composite indexes such as:

```
tenant_id + created_at
tenant_id + foreign_key
```

These decisions are made frequently by AI coding agents and should be standardized.

---

## 10. Cross-Module Event Conventions

The document encourages communication through Events but never defines:

- where Events live
- where Listeners live
- naming conventions

A simple convention would help maintain consistency.

Example:

- Events belong to the emitting module.
- Listeners belong to the consuming module.

---

## 11. Frontend Testing Strategy

The document never specifies whether frontend testing exists.

If frontend tests are intentionally omitted for now, state that explicitly to prevent AI from introducing an unnecessary testing framework.

Example:

> Frontend testing is intentionally omitted during MVP development. Focus on backend tests.

---

## 12. Stray UI Guideline

This sentence appears under Section 2 without a heading:

> Use existing UI components extensively, and if not found one, create.

It feels disconnected from the surrounding architectural discussion.

It would fit better inside `DESIGN.md` or under the Design System section.

---

# Top Three Recommended Fixes

If only three improvements are made, they should be:

1. Resolve the Inertia vs REST API contradiction by clearly defining the web architecture.
2. Explicitly document the multi-tenancy strategy and tenant resolution mechanism.
3. Replace the current `composer:latest` development commands with a proper Docker Compose development environment.

These three changes remove the largest sources of ambiguity and significantly reduce the risk of AI generating inconsistent architecture or code that later needs to be rewritten.