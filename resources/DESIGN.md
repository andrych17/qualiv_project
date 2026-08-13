# DESIGN.md

Design system reference for Claude Code, Claude Design, and Google Stitch when building any UI in this project. Every new component or screen should be composed from this system, not styled ad hoc. This file is the single source of truth until a real design-tokens package exists in the codebase.

## 1. Design Brief

**Subject:** a multi-tenant SaaS ERP, sold module-by-module to vertical markets. First audience: **legal professionals** (lawyers, paralegals, firm admins) managing cases, deadlines, documents, and client communication.

**Audience needs:** trust, precision, low cognitive load under time pressure, and a tool that feels built *for* their profession rather than a generic dashboard reskinned with their logo. Legal buyers are conservative about software — the UI needs to read as dependable and mature, not trendy.

**The screen's single job, restated per context:** help one person move a piece of work (a case, a task, a notification) forward with the least friction, always showing *where things stand* — because ERPs live and die on status visibility (what's overdue, what's waiting on me, what's done).

This rules out playful/consumer aesthetics and the common AI-generated defaults (warm cream + terracotta; near-black + acid accent; broadsheet hairline-rule layouts). None of those fit a professional, data-dense, trust-first product.

## 2. Design Tokens

### Color — "Ink & Signal"
A near-neutral, low-saturation base (so dense data doesn't fight the UI) with one confident accent reserved for action, and a small semantic set for status — since status communication is the core job of an ERP.

| Token | Hex | Use |
|---|---|---|
| `--color-ink-900` | `#12181F` | Primary text, headings |
| `--color-ink-600` | `#4A5563` | Secondary text, labels |
| `--color-surface-0` | `#FFFFFF` | Cards, panels |
| `--color-surface-50` | `#F4F6F8` | App background |
| `--color-border` | `#DEE3E8` | Hairlines, dividers, table rules |
| `--color-accent` | `#1F5FBF` | Primary action, links, focus ring — a confident, unambiguous blue; deliberately *not* the terracotta/violet AI-default accents |
| `--color-signal-success` | `#1E7F5C` | Completed, on-track, resolved |
| `--color-signal-warning` | `#B5760A` | Due soon, needs attention |
| `--color-signal-danger` | `#C1332B` | Overdue, blocked, error |
| `--color-signal-info` | `#5B4FCF` | System/automated notices (kept distinct from accent so "the app did this" reads differently from "you can act here") |

Dark mode is a later phase — token names are structured (numeric scale) so a dark palette can be added without renaming anything.

### Typography
- **Display / headings:** *Source Serif 4* — a serif carries the gravitas legal users expect (contracts, briefs, letterheads) without tipping into decorative. Used only for H1/H2 and key numbers (dashboard stats), never for UI chrome.
- **UI / body:** *Inter* — neutral, extremely legible at small sizes, the workhorse for tables, forms, nav, buttons.
- **Data / mono:** *IBM Plex Mono* — case numbers, timestamps, IDs, audit logs. Anything the user might need to copy-paste or scan for exact characters.

Type scale (rem, 16px base): `12 / 14 / 16 / 18 / 22 / 28 / 36`. UI text defaults to 14. Body/reading content defaults to 16. Never go below 12.

### Spacing & Layout
- 8px base spacing unit (`4, 8, 12, 16, 24, 32, 48, 64`).
- Border radius: `4px` (inputs, buttons, small chips), `8px` (cards, modals). No fully-rounded "pill" chrome except status badges — rounded pills are reserved *specifically* for status, so their shape becomes meaningful, not decorative.
- Standard app shell: fixed left sidebar (module nav) + top bar (tenant/context switcher, notifications, search) + content area. This shell is shared by every module and every vertical — consistency here is what makes the product feel like one platform rather than bolted-together tools.

### Signature element: **the Status Rail**
A thin vertical color bar (2–3px) on the left edge of any row/card representing a trackable item (a case, a task, a scheduled job, a workflow step), colored by its semantic state (`success` / `warning` / `danger` / `info` / neutral border color if none). It appears identically in the Scheduler, Workflows, Notifications, and Legal case list — it's the one visual motif that ties every module together and directly encodes the product's core value: *always know where things stand at a glance*, scannable down a long list without reading text.

## 3. Component Inventory (build in this order)

Base primitives first, then composites. Each should be a single reusable component consumed everywhere — no per-module reimplementation.

**Primitives**
- Button (primary / secondary / ghost / destructive; with loading state)
- Input, Textarea, Select, Combobox, Date/time picker
- Checkbox, Radio, Toggle
- Status Badge (pill, uses semantic colors above)
- Avatar / initials chip
- Tooltip, Popover

**Composites**
- Data table (sortable, filterable, with row-level Status Rail; optional row expansion via `expandable` + `#row-detail`; optional Excel-style Group/Outline via `groupBy` on `Column[]` with per-column `footer: 'sum'|'avg'|'count'|'min'|'max'` for group subtotals + a pinned grand-total `<tfoot>` — see `resources/js/Components/tables/DataTable.vue`. In `groupBy` mode, `items` is only ever the current server-paginated page, so the grand total reflects that page unless the host passes `footerTotals` computed by the backend across the full result set.)
- Card (with optional Status Rail on the edge)
- Kanban / Board view (drag-to-advance columns by status — see dedicated spec below; used by
  CRM's Lead pipeline, Sales's Opportunity pipeline, and Performance's OKR board)
- Modal / drawer
- Toast / inline notification banner
- Empty state (see writing guidance below — always actionable, never just "no data")
- Tabs, breadcrumb, pagination
- Sidebar nav item (with module icon + optional badge count)
- Calendar / timeline view (for Schedule)
- Comment/activity thread (reusable across Workflows and Legal case notes)

### Kanban / Board View

A stage/status pipeline rendered as columns (one per stage) containing draggable cards — used
wherever a record moves through a linear or near-linear sequence of states before completion:
CRM's Lead pipeline (New → Contacted → Qualified → Converted/Disqualified), Sales's
Opportunity pipeline (New → Qualifying → Quoted → Won/Lost), and Performance's OKR board (by
status: On Track / At Risk / Off Track / Completed).

- **Column header**: stage name, card count, optional aggregate value (e.g. total estimated
  value of Leads in this stage) set in *Source Serif 4*, matching this system's convention of
  reserving the serif for key numbers.
- **Card**: the same Card composite (above), with its **Status Rail** on the left edge colored
  by the record's own semantic state — not the column position. A card that's overdue or
  at-risk stays visually flagged (`danger`/`warning`) even before anyone drags it, so the
  board never hides a problem just because of which column it's sitting in. Card body: title,
  owner avatar/initials chip, and one or two secondary fields (e.g. estimated value + next
  action date for a Lead) — never more. A Kanban card is a scannable summary, not the full
  record.
- **Drag-to-advance**: dragging a card to a new column changes its underlying status field. A
  drop that would violate a hard business rule (e.g. Sales's credit-blocked order, or a CRM
  disqualification that requires a reason code) opens the relevant inline form instead of
  silently completing the move — the drag is a shortcut for a valid transition, never a
  bypass of whatever rule would otherwise gate that change.
- **List view toggle**: every Kanban board has an equivalent sortable list/table view (reusing
  the Data table composite) for users who prefer it or are on a narrower viewport — Kanban is
  a view over the records, not the only way to interact with them, consistent with the
  Quality Floor's "responsive down to a usable mobile width" requirement (§6).
- **Empty column state** follows the same actionable-invitation voice as any other empty state
  (§5) — e.g. *"No leads in Qualified yet — drag one here or add a new lead,"* never a bare
  "No data."

## 4. Motion

Minimal and functional only: state transitions (hover, focus, expand/collapse), toast enter/exit, and a subtle row-highlight when a Status Rail changes color live (e.g. a task becomes overdue in real time). No page-load choreography, no decorative motion — this is a tool used all day; animation that delays the user becomes annoying fast. Respect `prefers-reduced-motion` everywhere.

## 5. Writing & Voice

- Address the user's real action, not the system's mechanism: "Assign case," not "Update case record."
- Buttons and their resulting confirmations share vocabulary: a button that says "Send reminder" produces a toast that says "Reminder sent," never "Notification dispatched."
- Errors state what happened and what to do next, without apologizing or being vague: "This case number already exists. Use a different number or open the existing case."
- Empty states are invitations to act, tailored per module: an empty Scheduler says "No events scheduled — add your first one," not "No data."
- Tone throughout: plain, precise, calm. No exclamation points, no forced friendliness — this mirrors how legal professionals expect their tools to sound.

## 6. Quality Floor (non-negotiable for every component)

- Responsive down to a usable mobile width, even though primary usage is desktop.
- Visible keyboard focus ring using `--color-accent` on every interactive element.
- Sufficient contrast at all text sizes (verify against `--color-surface-0` and `--color-surface-50`).
- `prefers-reduced-motion` respected.
- Every status-bearing element (badge, rail, icon) pairs color with a text label or icon shape — never color alone — for colorblind accessibility.

## 7. Open Items

- [ ] Finalize whether Source Serif 4 / Inter / IBM Plex Mono are locally hosted or loaded via a font CDN, given the VPS/Nginx setup.
- [x] Icon set choice (needs to cover legal-specific iconography: gavel, case file, court date, etc. — likely a general set + a small custom set for Legal).
- [ ] Dark mode palette (deferred until core light-mode components are stable).
- [x] Formalize this into an actual token file (CSS variables / Tailwind config) once the Vue.js component library scaffolding begins. (`resources/css/app.css` + `tailwind.config.js`)
