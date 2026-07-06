# ONE SHOT PROMPT — Laravel + Vue + Inertia + Reusable UI Components

Copy-paste seluruh prompt ini ke AI coding agent seperti Claude Code, Cursor, Antigravity, atau agent lain yang bisa membuat dan mengubah file project.

---

## ROLE

You are a senior full-stack Laravel architect and senior Vue.js frontend engineer.

Your task is to create a production-ready internal business web application using Laravel and Vue in one project folder.

This application must be built as a modular monolith, easy to extend into modules such as CRM, Inventory, Sales, Accounting, HR, Payroll, Asset, Procurement, Workflow, Notifications, and Delivery.

Focus on:

- Clean architecture
- Reusable UI components
- Reusable CRUD pattern
- Dummy data for development
- Professional SaaS/admin dashboard UI
- Easy future module generation
- Maintainable folder structure

Do not create random unrelated features. Build a strong foundation first.

---

## TECH STACK

Use this stack:

- Backend: Laravel
- Frontend: Vue 3
- Bridge: Inertia.js
- Styling: Tailwind CSS
- UI Component Base: shadcn-vue
- Icons: lucide-vue-next
- Auth: Laravel Breeze / Laravel official starter kit with Inertia + Vue
- Database: MySQL or PostgreSQL
- Table: reusable custom DataTable component, optionally using TanStack Table if needed
- Form validation: Laravel Form Request on backend + reusable Vue form components on frontend
- Notifications: Inertia flash message + Toast component
- Architecture: Modular Monolith

Use TypeScript in Vue components where reasonable.

---

## MAIN GOAL

Create a Laravel application with Vue + Inertia in one folder, with this base structure:

```text
project-root/
├── app/
│   ├── Modules/
│   │   ├── Inventory/
│   │   ├── CRM/
│   │   ├── Sales/
│   │   ├── Accounting/
│   │   ├── HCM/
│   │   ├── Payroll/
│   │   ├── Asset/
│   │   ├── Procurement/
│   │   ├── Workflow/
│   │   ├── Notifications/
│   │   └── Delivery/
│   │
│   └── Shared/
│       ├── Actions/
│       ├── DTOs/
│       ├── Enums/
│       ├── Services/
│       ├── Traits/
│       └── Helpers/
│
├── resources/
│   └── js/
│       ├── Components/
│       │   ├── ui/
│       │   ├── layout/
│       │   ├── navigation/
│       │   ├── forms/
│       │   ├── tables/
│       │   ├── filters/
│       │   ├── modals/
│       │   ├── feedback/
│       │   └── examples/
│       │
│       ├── Layouts/
│       ├── Pages/
│       │   ├── Dashboard/
│       │   ├── Inventory/
│       │   └── Settings/
│       │
│       ├── Composables/
│       ├── Stores/
│       ├── Types/
│       └── app.ts
│
├── routes/
│   └── web.php
│
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
│
└── docs/
    ├── ARCHITECTURE.md
    ├── MODULES.md
    ├── UI_COMPONENTS.md
    ├── DEVELOPMENT_GUIDE.md
    └── CRUD_PATTERN.md
```

---

## REQUIRED FEATURES

Build the first version with these features:

### 1. Authentication

Create working authentication:

- Login
- Logout
- Protected dashboard route
- Optional register route, but make it easy to disable for internal app

### 2. Main Layout

Create reusable application layout:

- Sidebar navigation
- Topbar/header
- Breadcrumb
- Page title area
- User dropdown
- Responsive layout
- Mobile sidebar
- Flash notification area
- Dark mode support if reasonable

### 3. Dashboard

Create dashboard page:

- Summary cards
- Module shortcut cards
- Recent activities table
- Simple chart placeholder
- Quick action buttons

Use dummy data first.

### 4. Inventory Module

Create one complete example module: Inventory.

Inventory features:

- List inventory items
- Create item
- Edit item
- Delete item
- Search item by code/name
- Filter by category
- Filter by status
- Pagination
- Validation
- Flash success/error message
- Dummy seed data

Inventory table columns:

- Code
- Name
- Category
- Stock
- Unit
- Status
- Created Date
- Actions

Inventory fields:

