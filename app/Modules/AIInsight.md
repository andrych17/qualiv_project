# AIInsights (AII) Module 
Structure following the same shape as WNE (Core, shared, event-driven facade):

## Classification: Core module (per CLAUDE.md §2/§5) 

# 1. Backgrounds
every vertical benefits from "ask your data," and it has zero vertical-specific logic. 

# 2. Goals
Legal-specific phrasing (e.g., understanding "case" terminology) can live in a thin per-vertical prompt-context layer that AIInsights consumes, not the other way around.

# 3. Forms / Engines

## 3A. Main Interaction (the chat itself)

Single chat-style interface, tenant-scoped, reusing DESIGN.md's Card/Data table primitives for rendering results (a query result becomes a Data table with Status Rail if it's status-bearing data, otherwise a plain table or chart).
Conversation history persisted per user (not just per tenant) — aii.conversations, aii.messages — so a paralegal's question thread doesn't leak into a partner's.
Each assistant turn that ran a query stores the actual SQL executed alongside the answer, for audit/debugging — this matters a lot once you're letting an LLM touch tenant data unsupervised.

## 3B. Query Execution Engine

AIInsightsService::ask($tenantUser, $question) → the facade any UI calls.
Internally: builds a system prompt with schema context (table/column descriptions — you'll want a lightweight aii.schema_annotations table so Claude isn't guessing what amt_net means, same problem the Contextflo-style tools solve), calls Claude API with a run_readonly_query tool.
Tool executes against a per-tenant read-only DB role, with a hard row-limit (e.g. LIMIT 500) and statement timeout injected server-side — never trust the model to self-limit.
No write tool exposed at all in v1, matching your "read-only is fine" decision.

## 3C. Schema Context & Guardrails

aii.schema_annotations — table/column → human description, maintained by you (or auto-drafted once from information_schema + a one-time LLM pass, then hand-edited).
aii.query_audit — every executed SQL statement, tenant, user, timestamp, row count, latency. This is your safety net and also a usage-cost dataset.
Denylist/allowlist of schemas the tool can touch per tenant (e.g. never CUSTOMFIELDS raw storage if it contains anything sensitive you haven't vetted).

## 3D. Plan/Entitlement Gating

Ties into your existing plan/feature-flag concept (CLAUDE.md §4) — "AI Insights" as an add-on SKU, with a per-tenant monthly token/query budget enforced in Core, not just a marketing toggle. This is also your cost control lever (below).

## 4. Tables
- aii.conversations
- aii.messages
- aii.query_audit
- aii.schema_annotations
- aii.usage_counters (rolling monthly token/query counts per tenant, for entitlement + billing).