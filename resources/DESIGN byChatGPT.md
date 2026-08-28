# DESIGN.md

Design system reference for Claude Code, Claude Design, and Google
Stitch when building any UI in this project. Every new component or
screen should be composed from this system, not styled ad hoc. This file
is the single source of truth until a real design-tokens package exists
in the codebase.

## 1. Design Brief

**Product:** NET SaaS ERP Plus --- a modular, multi-tenant business
operating system sold module-by-module to vertical markets.

**First audience:** legal professionals --- lawyers, paralegals, and
firm administrators managing cases, deadlines, documents, billing,
tasks, and client communication.

**Design character:** **calm, precise, premium, and operational.**

NET must feel mature and dependable without looking like legacy
enterprise software. It should be information-dense enough for daily ERP
work, but visually composed enough that users immediately perceive it as
a modern product.

### Visual principles

1.  **Hierarchy over decoration.** Visual emphasis should tell users
    what matters now.
2.  **Layered surfaces over boxes.** Use tonal contrast and spacing
    before borders and shadows.
3.  **Signal, don't shout.** Color is primarily for actions, state, and
    attention.
4.  **Dense but breathable.** ERP users need information density;
    density must come from structure, not cramped controls.
5.  **One platform, many verticals.** The shell and interaction language
    remain consistent while each vertical can expose domain-specific
    objects and terminology.
6.  **Professional, not sterile.** Subtle warmth, depth, typography, and
    interaction states prevent the interface from feeling like a
    spreadsheet skin.
7.  **Fast perception.** A user should understand status, ownership,
    next action, and urgency without opening the record.

### What NET should avoid

-   Generic Bootstrap/admin-dashboard appearance
-   Every element surrounded by a border
-   Excessive gray-on-gray hierarchy
-   Large colored cards used only for decoration
-   Excessive rounded corners
-   Gradient-heavy or glassmorphism UI
-   Oversized empty dashboard cards
-   Excessive animation
-   AI-style purple/black visual language
-   Consumer-app playfulness

------------------------------------------------------------------------

## 2. Visual Identity --- "Ink & Signal"

The existing "Ink & Signal" concept remains the foundation, but the
system now uses **tonal layers, an accent scale, and restrained
chromatic surfaces** so the UI does not become visually flat.

### Core palette

  -------------------------------------------------------------------------
  Token                     Hex                     Use
  ------------------------- ----------------------- -----------------------
  `--color-ink-950`         `#0E141B`               Strongest headings /
                                                    high-emphasis text

  `--color-ink-900`         `#12181F`               Primary text

  `--color-ink-700`         `#34404D`               Strong secondary text

  `--color-ink-600`         `#4A5563`               Secondary text / labels

  `--color-ink-500`         `#667382`               Muted metadata

  `--color-ink-400`         `#8995A2`               Placeholder / tertiary
                                                    text

  `--color-surface-0`       `#FFFFFF`               Primary content
                                                    surfaces

  `--color-surface-25`      `#FBFCFD`               Raised / inset content

  `--color-surface-50`      `#F5F7F9`               Application background

  `--color-surface-100`     `#EEF1F4`               Hover / selected
                                                    neutral surface

  `--color-surface-150`     `#E8ECF0`               Stronger neutral
                                                    grouping

  `--color-border-subtle`   `#E8ECF0`               Low-emphasis dividers

  `--color-border`          `#D8DEE5`               Standard borders

  `--color-border-strong`   `#C4CCD5`               Focused/selected
                                                    structural boundaries
  -------------------------------------------------------------------------

### Brand accent

The existing blue remains the NET brand color. Do not replace it; give
it a usable scale.

  Token                  Hex         Use
  ---------------------- ----------- ---------------------------------
  `--color-accent-50`    `#EFF6FF`   Soft selected/action background
  `--color-accent-100`   `#DCEBFF`   Hover/selected surface
  `--color-accent-200`   `#BFD8FF`   Soft emphasis
  `--color-accent-400`   `#4C82D2`   Secondary accent
  `--color-accent-500`   `#1F5FBF`   Primary action / links
  `--color-accent-600`   `#174D9E`   Hover/pressed
  `--color-accent-700`   `#123D7F`   Strong active state