- code
- name
- description
- category_id
- stock
- unit
- minimum_stock
- status

Statuses:

- active
- inactive
- archived

Example categories:

- Raw Material
- Finished Goods
- Sparepart
- Office Supply
- Packaging
- Asset

---

## BACKEND ARCHITECTURE RULES

Use this structure for Inventory:

```text
app/Modules/Inventory/
├── Controllers/
│   └── InventoryItemController.php
├── Models/
│   ├── InventoryItem.php
│   └── InventoryCategory.php
├── Requests/
│   ├── StoreInventoryItemRequest.php
│   └── UpdateInventoryItemRequest.php
├── Services/
│   └── InventoryItemService.php
├── Data/
│   └── InventoryItemData.php
├── Enums/
│   └── InventoryItemStatus.php
└── Routes/
    └── inventory.php
```

Rules:

- Keep controllers thin.
- Put business logic in service classes.
- Use Form Request for validation.
- Use Eloquent relationships properly.
- Use route model binding where possible.
- Use query scopes for search/filter.
- Use pagination.
- Use enums or constants for status values.
- Use clear naming.
- Do not put all logic in controller.

---

## FRONTEND COMPONENT SYSTEM

Create reusable Vue components. Use shadcn-vue as base where possible. Components must be reusable across modules.

Required components:

```text
resources/js/Components/
├── layout/
│   ├── AppLayout.vue
│   ├── AppSidebar.vue
│   ├── AppHeader.vue
│   ├── AppBreadcrumb.vue
│   ├── AppContent.vue
│   ├── PageHeader.vue
│   └── UserDropdown.vue
│
├── navigation/
│   ├── SidebarItem.vue
│   └── ModuleShortcutCard.vue
│
├── forms/
│   ├── FormInput.vue
│   ├── FormTextarea.vue
│   ├── FormSelect.vue
│   ├── FormDatePicker.vue
│   ├── FormNumberInput.vue
│   ├── FormSwitch.vue
│   └── FormError.vue
│
├── tables/
│   ├── DataTable.vue
│   ├── DataTableHeader.vue
│   ├── DataTablePagination.vue
│   ├── DataTableEmpty.vue
│   └── ActionDropdown.vue
│
├── filters/
│   ├── SearchInput.vue
│   ├── FilterPanel.vue
│   └── FilterSelect.vue
│
├── modals/
│   ├── ConfirmDialog.vue
│   └── FormDialog.vue
│
├── feedback/
│   ├── Toast.vue
│   ├── EmptyState.vue
│   ├── LoadingState.vue
│   └── StatusBadge.vue
│
└── examples/
    ├── ComponentShowcase.vue
    └── ExampleCrudPage.vue
```

---

## COMPONENT API REQUIREMENTS

Create components with clean props and events.

### PageHeader.vue

Purpose: reusable page heading.

Props:

```ts
title: string
description?: string
```

Slots:

```text
actions
```

Usage example:

```vue
<PageHeader
  title="Inventory Items"
  description="Manage inventory items and stock information."
>
  <template #actions>
    <Link :href="route('inventory.items.create')">
      <Button>Create Item</Button>
    </Link>
  </template>
</PageHeader>
```

### FormInput.vue

Purpose: reusable input with label and error.

Props:

```ts
modelValue: string | number | null
label: string
name: string
type?: string
placeholder?: string
error?: string
required?: boolean
```

Events:

```ts
update:modelValue
```

Usage example:

```vue
<FormInput
  v-model="form.name"
  name="name"
  label="Item Name"
  placeholder="Enter item name"
  :error="form.errors.name"
  required
/>
```

### FormSelect.vue

Purpose: reusable select.

Props:

```ts
modelValue: string | number | null
label: string
name: string
options: Array<{ label: string; value: string | number }>
placeholder?: string
error?: string
required?: boolean
```

Usage example:

```vue
<FormSelect
  v-model="form.category_id"
  name="category_id"
  label="Category"
  :options="categoryOptions"
  :error="form.errors.category_id"
/>
```

### DataTable.vue

Purpose: reusable table for all modules.

Props:

```ts
columns: Array<{
  key: string
  label: string
  sortable?: boolean
  align?: 'left' | 'center' | 'right'
}>
items: Array<Record<string, any>>
loading?: boolean
emptyTitle?: string
emptyDescription?: string
```

Slots:

```text
cell-{columnKey}
actions
empty
```

Usage example:

```vue
<DataTable
  :columns="columns"
  :items="items.data"
  empty-title="No inventory items"
  empty-description="Create your first inventory item to start tracking stock."
>
  <template #cell-status="{ item }">
    <StatusBadge :status="item.status" />
  </template>

  <template #cell-actions="{ item }">
    <ActionDropdown
      :item="item"
      @edit="goToEdit(item)"
      @delete="confirmDelete(item)"
    />
  </template>
</DataTable>

### DataTablePagination.vue

Purpose: Reusable pagination component.

Props:

```ts
links: Array<{
  url: string | null
  label: string
  active: boolean
}>
```

Usage example:

```vue
<DataTablePagination :links="items.links" />
```
```

### StatusBadge.vue

Purpose: reusable status indicator.

Props:

```ts
status: string
label?: string
```

Status mapping:

```text
active    -> green/positive style
inactive  -> gray/neutral style
archived  -> red/destructive style
pending   -> yellow/warning style
approved  -> green/positive style
rejected  -> red/destructive style
```

### ConfirmDialog.vue

Purpose: reusable confirmation dialog before delete or dangerous action.

Props:

```ts
open: boolean
title: string
description?: string
confirmText?: string
cancelText?: string
variant?: 'default' | 'destructive'
```

Events:

```ts
confirm
cancel
update:open
```

---

## EXAMPLE COMPONENT IMPLEMENTATION

Create these example components as actual files.

### resources/js/Components/feedback/StatusBadge.vue

```vue
<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  status: string
  label?: string
}>()

const normalizedStatus = computed(() => props.status?.toLowerCase() ?? 'unknown')

const displayLabel = computed(() => {
  if (props.label) return props.label
  return normalizedStatus.value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, char => char.toUpperCase())
})

const badgeClass = computed(() => {
  const map: Record<string, string> = {
    active: 'bg-green-100 text-green-700 border-green-200',
    approved: 'bg-green-100 text-green-700 border-green-200',
    inactive: 'bg-gray-100 text-gray-700 border-gray-200',
    archived: 'bg-red-100 text-red-700 border-red-200',
    rejected: 'bg-red-100 text-red-700 border-red-200',
    pending: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  }

  return map[normalizedStatus.value] ?? 'bg-gray-100 text-gray-700 border-gray-200'
})
</script>

<template>
  <span
    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
    :class="badgeClass"
  >
    {{ displayLabel }}
  </span>
</template>
```

### resources/js/Components/forms/FormInput.vue

```vue
<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: string | number | null
  label: string
  name: string
  type?: string
  placeholder?: string
  error?: string
  required?: boolean
}>(), {
  type: 'text',
  placeholder: '',
  required: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const inputId = computed(() => `input-${props.name}`)
</script>

<template>
  <div class="space-y-1.5">
    <label :for="inputId" class="text-sm font-medium text-gray-700">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <input
      :id="inputId"
      :name="name"
      :type="type"
      :value="modelValue ?? ''"
      :placeholder="placeholder"
      class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
      :class="error ? 'border-red-500 focus:border-red-500 focus:ring-red-500/10' : ''"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />

    <p v-if="error" class="text-sm text-red-600">
      {{ error }}
    </p>
  </div>
</template>
```

### resources/js/Components/tables/DataTable.vue

