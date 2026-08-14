# AIInsight Module
## AI-Powered "Ask Your Data" Analytics — Core Shared Module (not standalone-sellable — requires other modules' data to be useful)

# 1. Backgrounds

> Pain point and business value.

Every vertical (Legal today; Property, and others later) and every Core module in this
platform (CRM, Accounting, HCM, Inventory, ...) accumulates structured data a tenant
increasingly wants to interrogate in plain language rather than through a fixed report screen
— "which cases are overdue," "what's our AR aging by client this month," "how many leave
requests are pending." Left unsolved centrally, this repeats the exact anti-pattern every
other Core module in this platform was built to avoid:

- Each module would otherwise build its own ad hoc "smart search" or report-builder — no
  shared safety layer, no shared cost control, no consistent UX, and a real risk of someone
  eventually wiring a write-capable query path into one module and not another.
- Vertical-specific vocabulary (a Legal "matter," a Property "unit") has no natural home if
  AIInsights hardcodes per-vertical logic — that would violate the same Core-has-zero-
  knowledge-of-Vertical rule (`CLAUDE.md` §2) every other Core module in this platform follows.
- Giving an LLM read access to a tenant's live database is a genuinely sensitive capability —
  without a hard row-limit, statement timeout, full audit trail, and a real Zero Data
  Retention posture with the model provider, this is not something a conservative legal-buyer
  audience (`DESIGN.md`'s stated brief) would trust, and shouldn't be shipped without those
  guardrails built in from the first version, not retrofitted later.

**Client requirements:**
- **Zero vertical-specific logic in the module itself.** A vertical's terminology and domain
  context (e.g. Legal's "case"/"matter"/"deed" vocabulary) is supplied to AIInsights through a
  registered extension point (§3E), never hardcoded — the same "Core defines the contract, any
  caller populates it" pattern already used for Sales's `SalesOrderRequested`
  (`SALES_SPECS.md` §3I) and every driver-interface pattern in this platform.
- Multi-tenant aware, same DB-per-tenant isolation as every other Core module — no `tenant_id`
  column, and critically, no cross-tenant query path is possible even in principle, since every
  query executes against that tenant's own read-only DB role inside that tenant's own database
  (§3B).
- **Read-only, always** — no write tool is exposed to the model in any version of this module.
  This is a permanent product decision, not a v1 limitation to relax later (§2).
- **Not standalone-sellable**, unlike most other Core modules in this platform (DMS, Schedule,
  Inventory, etc.) — AIInsights has nothing useful to say about an empty tenant DB. It is
  always an add-on to a tenant that already has real data flowing through other modules.
- **A Zero Data Retention (ZDR) agreement with Anthropic is a hard prerequisite for production
  launch to any tenant with confidentiality obligations** — most immediately every
  Legal-vertical tenant, since attorney-client privilege attaches to case data this module
  would otherwise process through a third-party model API. This module must not be switched on
  for a paying tenant until ZDR terms are confirmed in place — see §5. Other specs that
  reference this requirement (`PURCHASE_SPECS.md` §3L, `INVENTORY_SPECS.md` §2 Optimization
  tier) point back to this section as the source of truth for it.
- Plan/entitlement gating (an "AI Insights" add-on SKU per `CLAUDE.md` §4's plan/feature-flag
  convention) with a per-tenant monthly token/query budget enforced in Core — both a feature
  gate and the module's primary cost-control lever, not optional polish.
- Full audit trail of every executed query (SQL, tenant, user, timestamp, row count, latency)
  — the safety net that makes "an LLM touching tenant data unsupervised" defensible, and the
  first thing to show a skeptical buyer in a demo.

# 2. Goals

> Designated features. MVP-first, and — unlike most modules in this platform — one hard,
> permanent constraint that never relaxes even in Future Version: **no write capability,
> ever.**

**MVP**
- **Main Interaction (Chat Interface)** — single chat-style interface per tenant user (§3A).
- **Query Execution Engine** — `AIInsightsService::ask()` facade, tenant-scoped read-only DB
  role, hard row-limit and statement timeout enforced server-side, never trusting the model to
  self-limit (§3B).
- **Schema Context & Guardrails** — schema annotations so the model isn't guessing column
  meanings, a full query audit log, and a schema/table denylist per tenant (§3C).
- **Plan / Entitlement Gating** — per-tenant monthly token/query budget, tied into the existing
  plan/feature-flag mechanism (§3D).
- **Vertical Prompt-Context Extension Point** — the seam that lets Legal (and any future
  vertical) supply domain vocabulary without AIInsights having vertical-specific code (§3E).
- **ZDR agreement in place before production go-live for any confidentiality-sensitive
  tenant** — a launch gate, not a feature to build, but treated with the same seriousness as
  any MVP item above (§5).

**Future Version (explicitly deferred — do not build now)**
- **Any write capability.** Not deferred because it's hard — deferred *permanently* as a
  product boundary. If a future version of this module ever needs to take an action (not just
  answer a question), that action should go through the relevant module's own facade/workflow
  (e.g. WNE) with a human approval step, never a raw write issued by the model.
- **Proactive/scheduled insights** ("email me this scorecard every Monday") — a natural
  extension once the MVP chat loop is proven; would reuse **WNE** for delivery and **Schedule**
  for cadence, not new notification/scheduling logic.
- **Auto-refreshing `schema_annotations`** via a scheduled LLM pass (v1 is a one-time
  auto-draft from `information_schema` + hand-editing) — becomes worth building once
  tenant-specific `CUSTOMFIELDS` schema drift is common enough that manual upkeep is a real
  burden.
- **Cross-module analytical connectors** already described as *depending on* AIInsights Core
  in other specs' own Future Version sections — Purchase's AI-assisted procurement
  (`PURCHASE_SPECS.md` §3L), Inventory's AI Forecasting/Slotting/Anomaly Detection
  (`INVENTORY_SPECS.md` §2 Optimization tier), HCM's HR Analytics (`HCM_SPECS.md` §3O),
  Performance's drill-down BI (`PERFORMANCE_SPECS.md` §2) — all build *on top of* this module
  once it exists; none of them duplicate it, and none of them are built here.
- **Voice interface / multi-turn agentic tool chaining** beyond one query per conversational
  turn.

# 3. Forms / Engines

## 3A. Main Interaction (Chat Interface)

- Single chat-style interface, tenant-scoped, reusing `DESIGN.md`'s Card/Data Table primitives
  for rendering results — a query result becomes a Data Table with Status Rail if it's
  status-bearing data (e.g. "overdue cases"), otherwise a plain table or chart, per the shared
  component library every other module already composes from.
- **Conversation history is persisted per user, not just per tenant**
  (`AIINSIGHT.conversations`, `AIINSIGHT.messages`) — a paralegal's question thread must never
  leak into a partner's, even though both belong to the same tenant.
- Each assistant turn that ran a query stores the actual SQL executed alongside the answer
  (`AIINSIGHT.query_audit`, §3C) — this matters once an LLM is touching tenant data
  unsupervised; the answer is never trusted without a reconstructable trail of what actually
  ran.
- Usage/entitlement snapshot visible alongside the chat (tokens/queries remaining this month,
  per §3D) — so a user isn't surprised by a budget cutoff mid-conversation.

**Rules / logic**
- A conversation belongs to exactly one user; there is no shared/team conversation concept in
  MVP — matches the "my work" vs. "team work" distinction already established elsewhere
  (e.g. WNE's My Approvals, §3H of `WNE_SPECS.md`), applied here at its simplest.

## 3B. Query Execution Engine

- `AIInsightsService::ask($tenantUser, $question)` — the facade any UI calls; the only
  supported integration point for this module (no direct model-API calls from elsewhere in the
  codebase).
- Internally: builds a system prompt from schema context (§3C) plus any registered vertical
  prompt-context (§3E), calls the Claude API's Messages endpoint with a single
  `run_readonly_query` tool exposed to the model.
- The tool executes against a **per-tenant, read-only Postgres role**, with a hard row-limit
  (e.g. `LIMIT 500`) and a statement timeout injected server-side on every call — the model is
  never trusted to self-limit; both constraints are enforced at the database-role/connection
  level, not just in the prompt.
- **No write tool is exposed, in any configuration, for any tenant.** This is checked at the
  tool-registration level, not left as a prompt instruction the model could be talked out of.
- Model selection: **Haiku by default**, escalating to **Sonnet** for queries the default
  model flags as too complex to answer confidently (e.g. multi-step joins across several
  schemas) — a cost-control decision, using prompt caching on the schema-context portion of the
  system prompt so repeated questions against the same tenant's schema don't re-pay that cost
  every turn.

**Rules / logic**
- Every query is scoped to exactly one tenant's DB-per-tenant boundary by construction — the
  read-only role itself has no visibility into any other tenant's database, so there is no
  cross-tenant query path to defend against at the application layer; it's structurally
  impossible, the same DB-per-tenant guarantee every other module in this platform relies on.
- A query that would exceed the row limit or timeout is truncated/aborted with a clear message
  surfaced back through the chat (per `DESIGN.md` voice guidance), never silently returning
  partial data framed as complete.

## 3C. Schema Context & Guardrails

- `AIINSIGHT.schema_annotations` — table/column → human description, auto-drafted once from
  `information_schema` plus a one-time LLM pass, then hand-edited by the tenant admin or
  Simon. This is what keeps the model from guessing what `amt_net` means, the same problem
  schema-documentation tools like Contextflo solve — solved once, centrally, instead of per
  query.
- `AIINSIGHT.query_audit` — every executed SQL statement, tenant, user, timestamp, row count,
  latency. This is both the safety net (§3A) and a usage-cost dataset for tuning the
  Haiku/Sonnet escalation rule (§3B).
- **Denylist/allowlist of schemas the tool can touch, per tenant** — e.g. a tenant that has
  never vetted `CUSTOMFIELDS` raw storage for sensitive content can exclude it entirely,
  overridable per tenant, never a platform-wide assumption.

**Rules / logic**
- A schema/table with no `schema_annotations` entry is still queryable (the model falls back
  to raw column names) but is flagged in the query audit as "unannotated" — a visibility
  signal for Simon to prioritize documentation work, not a hard block.

## 3D. Plan / Entitlement Gating & Usage Dashboard

- Ties into the platform's existing plan/feature-flag mechanism (`CLAUDE.md` §4:
  `tenants.plan` + `config/tenant_modules.php` + `TenantFeatureService` + `module:CODE`
  middleware) — "AI Insights" is an add-on SKU on top of a tenant's base plan, not a
  separately-built gating mechanism.
- `AIINSIGHT.usage_counters` — rolling monthly token/query counts per tenant, checked before
  every `ask()` call; a tenant over budget gets a clear in-chat message (per `DESIGN.md` voice:
  *"You've reached this month's AI Insights query limit. It resets on [date], or you can
  request a higher limit from your admin."*) rather than a silent failure.
- **Admin-facing usage view**: current-month usage vs. budget, a simple trend (queries/tokens
  per week), and the tenant's `schema_annotations` coverage — surfaced to a tenant admin, not
  every user, mirroring the "admin sees more than a general user" posture Payroll
  (§3-Admin, `PAYROLL_SPECS.md`) and HCM (§3H) already establish for sensitive/cost-relevant
  data.

**Rules / logic**
- Budget enforcement happens before the Claude API call, not after — a request that would
  exceed budget is rejected up front, never partially executed and billed anyway.

## 3E. Vertical Prompt-Context Extension Point

**Purpose:** let a vertical module (Legal today; Property later) teach AIInsights its
vocabulary and framing — "a 'matter' is a client engagement," "a 'deed' becomes immutable once
signed" — without AIInsights ever having Legal-specific code, the same driver-interface
pattern already established platform-wide (`ChannelDriverInterface` in WNE,
`ConferenceDriverInterface` in Schedule, `OcrDriverInterface` in DMS,
`CostingStrategyInterface` in Inventory).

- `VerticalPromptContextInterface`: `getContextFragment(tenantId): string` — a vertical module
  registers an implementation (e.g. `LegalPromptContextProvider`) that returns a short block of
  domain vocabulary/framing text, appended to the system prompt (§3B) alongside the schema
  context (§3C) whenever that vertical is enabled for the tenant.
- Registration is additive and optional — a tenant with no vertical module enabled (Core
  modules only) gets AIInsights with zero vertical framing, and it still works correctly
  against Core schemas (CRM, Accounting, HCM, ...).
- AIInsights has zero compile-time dependency on any Vertical module's classes — it only knows
  about the `VerticalPromptContextInterface` contract, resolved via the tenant's enabled
  modules (`config/tenant_modules.php`), the same Core-has-zero-knowledge-of-Vertical
  discipline `CLAUDE.md` §2 requires everywhere else.

**Rules / logic**
- A context fragment is plain text, not executable — it cannot expand the model's tool access,
  denylist, or row limits; it only shapes how the model *interprets* questions and results,
  keeping the security boundary (§3B/§3C) entirely outside the vertical's control.

# 4. Storage

**Database (schema `AIINSIGHT`, tenant DB — consistent with `CLAUDE.md` §7A):**

- `AIINSIGHT.conversations` — per user, per tenant.
- `AIINSIGHT.messages` — per conversation; assistant turns store the executed SQL reference
  (`query_audit_id`) alongside the rendered answer.
- `AIINSIGHT.query_audit` — append-only; every executed SQL statement, tenant, user,
  timestamp, row count, latency. No update/delete at the app layer, same audit-integrity rule
  as `DMS.access_logs`.
- `AIINSIGHT.schema_annotations` — table/column → human description, tenant-editable.
- `AIINSIGHT.usage_counters` — rolling monthly token/query counts per tenant, for entitlement
  gating (§3D) and cost tracking.
- `AIINSIGHT.schema_access_rules` — per-tenant schema/table denylist or allowlist (§3C).

**Object file storage:** none required for MVP — this module produces text answers and
tabular results, not documents. If a future "export this analysis as PDF/Excel" feature ships,
it reuses the existing `pdf`/`xlsx` skill patterns and, if the export needs to persist,
**DMS**'s attachment facade — no parallel storage code, same reuse discipline as every other
module in this platform.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Core module, same monolithic-modular posture as WNE/DMS/CRM/
Schedule, at `app/Modules/AIInsight/`. No microservice extraction — this is a thin
orchestration layer around the Claude API's Messages endpoint (tool use), not a different
runtime or independent-scaling workload per `CLAUDE.md` §2's extraction criteria. The Claude
API call itself is the only "heavy" external dependency, and it's already an HTTP call, not a
compute workload this platform needs to host.

- **Internal facade** — `AIInsightsService::ask($tenantUser, $question)` — the only supported
  integration point; no other module calls the Claude API directly for tenant-data querying.
- **Consumes** the platform's plan/feature-flag mechanism (`CLAUDE.md` §4) for entitlement
  gating (§3D), and each enabled vertical's `VerticalPromptContextInterface` implementation
  (§3E) for domain framing — both read-only, optional dependencies; AIInsights functions
  (against Core schemas only) even with zero verticals installed.
- **Model usage:** Claude API Messages endpoint + tool use (`run_readonly_query`); Haiku
  default, Sonnet escalation for complex queries; prompt caching on schema context for cost
  efficiency (§3B). No fine-tuning, no persistent per-tenant model state — every call is
  stateless from the model's perspective, with all context (schema, conversation history,
  vertical framing) assembled fresh per request.

**Zero Data Retention — the one non-negotiable prerequisite for production launch.** This
module sends tenant data (schema context, and whatever a query result surfaces) to the Claude
API on every turn. For any tenant with confidentiality obligations — legal-vertical tenants
first and foremost, given attorney-client privilege — this module **must not be enabled in
production** until a Zero Data Retention agreement with Anthropic is confirmed in place for
the tenant's API usage. This is a go-live gate enforced at the feature-flag level
(`config/tenant_modules.php`), not a configuration a tenant admin can self-service around, and
it is the reason this module is listed as "on the horizon" rather than shippable today (see
the project's own "on the horizon" notes). Other specs that reference "the same ZDR
requirement noted [for] AIInsights" (`PURCHASE_SPECS.md` §3L, `INVENTORY_SPECS.md` §2
Optimization tier) are pointing back to this paragraph — this is the section that actually
defines it, and it should not be considered satisfied by anything short of a confirmed
agreement.

**MVP scope boundary (be explicit about what's deferred):**
- No write capability now or ever (§2) — the one permanent boundary in this module, unlike
  every other MVP-vs-Future-Version split in this platform.
- `schema_annotations` is a one-time auto-draft + hand-edit in v1, not a scheduled refresh job
  — acceptable because schema changes are infrequent and deliberate (migrations), not
  something that drifts silently.
- No proactive/scheduled delivery (§2) — every insight in v1 is pulled by the user asking a
  question, never pushed.

**Suggested build order for Claude Code:** 3C (`schema_annotations` + `query_audit` schema —
the guardrail data model everything else depends on) → 3B (Query Execution Engine against a
single test tenant, with row-limit/timeout enforced at the DB-role level from day one, not
added later) → 3A (chat interface) → 3D (entitlement gating, wired into the existing plan/
feature-flag mechanism) → 3E (`VerticalPromptContextInterface`, with Legal as the first real
implementation) → **confirm the ZDR agreement is in place** → ship to the first tenant.

**Marketability notes**
- "Ask your data" is a strong, demoable differentiator for a conservative buyer once trust is
  established — the audit trail (§3A/§3C) and the ZDR posture above are what make it credible
  to lead with in a legal-vertical sales conversation, not just a novelty feature.
- Because every other module's own Future Version section (Purchase, Inventory, HCM,
  Performance) already points at this module as the place their AI features will eventually
  live, AIInsights is a platform-wide leverage point — one investment here pays off across
  every module that currently has an "AI-assisted X" item deferred to Future Version.
- The read-only, permanently-no-write boundary (§2) is itself a selling point for a
  risk-averse buyer, not just an engineering safety measure — worth stating explicitly in
  sales conversations ("the AI can answer questions about your data; it can never change
  anything").
