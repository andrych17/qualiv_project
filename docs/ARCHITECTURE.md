# Architecture

Per-tenant differences **without** `if ($tenantId === '001')`.

Same PHP/Vue code path. Firm A vs B differ because **tenant DB data** (consts + field defs) differs.

```text
Ladder (prefer lower first):
  1. Consts          → SYSCONFIG.config_consts
  2. Custom fields   → CUSTOMFIELDS.*
  3. Custom logic    → services reading consts + field values
  4. Plan / modules  → central tenants.plan
  5. Vertical module → app/Modules/Legal …
```

---

## 1. DB

### 1.1 Tenant DB layout

Mode B: one PostgreSQL database per tenant. No `tenant_id` on app tables.

```text
tenant_001 / tenant_002
├── SYSCONFIG.          # menus, groups, rights, consts
├── INVENTORY.
├── LEGAL.              # core entity columns only
├── CUSTOMFIELDS.       # EAV — tenant-specific attrs
└── …
```

Central DB (`nusaevo`): `tenants`, `tenant_user_lookups`, `tenants.plan` only.

### 1.2 `CUSTOMFIELDS` schema

Migration: `database/migrations/tenant/2026_07_17_150001_create_custom_fields_tables.php`

```text
CUSTOMFIELDS.field_defs      # what fields exist for an entity
CUSTOMFIELDS.field_values    # values per entity row
```

**field_defs**

| Column | Type | Notes |
|--------|------|--------|
| `id` | bigint PK | |
| `uuid` | uuid unique | external-facing |
| `entity_type` | string | e.g. `legal_case` |
| `code` | string | stable key (`court_register`) |
| `label` | string | UI label |
| `field_type` | string | `text` \| `number` \| `date` \| `select` |
| `options` | json nullable | select: `[{label, value}]` |
| `is_required` | bool | validated on save |
| `seq` | int | form order |
| `status` | string | `active` / inactive |
| unique | | `(entity_type, code)` |

**field_values**

| Column | Type | Notes |
|--------|------|--------|
| `id` | bigint PK | |
| `field_def_id` | bigint | app-enforced link to def |
| `entity_type` | string | same as def |
| `entity_id` | bigint | owner row PK |
| `value` | text nullable | all types stored as text; cast in service |
| unique | | `(field_def_id, entity_type, entity_id)` |

ponytail: single `value` column. Ceiling: typed columns / JSONB if reporting needs heavy filters.

### 1.3 Related core tables (not EAV)

| Schema.table | Role |
|--------------|------|
| `LEGAL.cases` | Fixed domain columns (`code`, `title`, `status`, `notes`, `uuid`) |
| `SYSCONFIG.config_consts` | Runtime knobs (`LEGAL.CASE_PREFIX`, `LEGAL.URGENT_SETS_PENDING`) |

Do **not** add tenant-specific nullable columns to `LEGAL.cases`. Put them in `CUSTOMFIELDS`.

### 1.4 Demo seed data

`TenantFlavorSeeder` (after SysConfig):

| | Firm A (`001`) | Firm B (`002`) |
|--|----------------|----------------|
| field_defs | `court_register`*, `hearing_date`, `priority`* | `lease_object`*, `monthly_rent`, `priority` |
| `LEGAL.CASE_PREFIX` | `A` | `B` |
| `LEGAL.URGENT_SETS_PENDING` | `1` | `0` |

\* required.

---

## 2. Code

### 2.1 Module map

```text
app/Modules/CustomFields/          # Core
├── Models/
│   ├── FieldDef.php               # CUSTOMFIELDS.field_defs
│   └── FieldValue.php             # CUSTOMFIELDS.field_values
└── Services/
    ├── CustomFieldService.php     # defs, validate, sync, formPayload
    └── CustomLogicEngine.php      # beforeSave hooks from consts + values

app/Modules/Legal/                 # Vertical (consumer)
├── Contracts/CaseCodeGenerator.php
├── Services/
│   ├── LegalCaseService.php       # orchestrates CF + logic + persist
│   └── PrefixedCaseCodeGenerator.php
├── Controllers/LegalCaseController.php
└── Requests/Store|UpdateLegalCaseRequest.php

app/Providers/AppServiceProvider.php
  → bind CaseCodeGenerator → PrefixedCaseCodeGenerator

resources/js/
├── Components/forms/CustomFieldInputs.vue
└── Pages/Legal/Cases/{Create,Edit}.vue
```