```vue
<script setup lang="ts">
type Column = {
  key: string
  label: string
  sortable?: boolean
  align?: 'left' | 'center' | 'right'
}

defineProps<{
  columns: Column[]
  items: Record<string, any>[]
  loading?: boolean
  emptyTitle?: string
  emptyDescription?: string
}>()

const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}
</script>

<template>
  <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500"
              :class="alignClass(column.align)"
            >
              {{ column.label }}
            </th>
          </tr>
        </thead>

        <tbody class="divide-y divide-gray-100 bg-white">
          <tr v-if="loading">
            <td :colspan="columns.length" class="px-4 py-8 text-center text-sm text-gray-500">
              Loading data...
            </td>
          </tr>

          <tr v-else-if="items.length === 0">
            <td :colspan="columns.length" class="px-4 py-10 text-center">
              <slot name="empty">
                <div class="space-y-1">
                  <p class="text-sm font-medium text-gray-900">
                    {{ emptyTitle ?? 'No data found' }}
                  </p>
                  <p class="text-sm text-gray-500">
                    {{ emptyDescription ?? 'Try changing your search or filter.' }}
                  </p>
                </div>
              </slot>
            </td>
          </tr>

          <tr
            v-else
            v-for="item in items"
            :key="item.id"
            class="hover:bg-gray-50"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              class="whitespace-nowrap px-4 py-3 text-sm text-gray-700"
              :class="alignClass(column.align)"
            >
              <slot :name="`cell-${column.key}`" :item="item" :value="item[column.key]">
                {{ item[column.key] }}
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
```

### resources/js/Components/filters/SearchInput.vue

```vue
<script setup lang="ts">
import { Search } from 'lucide-vue-next'

withDefaults(defineProps<{
  modelValue: string
  placeholder?: string
}>(), {
  placeholder: 'Search...',
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>

<template>
  <div class="relative">
    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
    <input
      :value="modelValue"
      :placeholder="placeholder"
      class="w-full rounded-md border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
  </div>
</template>
```

---

## FRONTEND PAGE EXAMPLE

Create this example Inventory index page.

### resources/js/Pages/Inventory/Items/Index.vue

```vue
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import SearchInput from '@/Components/filters/SearchInput.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import ActionDropdown from '@/Components/tables/ActionDropdown.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import { Button } from '@/Components/ui/button'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface InventoryItem {
  id: number
  code: string
  name: string
  category_name: string
  stock: number
  minimum_stock: number
  unit: string
  status: string
  created_at_formatted: string
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  meta?: any
}

const props = defineProps<{
  items: PaginatedData<InventoryItem>
  filters: {
    search?: string
    category?: string
    status?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

const columns = [
  { key: 'code', label: 'Code' },
  { key: 'name', label: 'Name' },
  { key: 'category_name', label: 'Category' },
  { key: 'stock', label: 'Stock', align: 'right' },
  { key: 'unit', label: 'Unit' },
  { key: 'status', label: 'Status' },
  { key: 'created_at_formatted', label: 'Created Date' },
  { key: 'actions', label: 'Actions', align: 'right' },
]

watch([search, status], debounce(() => {
  router.get(route('inventory.items.index'), {
    search: search.value,
    status: status.value
  }, {
    preserveState: true,
    replace: true,
  })
}, 400))

const goToEdit = (item: InventoryItem) => {
  router.get(route('inventory.items.edit', item.id))
}

const confirmDelete = (item: InventoryItem) => {
  if (!confirm(`Delete item ${item.name}?`)) return
  router.delete(route('inventory.items.destroy', item.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Inventory Items"
      description="Manage inventory items, stock, category, and status."
    >
      <template #actions>
        <Link :href="route('inventory.items.create')">
          <Button>Create Item</Button>
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
          <div class="w-full sm:max-w-xs">
            <SearchInput v-model="search" placeholder="Search by code or name..." />
          </div>
          <div class="w-full sm:max-w-[200px]">
            <FormSelect
              v-model="status"
              name="status"
              label=""
              placeholder="All Status"
              :options="[
                { label: 'Active', value: 'active' },
                { label: 'Inactive', value: 'inactive' },
                { label: 'Archived', value: 'archived' }
              ]"
            />
          </div>
        </div>
      </div>

      <DataTable
        :columns="columns"
        :items="items.data"
        empty-title="No inventory items"
        empty-description="Create your first inventory item to start tracking stock."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>

        <template #cell-stock="{ item }">
          <span :class="item.stock <= item.minimum_stock ? 'font-semibold text-red-600' : ''">
            {{ item.stock }}
          </span>
        </template>

        <template #cell-actions="{ item }">
          <ActionDropdown
            :item="item"
            @edit="goToEdit(item)"
            @delete="confirmDelete(item)"
          />
        </template>
      </DataTable>

      <DataTablePagination :links="items.links" />
    </div>
  </AppLayout>
</template>
```