**Rule:** `accent-50/100` should provide most of the visual color in
selected states. Reserve `accent-500+` for actual controls and emphasis.

### Semantic signals

Keep the existing semantic meanings, but give each signal a soft
background and foreground pair.

  Signal    Foreground   Soft background   Meaning
  --------- ------------ ----------------- -------------------------------
  Success   `#18704F`    `#EAF7F1`         Complete / healthy / on track
  Warning   `#9A6200`    `#FFF6DF`         Due soon / attention
  Danger    `#B52B25`    `#FDEDEC`         Overdue / blocked / error
  Info      `#5145B5`    `#F0EEFF`         Automated/system information
  Neutral   `#596573`    `#F0F2F4`         Informational / inactive

Status colors must always be paired with text or an icon.

------------------------------------------------------------------------

## 3. Surfaces, Depth & Shape

NET should feel layered rather than flat.

### Surface hierarchy

``` text
Application
└── Page surface
    ├── Content surface
    ├── Inset surface
    └── Raised surface
```

Recommended mapping:

-   `surface-50`: application/page background
-   `surface-0`: cards, tables, forms, detail panels
-   `surface-25`: nested sections and subtle raised content
-   `surface-100`: hover and selected neutral state
-   `surface-150`: grouped controls / stronger separation

### Borders

Do not put borders around every visual group.

Prefer this order:

1.  whitespace
2.  tonal surface change
3.  subtle divider
4.  standard border
5.  shadow

Use borders primarily where users need a clear interaction boundary.

### Elevation

Use shadows only for elements physically above the page:

-   dropdowns
-   popovers
-   command palette
-   modals
-   drawers
-   floating actions

Cards should normally rely on **surface + border**, not a permanent
shadow.

### Radius

  Token               Value Use
  ----------------- ------- ---------------------------------
  `--radius-xs`         4px inputs, compact controls
  `--radius-sm`         6px buttons, badges, table controls
  `--radius-md`         8px cards, panels
  `--radius-lg`        12px major containers / dialogs
  `--radius-full`     999px status badges, avatars only

Avoid decorative rounded containers.

------------------------------------------------------------------------

## 4. Typography

### Families

-   **UI / body:** Inter
-   **Display / editorial:** Source Serif 4
-   **Data / exact identifiers:** IBM Plex Mono

### Important adjustment

Source Serif 4 is reserved for **page titles, major section titles, and
selected editorial/key-value emphasis**. It should not be used broadly
for dashboard numbers or normal application UI.

This prevents the legal aesthetic from making the ERP feel like a
document-management application.

### Type scale

  Token           Size   Line height     Weight
  ------------- ------ ------------- ----------
  `text-xs`       12px          16px   400--600
  `text-sm`       13px          18px   400--600
  `text-base`     14px          20px   400--600
  `text-lg`       16px          24px   500--600
  `text-xl`       18px          26px        600
  `text-2xl`      22px          30px        600
  `text-3xl`      28px          34px        600
  `text-4xl`      36px          42px        600

ERP UI defaults to **14px**.

### Typography hierarchy

A typical detail page should read:

``` text
Page title             Source Serif 4 / 28px / 600
Supporting metadata    Inter / 13px / 400
Section title          Inter / 16px / 600
Field label            Inter / 12px / 500
Field value             Inter / 14px / 500
Exact identifier       IBM Plex Mono / 13px
```

Do not use bold weight as the only hierarchy mechanism. Use size,
spacing, color, and grouping together.

------------------------------------------------------------------------

## 5. Spacing & Density

Use an 8px base rhythm with 4px as the compact unit:

`4 / 8 / 12 / 16 / 24 / 32 / 48 / 64`

### Density modes

ERP users have different preferences. Components should support:

-   **Comfortable:** default forms and general screens
-   **Compact:** data-heavy tables and operational screens

Do not create separate component designs for each mode. Density changes
padding, row height, and gaps while preserving typography and hierarchy.

