# DMS Module
## Document Management System — Core Shared Module (also usable standalone)

# 1. Backgrounds

> Pain point and business value.

Almost every module in the ERP eventually needs to attach, store, and retrieve documents:
Purchasing needs vendor quotes and signed POs, HR needs contracts and ID scans, and — most
urgently, since **Legal is the first paying vertical** — Legal needs case files, filings,
contracts, and correspondence with strict confidentiality and retention requirements. Left
unsolved centrally, this repeats the exact anti-pattern the WNE module was built to avoid:

- Each module invents its own upload/storage code — inconsistent, not reusable, no shared
  versioning or audit trail.
- No central place to search "which document mentions X" across modules.
- No consistent retention/legal-hold behavior — a real compliance risk for a Legal-vertical
  product, where destroying (or failing to destroy) a document on schedule has legal
  consequences.
- No reusable preview/version history UI — every module would build its own.
- Confidentiality needs (a case document should not be visible outside the case team) have no
  common enforcement point.

**Client requirements:**
- Multi-tenant aware, storage isolated per tenant (already decided: Cloudflare R2,
  tenant-prefixed keys — see `CLAUDE.md` §7B).
- Any module can attach documents to its own records **without knowing storage internals**,
  via a facade/event, same pattern as WNE.
- Must also work **standalone** — a tenant can use DMS as a plain document library (folders,
  upload, search) with nothing else installed, since it's sellable as its own line item.
- Version control is mandatory — legal documents get amended; nothing should be silently
  overwritten.
- Full audit trail — who uploaded/viewed/downloaded/edited/deleted, and when (legal
  discoverability and confidentiality proof).
- Retention rules must be configurable per tenant/doc type, with a **legal hold** override that
  blocks deletion regardless of schedule.
- Documents should be findable by more than exact filename — full-text search on content is
  expected; semantic/AI search and OCR are desirable but **not required for launch**.

# 2. Goals

> Designated features. MVP-first — ship something sellable fast, defer heavy AI/infra work.

**MVP (ship with Legal vertical launch)**
- **Centralized, decoupled storage service.** Other modules integrate via `DocumentService`
  facade (`attach()`, `upload()`, `getVersions()`, `search()`) or a `DocumentUploaded` /
  `DocumentAttachRequested` event — same seam pattern as WNE (see `WNE_SPECS.md` §4).
- **Polymorphic attachment** — any module record can have N documents (`subject_type` +
  `subject_id`), plus documents can exist with no owning module at all (standalone library use).
- **Folder/category tree** for standalone browsing, with a simple per-folder access flag
  (private / team / tenant-wide) — good enough for launch-grade confidentiality, without
  building a full RBAC engine.
- **Metadata management** — a base metadata set (title, description, doc type, effective/expiry
  date) plus tenant-defined custom fields, reusing the existing `CUSTOMFIELDS` schema pattern
  rather than inventing a second one.
- **Version control** — every re-upload creates a new immutable version; nothing is ever
  overwritten in object storage; current version pointer + full history.
- **Document lifecycle** — `draft → active → archived → expired → purged`, admin/system driven.
- **Audit trail** — immutable log of upload / view / download / metadata-edit / version /
  restore / delete / permission-change.
- **Retention management** — per tenant/doc-type retention policy (period + action on expiry:
  notify, archive, or delete) with a **legal hold** flag that overrides any scheduled action.
  Reuses **WNE** for the "document expiring, please review" notification — no separate
  notification code needed.
- **Basic object relation** — link documents to each other (`amendment_of`, `supersedes`,
  `attachment_of`, `related_to`) as a simple lookup table.
- **Keyword/full-text search** — PostgreSQL native full-text search (`tsvector`) over filename,
  description, tags, and metadata. No AI required.