---

## DUMMY DATA REQUIREMENTS

Create migrations, factories, and seeders.

### Inventory categories dummy data

Create categories:

```php
$categories = [
    ['name' => 'Raw Material', 'code' => 'RAW'],
    ['name' => 'Finished Goods', 'code' => 'FG'],
    ['name' => 'Sparepart', 'code' => 'SP'],
    ['name' => 'Office Supply', 'code' => 'OS'],
    ['name' => 'Packaging', 'code' => 'PKG'],
    ['name' => 'Asset', 'code' => 'AST'],
];
```

### Inventory items dummy data

Create at least these dummy items:

```php
$items = [
    [
        'code' => 'RAW-001',
        'name' => 'Steel Plate 2mm',
        'description' => 'Raw material for production',
        'category_code' => 'RAW',
        'stock' => 120,
        'minimum_stock' => 30,
        'unit' => 'pcs',
        'status' => 'active',
    ],
    [
        'code' => 'RAW-002',
        'name' => 'Aluminium Sheet',
        'description' => 'Aluminium sheet for assembly',
        'category_code' => 'RAW',
        'stock' => 75,
        'minimum_stock' => 20,
        'unit' => 'pcs',
        'status' => 'active',
    ],
    [
        'code' => 'FG-001',
        'name' => 'Finished Product A',
        'description' => 'Ready to sell product',
        'category_code' => 'FG',
        'stock' => 45,
        'minimum_stock' => 10,
        'unit' => 'box',
        'status' => 'active',
    ],
    [
        'code' => 'SP-001',
        'name' => 'Bearing 6202',
        'description' => 'Machine sparepart',
        'category_code' => 'SP',
        'stock' => 8,
        'minimum_stock' => 15,
        'unit' => 'pcs',
        'status' => 'active',
    ],
    [
        'code' => 'OS-001',
        'name' => 'A4 Paper',
        'description' => 'Office printing paper',
        'category_code' => 'OS',
        'stock' => 30,
        'minimum_stock' => 10,
        'unit' => 'rim',
        'status' => 'active',
    ],
    [
        'code' => 'PKG-001',
        'name' => 'Carton Box Medium',
        'description' => 'Packaging carton box',
        'category_code' => 'PKG',
        'stock' => 200,
        'minimum_stock' => 50,
        'unit' => 'pcs',
        'status' => 'active',
    ],
    [
        'code' => 'AST-001',
        'name' => 'Barcode Scanner',
        'description' => 'Warehouse scanner device',
        'category_code' => 'AST',
        'stock' => 5,
        'minimum_stock' => 2,
        'unit' => 'unit',
        'status' => 'active',
    ],
    [
        'code' => 'AST-002',
        'name' => 'Old Printer',
        'description' => 'Archived office asset',
        'category_code' => 'AST',
        'stock' => 1,
        'minimum_stock' => 0,
        'unit' => 'unit',
        'status' => 'archived',
    ],
];
```

Also generate more dummy items using factory so the table has at least 50 records.

---

## DATABASE DESIGN

Create these tables:

### inventory_categories

Fields:

```text
id
code unique
name
description nullable
status default active
timestamps
soft deletes optional
```

### inventory_items

Fields:

```text
id
inventory_category_id foreign key
code unique
name
description nullable
stock integer default 0
minimum_stock integer default 0
unit string
status string default active
timestamps
soft deletes optional
```

Relationship:

```text
InventoryCategory has many InventoryItem
InventoryItem belongs to InventoryCategory
```

---

## ROUTES

Create routes like this:

```php
Route::middleware(['auth', 'verified'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::resource('items', InventoryItemController::class);
});
```

Make sure route names are:

```text
inventory.items.index
inventory.items.create
inventory.items.store
inventory.items.edit
inventory.items.update
inventory.items.destroy
```

---

## CONTROLLER REQUIREMENTS

InventoryItemController must have:

```php
index()
create()
store(StoreInventoryItemRequest $request)
edit(InventoryItem $item)
update(UpdateInventoryItemRequest $request, InventoryItem $item)
destroy(InventoryItem $item)
```

In `index()`:

- Accept search, category, status filters from request.
- Return Inertia page `Inventory/Items/Index`.
- Include paginated items.
- Include current filters.
- Include category options.

Each item returned to frontend should include:

```php
[
    'id' => $item->id,
    'code' => $item->code,
    'name' => $item->name,
    'category_name' => $item->category?->name,
    'stock' => $item->stock,
    'minimum_stock' => $item->minimum_stock,
    'unit' => $item->unit,
    'status' => $item->status,
    'created_at_formatted' => $item->created_at?->format('d M Y'),
]
```

---

## VALIDATION RULES

Store item validation:

```text
code required string max:50 unique:inventory_items,code
name required string max:255
inventory_category_id required exists:inventory_categories,id
description nullable string
stock required integer min:0
minimum_stock required integer min:0
unit required string max:30
status required in:active,inactive,archived
```

Update item validation:

Same as store, but unique code should ignore current item.

---

## UI STYLE REQUIREMENTS

Use modern SaaS dashboard style:

- Clean white/gray background
- Rounded cards
- Soft shadow
- Professional spacing
- Minimal color usage
- Clear table layout
- Sidebar navigation like admin dashboard
- Reusable components
- Good empty states
- Good loading states
- Proper error display
- Mobile responsive where reasonable

Do not over-design. Keep it professional and clean.

---

## SIDEBAR MENU

Create sidebar menu with these groups:

```ts
const menuItems = [
  { label: 'Dashboard', icon: 'LayoutDashboard', href: route('dashboard') },
  { label: 'CRM', icon: 'Users', href: '#' },
  { label: 'Schedule', icon: 'CalendarDays', href: '#' },
  { label: 'CMS', icon: 'FileText', href: '#' },
  { label: 'Legal', icon: 'Scale', href: '#' },
  { label: 'HSE', icon: 'ShieldCheck', href: '#' },
  { label: 'Project', icon: 'KanbanSquare', href: '#' },
  { label: 'Inventory', icon: 'Boxes', href: route('inventory.items.index') },
  { label: 'Sales', icon: 'ShoppingCart', href: '#' },
  { label: 'Procurement', icon: 'PackageSearch', href: '#' },
  { label: 'HCM', icon: 'UserRoundCog', href: '#' },
  { label: 'Payroll', icon: 'WalletCards', href: '#' },
  { label: 'Asset', icon: 'Archive', href: '#' },
  { label: 'Accounting', icon: 'Calculator', href: '#' },
  { label: 'Workflow', icon: 'Workflow', href: '#' },
  { label: 'Notifications', icon: 'Bell', href: '#' },
  { label: 'Delivery', icon: 'Truck', href: '#' },
]
```

Use lucide-vue-next icons.

Note: Sidebar menu contains planned modules (marked with '#'). Only 'Dashboard' and 'Inventory' will have working routes in the initial version.

---

## DASHBOARD DUMMY DATA

Create dashboard dummy cards:

```ts
const summaryCards = [
  { title: 'Total Inventory Items', value: '1,248', description: '+12% from last month', icon: 'Boxes' },
  { title: 'Low Stock Items', value: '18', description: 'Need attention', icon: 'TriangleAlert' },
  { title: 'Active Modules', value: '17', description: 'System modules ready', icon: 'LayoutGrid' },
  { title: 'Pending Approvals', value: '9', description: 'Waiting for review', icon: 'Clock' },
]
```

Create recent activity dummy data:

```ts
const recentActivities = [
  { id: 1, module: 'Inventory', action: 'Created item RAW-001', user: 'Admin User', time: '5 minutes ago' },
  { id: 2, module: 'Sales', action: 'Updated sales order SO-2026-001', user: 'Sales User', time: '20 minutes ago' },
  { id: 3, module: 'Accounting', action: 'Posted journal entry JE-1001', user: 'Finance User', time: '1 hour ago' },
  { id: 4, module: 'Workflow', action: 'Approved purchase request PR-5501', user: 'Manager User', time: '2 hours ago' },
]
```

---

## COMPOSABLES

Create reusable composables:

```text
resources/js/Composables/
├── debounce.ts
├── useFlashToast.ts
├── useConfirmDialog.ts
└── useTableFilters.ts
```

### debounce.ts