### Recommended defaults

  Element          Comfortable    Compact
  -------------- ------------- ----------
  Table row           44--48px   36--40px
  Input                   40px       36px
  Button                  40px       36px
  Card padding        20--24px       16px
  Section gap             24px       16px

------------------------------------------------------------------------

## 6. Application Shell

The application shell is one of NET's strongest opportunities for visual
differentiation.

### Structure

``` text
┌─────────────────────────────────────────────────────────────┐
│ Tenant / Context        Search              Alerts  User    │
├───────────────┬─────────────────────────────────────────────┤
│               │                                             │
│ NET           │ Page title                    Primary action │
│               │                                             │
│ Home          │ Content                                     │
│               │                                             │
│ BUSINESS      │                                             │
│ Sales         │                                             │
│ Purchasing    │                                             │
│ Inventory     │                                             │
│ Accounting    │                                             │
│               │                                             │
│ PEOPLE        │                                             │
│ HR            │                                             │
│               │                                             │
│ VERTICAL      │                                             │
│ Legal         │                                             │
│ Property      │                                             │
│               │                                             │
│ Settings      │                                             │
└───────────────┴─────────────────────────────────────────────┘
```

### Sidebar

The sidebar should use:

-   clear section labels
-   32--36px navigation rows
-   icon + label
-   subtle `accent-50` active background
-   a small active indicator where useful
-   badge counts only for actionable information
-   collapsible mode for wider screens

Do not make every module a colorful icon.

### Top bar

The top bar should prioritize:

1.  tenant/context
2.  global search / command palette
3.  notifications
4.  user menu

Avoid filling it with secondary actions.

------------------------------------------------------------------------

## 7. Signature Element --- Status Rail

The Status Rail remains a NET signature.

A 2--3px vertical rail appears on the left edge of trackable records.

Use it for:

-   cases
-   tasks
-   notifications
-   workflow records
-   scheduled items
-   Kanban cards
-   actionable dashboard lists

### Important restraint

Do **not** put Status Rails on every card.

The rail means:

> "This item has an operational state worth scanning."

Decorative use weakens its meaning.

------------------------------------------------------------------------

## 8. Component Visual Rules

### Buttons

Primary:

-   solid accent
-   6px radius
-   36--40px height
-   semibold label
-   restrained shadow only when appropriate

Secondary:

-   neutral surface
-   standard border

Ghost:

-   no permanent border
-   hover surface

Destructive:

-   danger semantic color

Every button must have:

-   hover
-   pressed
-   focus
-   disabled
-   loading

### Inputs

Inputs should feel like **fields**, not cards.

-   white/surface background
-   1px border
-   6px radius
-   40px default height
-   visible focus ring
-   label above field
-   helper/error text below

Avoid floating labels unless there is a strong space constraint.

### Cards

A card should have a clear reason to exist.

Preferred:

``` text
┌──────────────────────────────────────┐
│ Revenue                    This month│
│                                      │
│ Rp 124.6M                            │
│ ↑ 12.4%                              │
│                                      │
│ ────────────────╱╲──────             │
└──────────────────────────────────────┘
```

Avoid cards that contain only a title and a single number with excessive
empty space.

### Status Badge

Status badges are the main exception to the no-pill rule.

Use:

-   soft semantic background
-   semantic foreground
-   short label
-   optional icon

Example:

`● Paid`

not a large colored capsule.

------------------------------------------------------------------------

## 9. Data Table

The Data Table is a core NET component and should be treated as a
product-level component, not merely a generic Vue table.

### Visual priorities

1.  primary record identifier
2.  operational status
3.  important numeric/date information
4.  secondary metadata
5.  row actions

### Table appearance

-   header uses subtle `surface-50`/`surface-100`
-   avoid heavy grid lines
-   use horizontal dividers only where useful
-   44--48px comfortable rows
-   36--40px compact rows
-   hover uses `surface-50`
-   selected row uses `accent-50`
-   Status Rail remains visible
-   primary field uses `font-weight: 500–600`
-   metadata uses `ink-500`

### Existing advanced behavior remains required