**Future Version (post-launch, once there's real usage volume/revenue to justify the build)**
- **Intelligent OCR** — extract text from scanned/image documents. Naturally a candidate for
  extraction per `CLAUDE.md` §2 (heavy async processing, different runtime fits better — e.g.
  Python + Tesseract/cloud OCR API), not a monolith concern.
- **Semantic search** — embeddings + `pgvector`, hybrid keyword+semantic ranking. Depends on
  OCR text existing first.
- **Automatic tagging** — LLM classifies a document's type/tags from its extracted text, with a
  human review queue before tags are auto-applied.
- **Fine-grained ACL / sharing** — per-document explicit user/role grants, external share links
  with expiry — beyond the MVP's folder-level flag.
- **Relation graph visualization** — visual view of how documents connect.
- **Deduplication UX** — checksum is captured from day one (needed for integrity anyway); a
  "this file already exists, link instead?" prompt is future polish.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Main Dashboard (Document Library)

**Function / features**
- Folder tree + list/grid of documents. Filters: owning module, doc type, tag, status, date
  range, "expiring soon" / "on legal hold".
- Quick preview panel (PDF/image inline; other types show metadata + download).
- Upload button (single or bulk), drag-and-drop.

**Layout**
- Left: folder tree (standalone mode) or "Attached to this record" list (embedded mode, e.g.
  inside a Legal case page).
- Main: Data table (per `DESIGN.md` component inventory) with Status Rail colored by
  lifecycle state (`active` = neutral/success, `expiring soon` = warning, `expired`/`on hold` =
  danger, `archived` = neutral border).
- Row click opens drawer: metadata, version history tab, audit log tab, relations tab.

**Rules / logic**
- Tenant-scoped by default global scope, same as WNE.
- A document embedded inside a module record (e.g. Legal case) is always also visible from the
  standalone library, filtered by "owning module = Legal" — one store, two views.
- Folder access flag (`private` / `team` / `tenant`) is enforced at query time, not just UI-hidden.

## 3B. Document Entry (Upload / Edit)

- Fields: file, folder, `doc_type`, title, description, tags (free-tag MVP, controlled vocabulary
  later), custom metadata (per `doc_type`, via CUSTOMFIELDS), owning module reference
  (`subject_type` / `subject_id`, optional), effective date, expiry date, retention policy
  (defaults from `doc_type`, overridable).
- **On upload:** compute SHA-256 checksum, stream to R2 under
  `tenant_{id}/DMS/{module}/{yyyy}/{mm}/{document_uuid}/v{n}.{ext}`, create `documents` row (if
  new) or a new `document_versions` row (if re-upload), fire `DocumentUploaded` event → audit
  log entry. OCR/auto-tag hooks listen on this event but are no-ops until Future Version ships.

## 3C. Version History Viewer

- List of versions (uploader, timestamp, size, checksum, note).
- Actions: download specific version, restore as current, compare metadata between two versions.
- Restoring creates a **new** version pointing at the old file — history is never destructive.

## 3D. Folder / Category Management (standalone use)

- Tree CRUD, per-folder: default `doc_type`, default retention policy, access flag.
- Deleting a non-empty folder requires reassigning or archiving its documents first.

## 3E. Search Engine (MVP: keyword; Future: semantic)

- MVP: Postgres `tsvector` generated column over filename + title + description + tags +
  `extracted_text` (nullable, populated later by OCR). Ranked results, filterable by same facets
  as the dashboard.
- Future: `pgvector` embedding column on `documents`/`document_versions`, hybrid re-ranking,
  "find documents similar to this one."

## 3F. Retention & Lifecycle Engine

- States: `draft → active → archived → expired → purged` (soft-delete first, hard-delete only
  after a configurable grace period).
- `retention_policies`: per tenant × doc_type, `retention_period_days`, `action_on_expiry`
  (`notify_only` / `archive` / `delete`), `legal_hold_overridable` flag.
- Scheduled job (daily) scans documents approaching/at expiry:
  - If `legal_hold = true` on the document → skip entirely, log a "hold prevented action" audit
    entry.
  - Otherwise fires a `WorkflowRequested`/`NotificationRequested` event into **WNE** (reuse, no
    new notification code) so a reviewer confirms before destructive action, or the configured
    action runs automatically for `notify_only`/`archive` policies.

## 3G. OCR & Auto-Tagging Engine — **Future Version**

- Async job dispatches to an external microservice (Python; Tesseract or a cloud OCR API) —
  justified extraction per `CLAUDE.md` §2 (different runtime, heavy async workload), same
  reasoning already applied to the on-prem tenancy gateway.
- Writes `extracted_text` back via callback/webhook, which refreshes the search `tsvector` and
  (once semantic search ships) the embedding.
- Auto-tagging: LLM suggests doc type/tags from `extracted_text`; lands in a review queue, never
  auto-applied silently — keeps confidentiality/accuracy expectations intact for legal docs.

## 3H. Object Relation Engine

- MVP: `document_relations` table — `source_document_id`, `target_document_id`,
  `relation_type` (`version_of` is implicit via `document_versions`; explicit types here are
  `amendment_of`, `supersedes`, `attachment_of`, `related_to`).
- Future: graph visualization of a document's relation network.

## 3I. Audit Trail

- `dms.access_logs`: append-only, one row per action (`upload`, `view`, `download`,
  `edit_metadata`, `version_upload`, `restore`, `delete`, `permission_change`, `hold_applied`,
  `hold_released`), actor, timestamp, IP (optional), document + version reference.
- No update/delete permitted on this table at the app layer — audit integrity for legal
  discoverability.

---

# 4. Storage

**Database (schema `DMS`, tenant DB — consistent with `CLAUDE.md` §7A):**
- `DMS.folders`
- `DMS.doc_types`
- `DMS.documents` (current-version pointer, lifecycle state, owning module ref, folder,
  retention policy ref, legal_hold flag)
- `DMS.document_versions` (immutable, checksum, storage key, size, mime type, uploaded_by)
- `DMS.document_relations`
- `DMS.tags`, `DMS.document_tags`
- `DMS.retention_policies`
- `DMS.access_logs` (audit trail, append-only)
- Custom metadata piggybacks on the existing `CUSTOMFIELDS` schema/mechanism rather than a
  DMS-specific one.

**Object File (per `CLAUDE.md` §7B, already reserves a `DMS/` folder per tenant):**
```text
tenant_001/DMS/
├── {owning_module}/{yyyy}/{mm}/{document_uuid}/
│   ├── v1.{ext}
│   ├── v2.{ext}
│   └── ...
```
- One shared Cloudflare R2 bucket, tenant-prefixed keys — same convention as the rest of the
  platform. Versions are never overwritten or deleted from storage until hard-purge after the
  retention grace period.

# 5. Technical Notes

> All necessary technical detail to help AI Coding

**Architecture pattern:** Monolithic-modular, same shape as WNE.
- **Internal facade** — `DocumentService::upload()`, `::attach()`, `::getVersions()`,
  `::search()`, `::applyRetention()` — for same-process modules (preferred).
- **Internal event bus** — `DocumentUploaded`, `DocumentAttachRequested`,
  `RetentionActionDue` — decouples callers, and is the seam that lets OCR/AI work move out of
  the monolith later without touching any calling module.
- **Cross-module reuse of WNE** for all retention/expiry notifications and any approval step
  (e.g. "approve permanent deletion") — do not build a parallel notification path inside DMS.

**MVP scope boundary (be explicit about what's deferred):**
- `extracted_text` column exists from day one (nullable) so the search infrastructure and
  schema don't need a breaking migration later — it's simply unpopulated until OCR ships.
- No `pgvector`/embeddings in MVP; add as an additive migration when semantic search is built.
- Folder-level access flag is enough for launch; do not build per-document ACL until a client
  actually asks for it — matches the "simpler sellable version first" MVP bias in
  `CLAUDE.md` §10.

**Versioning integrity:** SHA-256 checksum captured on every version, both for future dedupe
and to prove a document hasn't been altered — relevant for legal evidentiary use.

**Extensibility:** OCR/tagging providers register behind the same kind of driver interface
pattern WNE uses for channels (`OcrDriverInterface`) — swapping Tesseract for a cloud API later
is additive, not a rewrite.

**Suggested build order for Claude Code:** 3A/3B/3D (upload + browse) → 3C (versioning) →
3I (audit trail, cheap and high-value) → 3F (retention, wire into WNE) → 3H (relations) →
3E keyword search — ship at this point — then revisit 3G/semantic search as Future Version.