```ts
export function debounce<T extends (...args: any[]) => void>(callback: T, delay = 300) {
  let timeout: ReturnType<typeof setTimeout>

  return (...args: Parameters<T>) => {
    clearTimeout(timeout)
    timeout = setTimeout(() => callback(...args), delay)
  }
}
```

### useFlashToast.ts

```ts
import { watch, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function useFlashToast(showToast: (message: string, type: 'success' | 'error') => void) {
  const page = usePage()

  const checkAndShow = () => {
    const flash = page.props.flash as { success?: string; error?: string }
    if (flash?.success) {
      showToast(flash.success, 'success')
    }
    if (flash?.error) {
      showToast(flash.error, 'error')
    }
  }

  onMounted(() => checkAndShow())
  watch(() => page.props.flash, () => checkAndShow(), { deep: true })
}
```

---

## DOCUMENTATION REQUIREMENTS

Create these docs:

### docs/ARCHITECTURE.md

Explain:

- Why modular monolith
- Backend folder structure
- Frontend folder structure
- Shared services
- Where to put business logic
- How Inertia connects Laravel and Vue

### docs/MODULES.md

Explain:

- How to add a new module
- Required backend folders
- Required frontend pages
- Route naming pattern
- CRUD naming pattern

### docs/UI_COMPONENTS.md

Explain:

- List of reusable components
- How to use FormInput
- How to use DataTable
- How to use StatusBadge
- How to use ConfirmDialog
- Component naming rules

### docs/DEVELOPMENT_GUIDE.md

Explain:

- How to install
- How to run locally
- How to run migration and seeder
- How to create a new page
- How to create a new component

### docs/CRUD_PATTERN.md

Explain:

- Standard CRUD flow
- Controller pattern
- Service pattern
- Request validation pattern
- Inertia page pattern
- Delete confirmation pattern

---

## INSTALLATION COMMANDS TO USE OR DOCUMENT

If starting from scratch, use equivalent commands:

```bash
composer create-project laravel/laravel enterprise-app
cd enterprise-app
composer require laravel/breeze --dev
php artisan breeze:install vue --typescript
npm install
npm install lucide-vue-next
php artisan migrate
npm run dev
```

If shadcn-vue setup is available, install and configure it. If not available, create compatible reusable local components under `resources/js/Components/ui`.

---

## EXECUTION ORDER

Work in this order:

1. Create base Laravel + Vue + Inertia setup.
2. Create app layout components.
3. Create reusable UI components.
4. Create dashboard page with dummy data.
5. Create Inventory backend module.
6. Create migrations, models, factories, and seeders.
7. Create Inventory frontend pages.
8. Wire routes.
9. Add flash messages and toast.
10. Add documentation.
11. Run formatting and basic checks.
12. Provide final summary of created files and how to run.

---

## IMPORTANT QUALITY CHECKLIST

Before finishing, verify:

- App runs locally.
- Login works.
- Dashboard opens after login.
- Sidebar appears.
- Inventory list opens.
- Inventory dummy data appears.
- Search works.
- Filter structure is ready.
- Create item works.
- Edit item works.
- Delete item works.
- Form validation works.
- Flash message appears.
- Components are reusable.
- No duplicate UI logic.
- Controllers are thin.
- Service classes are used.
- Documentation exists.

---

## FINAL RESPONSE FORMAT

After generating the project, respond with:

```text
Done.

Created:
- Laravel + Vue + Inertia base app
- Reusable app layout
- Reusable UI components
- Dashboard page
- Inventory module CRUD
- Dummy data seeder
- Documentation

How to run:
1. composer install
2. npm install
3. cp .env.example .env
4. php artisan key:generate
5. php artisan migrate --seed
6. npm run dev
7. php artisan serve
```

Also list important files that were created.

---

## STRICT RULES

- Do not skip reusable components.
- Do not put all code in one big file.
- Do not create a separate frontend project.
- Keep Laravel and Vue in one project folder.
- Do not build microservices.
- Do not overcomplicate with domain-driven design unless necessary.
- Use modular monolith, not distributed services.
- Prioritize working CRUD and reusable UI foundation.
- Make it easy to add future modules.
- Use clean names and consistent structure.