-   sortable
-   filterable
-   server pagination
-   saved views
-   column visibility
-   column resizing where useful
-   row expansion
-   grouping
-   group subtotals
-   grand totals
-   bulk actions
-   export
-   keyboard navigation

------------------------------------------------------------------------

## 10. Detail Pages

Detail pages should use a consistent hierarchy.

``` text
Page header
├── Breadcrumb
├── Title
├── Status
├── Metadata
└── Primary actions

Main content
├── Overview
├── Domain information
├── Related records
└── Activity

Secondary rail
├── Next action
├── Owner
├── Dates
└── Quick actions
```

### Key principle

A detail page should answer these questions immediately:

1.  What is this?
2.  What state is it in?
3.  Who owns it?
4.  What happens next?
5.  What changed recently?

------------------------------------------------------------------------

## 11. Dashboard

Dashboards should prioritize **actionable operational information**, not
decorative KPI tiles.

Preferred structure:

``` text
Page title
Short contextual sentence

┌────────────┬────────────┬────────────┬────────────┐
│ Revenue    │ Receivable │ Cash       │ Open work  │
└────────────┴────────────┴────────────┴────────────┘

Needs attention
─────────────────────────────────────────────────────
● Invoice overdue
● Contract expires soon
● Approval waiting
● Task due today

My work                         Recent activity
──────────────                  ────────────────
Tasks                           ...
Calendar                        ...
Approvals                       ...
```

A dashboard should answer:

> **What requires attention today?**

before:

> **What are the company's statistics?**

------------------------------------------------------------------------

## 12. Kanban / Board View

A stage/status pipeline is rendered as columns containing draggable
cards.

Existing behavior remains:

-   CRM Lead pipeline
-   Sales Opportunity pipeline
-   Performance OKR board
-   equivalent list/table view
-   drag-to-advance
-   business-rule validation
-   actionable empty states

### Visual refinement

Columns should use subtle tonal separation rather than strong borders.

Cards should remain visually light.

The **Status Rail**, not the card color, communicates record state.

This allows an overdue record to remain visibly overdue even when it
sits in a normal workflow column.

------------------------------------------------------------------------

## 13. Global Search & Command Palette

Global search should become a signature NET interaction.

`Ctrl/Cmd + K`

Search across:

-   customers
-   contacts
-   invoices
-   sales
-   purchasing
-   properties
-   legal matters
-   documents
-   tasks
-   calendar
-   settings

Search results should be grouped by object type.

The command palette should support both:

**Find**

`PT Maju Jaya`

and eventually:

**Act**

`Create invoice for PT Maju Jaya`

Keyboard-first interaction is strongly preferred for power users.

------------------------------------------------------------------------

## 14. Activity Timeline

Every major business object should support a reusable Activity Timeline.

Example:

``` text
27 Aug  10:32
Invoice created

27 Aug  10:35
Invoice sent to customer

28 Aug  09:14
Customer opened invoice

30 Aug  14:20
Payment received
```

The timeline should distinguish:

-   user actions
-   automated system actions
-   comments
-   status changes
-   documents
-   communications

System-generated events should use the Info semantic treatment rather
than the primary brand accent.

------------------------------------------------------------------------

## 15. Motion

Minimal and functional.

Allowed:

-   hover/focus transitions
-   expand/collapse
-   drawer/modal entrance
-   toast entrance/exit
-   drag feedback
-   live Status Rail change
-   subtle selection transitions

Default duration:

-   micro interaction: 120--160ms
-   component transition: 160--200ms
-   modal/drawer: 180--240ms

No page-load choreography.

Always respect `prefers-reduced-motion`.

------------------------------------------------------------------------

## 16. Empty, Loading, Error & Success States

### Empty

Never show only:

`No data`

Instead:

``` text
No invoices yet

Create your first invoice to start tracking
customer payments.

[ Create invoice ]
```

### Loading

Use skeletons matching the final layout.

Avoid full-screen spinners unless the whole application is blocked.

### Error

State:

1.  what happened
2.  why it matters
3.  what the user can do