### 2.2 Boundaries

| Layer | Owns | Must not |
|-------|------|----------|
| CustomFields (Core) | EAV + generic logic engine | Import Legal models |
| Legal (Vertical) | Domain service, case codes, routes | Put EAV tables under `LEGAL` |
| SYSCONFIG | Consts / menus / rights | Hard-code Firm A/B |

### 2.3 Wire pattern (any entity)

```text
Controller
  → formPayload(entity_type, id?)
Service create/update
  → validateAndNormalize(custom_fields)
  → (optional) CaseCodeGenerator / domain helpers
  → CustomLogicEngine::beforeSave
  → persist core row
  → sync(entity_type, id, values)
Service delete
  → deleteFor → delete core row
```

### 2.4 Save sequence (Legal)

```mermaid
sequenceDiagram
  participant UI as Vue Create/Edit
  participant Ctrl as LegalCaseController
  participant Svc as LegalCaseService
  participant CF as CustomFieldService
  participant Logic as CustomLogicEngine
  participant Code as CaseCodeGenerator
  participant DB as Tenant DB

  UI->>Ctrl: POST/PUT + custom_fields
  Ctrl->>Svc: create/update(validated)
  Svc->>CF: validateAndNormalize
  alt code empty
    Svc->>Code: next() from LEGAL.CASE_PREFIX
  end
  Svc->>Logic: beforeSave(legal_case, data, custom)
  Svc->>DB: INSERT/UPDATE LEGAL.cases
  Svc->>CF: sync → CUSTOMFIELDS.field_values
```

### 2.5 Tests

`tests/Feature/CustomFieldsLegalCaseTest.php`

- Urgent + const on → status `pending`, auto prefix, values stored
- Required custom field missing → session validation error

---

## 3. Custom

Two kinds of “custom” — both config/data driven, never `tenant_id` branches.

### 3.1 Custom fields (data shape)

Extra attributes per entity, defined per tenant in `field_defs`, stored in `field_values`.

- UI: `CustomFieldInputs` on Legal create/edit
- Validation: required / type / select options in `CustomFieldService`
- Admin CRUD for defs: **not yet** (seed / SQL)

Add to another entity:

1. Seed defs with new `entity_type` (e.g. `inventory_item`)
2. Wire that module’s Service like Legal
3. Reuse `CustomFieldInputs.vue`

### 3.2 Custom logic (behavior)

#### A. Strategy / contract

```php
// AppServiceProvider
$this->app->bind(CaseCodeGenerator::class, PrefixedCaseCodeGenerator::class);
```

`PrefixedCaseCodeGenerator::next()` reads `LEGAL.CASE_PREFIX` → `{PREFIX}-{NNN}`.  
Blank code on create → auto. Firm A/B differ by seeded const only.

#### B. Engine hooks

`CustomLogicEngine::beforeSave($entityType, $data, $customValues)` mutates core payload.

Current Legal rule:

```text
LEGAL.URGENT_SETS_PENDING enabled
AND custom field priority = urgent
AND status = open
→ set status = pending
```

| Const | Firm A | Firm B | Effect |
|-------|--------|--------|--------|
| `URGENT_SETS_PENDING` | on | off | Same code; A forces pending, B keeps open |

ponytail: flat ifs in engine. Ceiling: pluggable Rule classes when rules multiply.

### 3.3 Constants that drive custom logic

| const_group | group_code | Meaning |
|-------------|------------|---------|
| `LEGAL` | `CASE_PREFIX` | Auto case code prefix |
| `LEGAL` | `URGENT_SETS_PENDING` | `num1>0` → urgent forces pending |

UI: System → Constants (`/config/consts`).

### 3.4 Do / Don’t

| Do | Don’t |
|----|-------|
| Put tenant-specific attrs in `CUSTOMFIELDS` | Add nullable columns to `LEGAL.cases` for one firm |
| Drive behavior from consts + field values | `if (tenant_id === '001')` |
| Keep CustomFields Core | Leak Legal model into CustomFieldService |
| Prefer consts before engine rules | New microservice for simple toggles |

---

## Related

- Multi-tenancy & schemas: [CLAUDE.md](../CLAUDE.md) §4, §7
- Plan gates: `config/tenant_modules.php`, `TenantFeatureService`
- Design: [resources/DESIGN.md](../resources/DESIGN.md)