Example:

`This case number already exists. Use a different number or open the existing case.`

### Success

Keep confirmations short and use the same vocabulary as the triggering
action.

`Reminder sent`

not:

`Notification dispatched successfully.`

------------------------------------------------------------------------

## 17. Vertical Identity

NET Core must remain visually consistent across vertical modules.

Vertical modules may differentiate themselves through:

-   terminology
-   navigation
-   domain-specific objects
-   contextual dashboards
-   specialized icons
-   document templates
-   workflows

They should **not** create unrelated visual systems.

Example:

``` text
NET Core
│
├── NET ERP
│   ├── Sales
│   ├── Purchasing
│   ├── Inventory
│   └── Accounting
│
├── NET People
│   ├── Employees
│   ├── Attendance
│   └── Leave
│
├── NET Legal
│   ├── Matters
│   ├── Parties
│   ├── Hearings
│   └── Documents
│
└── NET Property
    ├── Properties
    ├── Units
    ├── Tenants
    └── Leases
```

The shell, typography, surfaces, controls, table behavior, Status Rail,
and interaction patterns remain shared.

------------------------------------------------------------------------

## 18. Accessibility

Non-negotiable:

-   visible keyboard focus using `--color-accent-500`
-   WCAG-compliant text contrast
-   semantic HTML
-   keyboard operation for all actions
-   status conveyed by text/icon as well as color
-   reduced motion support
-   usable at narrow viewport widths
-   focus order must follow visual/semantic order

------------------------------------------------------------------------

## 19. Implementation Tokens

The visual system must be represented as semantic tokens, not repeated
literal colors.

Example:

``` css
:root {
  --color-page: var(--color-surface-50);
  --color-surface: var(--color-surface-0);
  --color-surface-inset: var(--color-surface-25);
  --color-surface-hover: var(--color-surface-100);

  --color-text-primary: var(--color-ink-900);
  --color-text-secondary: var(--color-ink-600);
  --color-text-muted: var(--color-ink-500);

  --color-action: var(--color-accent-500);
  --color-action-hover: var(--color-accent-600);
  --color-action-soft: var(--color-accent-50);

  --color-border-default: var(--color-border);
  --color-border-subtle: var(--color-border-subtle);
}
```

Components should consume semantic tokens wherever possible.

Do not write:

``` css
background: #F5F7F9;
```

inside individual components when a semantic token exists.

------------------------------------------------------------------------

## 20. Design Review Checklist

Before considering a screen complete:

### Visual hierarchy

-   [ ] Is the primary action obvious?
-   [ ] Can the user identify status immediately?
-   [ ] Are primary fields visually stronger than metadata?
-   [ ] Is there enough tonal variation to avoid a flat appearance?
-   [ ] Are borders used only where they improve comprehension?

### Density

-   [ ] Is information density appropriate for the task?
-   [ ] Is whitespace intentional rather than excessive?
-   [ ] Does the screen work in compact mode where appropriate?

### Consistency

-   [ ] Uses existing NET components
-   [ ] Uses semantic tokens
-   [ ] Uses the shared shell
-   [ ] Uses the Status Rail only where operational state matters
-   [ ] Uses shared interaction patterns

### Polish

-   [ ] Hover state
-   [ ] Focus state
-   [ ] Pressed state
-   [ ] Disabled state
-   [ ] Loading state
-   [ ] Empty state
-   [ ] Error state
-   [ ] Responsive behavior
-   [ ] Reduced-motion behavior

------------------------------------------------------------------------

## 21. Open Items

-   [ ] Finalize whether Source Serif 4 / Inter / IBM Plex Mono are
    locally hosted or loaded via a font CDN.
-   [x] Icon set choice.
-   [ ] Dark mode palette.
-   [x] Formalize tokens in `resources/css/app.css` +
    `tailwind.config.js`.
-   [ ] Define density tokens and compact mode.
-   [ ] Define component states as reusable variants.
-   [ ] Create a NET visual reference page containing: shell, dashboard,
    table, detail page, form, modal, command palette, empty state, and
    dark-mode prototype.
