<!-- ponytail: Config menu listing — SYSCONFIG.config_menus with UI/UX Pro Max Card & Table views -->
<script setup lang="ts">
import { computed, ref, watch, type Component } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import EmptyState from '@/Components/feedback/EmptyState.vue'
import Checkbox from '@/Components/Checkbox.vue'
import * as LucideIcons from 'lucide-vue-next'
import {
  LayoutGrid,
  List,
  Search,
  X,
  Plus,
  Pencil,
  Trash2,
  ExternalLink,
  Layers,
  FolderTree,
  CheckCircle2,
  EyeOff,
  HelpCircle,
  Hash,
  CornerDownRight,
} from 'lucide-vue-next'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ConfigMenuRow {
  id: number
  code: string
  menu_caption: string
  menu_header: string | null
  menu_link: string | null
  icon: string | null
  seq: number
  status_code: string
  status_label: string
  module_code: string | null
  parent_id?: number | null
  parent_code?: string | null
  parent_caption?: string | null
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

interface StatsData {
  total: number
  active: number
  inactive: number
  top_level: number
  headers_count: number
}

const props = defineProps<{
  items: PaginatedData<ConfigMenuRow>
  filters: {
    search?: string
    status?: string
    header?: string
    sort?: string
    direction?: string
    per_page?: string
  }
  headers: Array<{ label: string; value: string }>
  stats?: StatsData
}>()

// Persistent view mode: 'card' or 'table'
const STORAGE_VIEW_KEY = 'nusaevo_menu_view_mode'
const viewMode = ref<'card' | 'table'>(
  (localStorage.getItem(STORAGE_VIEW_KEY) as 'card' | 'table') || 'card'
)

const setViewMode = (mode: 'card' | 'table') => {
  viewMode.value = mode
  localStorage.setItem(STORAGE_VIEW_KEY, mode)
}

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '', header: props.filters.header ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : { key: 'seq', direction: 'asc' }
)
const selected = ref<Array<number>>([])
const perPage = ref(Number(props.filters.per_page) || props.items.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'A' },
      { label: 'Inactive', value: 'I' },
    ],
  },
  { key: 'header', label: 'Header', type: 'select', options: props.headers },
]

const columns: Array<{
  key: string
  label: string
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
}> = [
  { key: 'seq', label: 'Seq', align: 'right', sortable: true },
  { key: 'code', label: 'Code', sortable: true },
  { key: 'menu_caption', label: 'Caption', sortable: true },
  { key: 'menu_header', label: 'Header', sortable: true },
  { key: 'menu_link', label: 'Link' },
  { key: 'icon', label: 'Icon' },
  { key: 'status_label', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' },
]

const getIcon = (name: string | null): Component => {
  if (!name) return HelpCircle
  const icons = LucideIcons as unknown as Record<string, Component>
  if (icons[name]) return icons[name]

  const pascal = name
    .split(/[-_]/)
    .map((s) => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase())
    .join('')
  return icons[pascal] ?? HelpCircle
}

const isAllSelected = computed(() => {
  if (props.items.data.length === 0) return false
  return props.items.data.every((item) => selected.value.includes(item.id))
})

const toggleSelectAll = () => {
  if (isAllSelected.value) {
    selected.value = []
  } else {
    selected.value = props.items.data.map((item) => item.id)
  }
}

const toggleSelectItem = (id: number) => {
  const index = selected.value.indexOf(id)
  if (index > -1) {
    selected.value.splice(index, 1)
  } else {
    selected.value.push(id)
  }
}

// Grouped items by menu_header for Card view
const groupedCardItems = computed(() => {
  const groups: Record<string, ConfigMenuRow[]> = {}
  for (const item of props.items.data) {
    const header = (item.menu_header || 'General').trim() || 'General'
    if (!groups[header]) {
      groups[header] = []
    }
    groups[header].push(item)
  }
  return groups
})

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('config.menus.index'), {
    search: search.value,
    status: filters.value.status,
    header: filters.value.header,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, {
    preserveState: true,
    replace: true,
  })
}, 350))

const clearSearch = () => {
  search.value = ''
}

const clearFilters = () => {
  search.value = ''
  filters.value.status = ''
  filters.value.header = ''
}

const { confirm } = useConfirm()

const confirmDelete = (item: ConfigMenuRow | Record<string, unknown>) => {
  const row = item as ConfigMenuRow
  confirm({
    title: `Delete menu ${row.menu_caption} (${row.code})?`,
    description: 'This will permanently remove this menu entry and any permission mappings associated with it.',
    variant: 'destructive',
    confirmText: 'Delete Menu',
    onConfirm: () => router.delete(route('config.menus.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected menu(s)?`,
    description: 'This action cannot be undone. Selected menus and submenus will be deleted.',
    variant: 'destructive',
    confirmText: 'Delete Selected',
    onConfirm: () =>
      router.delete(route('config.menus.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Menus"
      description="Manage navigation hierarchy, categories, and routing for this tenant."
    >
      <template #actions>
        <PrimaryButton :href="route('config.menus.create')">
          <Plus class="h-4 w-4 mr-1.5" />
          Create Menu
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-6">
      <!-- KPI Stats Grid -->
      <div v-if="stats" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Total Menus"
          :value="stats.total.toString()"
          description="Registered navigation entries"
          icon="Layers"
        />
        <StatCard
          title="Active Menus"
          :value="stats.active.toString()"
          description="Visible in navigation sidebar"
          icon="CheckCircle2"
        />
        <StatCard
          title="Inactive Menus"
          :value="stats.inactive.toString()"
          description="Disabled or hidden entries"
          icon="EyeOff"
        />
        <StatCard
          title="Header Sections"
          :value="stats.headers_count.toString()"
          description="Category groupings"
          icon="FolderTree"
        />
      </div>

      <!-- Controls & Filter Toolbar Bar -->
      <div class="p-4 bg-surface-0 border border-border rounded-xl shadow-2xs space-y-3">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
          <!-- Search Box -->
          <div class="relative flex-1 max-w-md">
            <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-ink-400" />
            <input
              v-model="search"
              type="text"
              placeholder="Search code, caption, link..."
              class="w-full pl-9 pr-8 py-2 rounded-lg border border-border bg-surface-0 text-sm text-ink-900 placeholder:text-ink-500 focus:outline-none focus:ring-2 focus:ring-accent/20 focus:border-accent transition-colors"
            />
            <button
              v-if="search"
              type="button"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 rounded text-ink-400 hover:text-ink-700 cursor-pointer"
              @click="clearSearch"
            >
              <X class="h-3.5 w-3.5" />
            </button>
          </div>

          <!-- Filters & View Switcher -->
          <div class="flex flex-wrap items-center gap-2.5">
            <!-- Header Filter Select -->
            <select
              v-model="filters.header"
              class="py-2 pl-3 pr-8 rounded-lg border border-border bg-surface-0 text-xs font-medium text-ink-800 focus:outline-none focus:ring-2 focus:ring-accent/20 focus:border-accent cursor-pointer"
            >
              <option value="">All Headers</option>
              <option v-for="h in headers" :key="h.value" :value="h.value">{{ h.label }}</option>
            </select>

            <!-- Status Filter Select -->
            <select
              v-model="filters.status"
              class="py-2 pl-3 pr-8 rounded-lg border border-border bg-surface-0 text-xs font-medium text-ink-800 focus:outline-none focus:ring-2 focus:ring-accent/20 focus:border-accent cursor-pointer"
            >
              <option value="">All Statuses</option>
              <option value="A">Active</option>
              <option value="I">Inactive</option>
            </select>

            <!-- Reset Filter Button -->
            <button
              v-if="search || filters.status || filters.header"
              type="button"
              class="px-2.5 py-2 rounded-lg border border-border bg-surface-50 hover:bg-surface-100 text-xs font-medium text-ink-700 transition-colors cursor-pointer"
              title="Reset Filters"
              @click="clearFilters"
            >
              Reset
            </button>

            <!-- View Switcher (Card vs Table) -->
            <div class="flex items-center p-1 bg-surface-50 border border-border rounded-lg">
              <button
                type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all cursor-pointer"
                :class="
                  viewMode === 'card'
                    ? 'bg-surface-0 text-accent shadow-2xs border border-border'
                    : 'text-ink-600 hover:text-ink-900'
                "
                title="Card Grid View"
                @click="setViewMode('card')"
              >
                <LayoutGrid class="h-3.5 w-3.5" />
                <span>Cards</span>
              </button>

              <button
                type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all cursor-pointer"
                :class="
                  viewMode === 'table'
                    ? 'bg-surface-0 text-accent shadow-2xs border border-border'
                    : 'text-ink-600 hover:text-ink-900'
                "
                title="Table List View"
                @click="setViewMode('table')"
              >
                <List class="h-3.5 w-3.5" />
                <span>Table</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Bulk Selection Action Bar -->
        <div
          v-if="selected.length > 0"
          class="flex items-center justify-between px-3 py-2 bg-accent/10 border border-accent/25 rounded-lg text-xs font-medium text-ink-900 animate-enter"
        >
          <div class="flex items-center gap-2">
            <Checkbox :checked="isAllSelected" @update:checked="toggleSelectAll" />
            <span><strong>{{ selected.length }}</strong> menu(s) selected</span>
          </div>

          <div class="flex items-center gap-2">
            <button
              type="button"
              class="px-2.5 py-1 text-ink-600 hover:text-ink-900 hover:underline cursor-pointer"
              @click="selected = []"
            >
              Deselect all
            </button>
            <button
              type="button"
              class="px-3 py-1 rounded-md bg-signal-danger text-white hover:bg-signal-danger/90 font-semibold cursor-pointer shadow-2xs transition-colors flex items-center gap-1.5"
              @click="confirmBulkDelete"
            >
              <Trash2 class="h-3.5 w-3.5" />
              <span>Delete Selected</span>
            </button>
          </div>
        </div>
      </div>

      <!-- CARD GRID VIEW MODE -->
      <div v-if="viewMode === 'card'" class="space-y-6">
        <!-- If results exist -->
        <template v-if="items.data.length > 0">
          <!-- Select All Toolbar for Cards -->
          <div class="flex items-center justify-between px-1 text-xs text-ink-600">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <Checkbox :checked="isAllSelected" @update:checked="toggleSelectAll" />
              <span>Select All Visible Menus ({{ items.data.length }})</span>
            </label>
            <span class="text-ink-500 font-mono">Showing {{ items.from }} - {{ items.to }} of {{ items.total }}</span>
          </div>

          <!-- Header Sections & Card Grids -->
          <div v-for="(sectionItems, headerName) in groupedCardItems" :key="headerName" class="space-y-3">
            <!-- Section Header Title -->
            <div class="flex items-center gap-2 pb-1 border-b border-border">
              <FolderTree class="h-4 w-4 text-accent" />
              <h2 class="text-sm font-bold text-ink-900 uppercase tracking-wider">{{ headerName }}</h2>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-surface-50 text-ink-600 border border-border">
                {{ sectionItems.length }}
              </span>
            </div>

            <!-- Cards Grid Layout -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
              <div
                v-for="item in sectionItems"
                :key="item.id"
                class="group relative rounded-xl border bg-surface-0 p-4.5 transition-all duration-200 hover:border-accent/40 hover:shadow-md flex flex-col justify-between"
                :class="
                  selected.includes(item.id)
                    ? 'border-accent ring-2 ring-accent/20 bg-accent/5'
                    : 'border-border'
                "
              >
                <div>
                  <!-- Top Row: Checkbox, Icon, Seq & Status -->
                  <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                      <Checkbox
                        :checked="selected.includes(item.id)"
                        @update:checked="toggleSelectItem(item.id)"
                      />

                      <div
                        class="h-10 w-10 rounded-xl bg-accent/10 border border-accent/20 flex items-center justify-center text-accent group-hover:bg-accent group-hover:text-accent-text group-hover:scale-105 transition-all duration-200 shrink-0 shadow-2xs"
                      >
                        <component :is="getIcon(item.icon)" class="h-5 w-5" />
                      </div>

                      <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 flex-wrap">
                          <span class="font-mono text-xs font-bold text-ink-900 bg-surface-50 border border-border px-1.5 py-0.5 rounded shadow-2xs">
                            {{ item.code }}
                          </span>
                          <span
                            v-if="item.module_code"
                            class="text-[10px] font-semibold uppercase tracking-wider bg-accent/10 text-accent border border-accent/20 px-1.5 py-0.5 rounded"
                          >
                            {{ item.module_code }}
                          </span>
                        </div>
                        <p class="text-xs text-ink-500 mt-0.5 flex items-center gap-1 font-mono">
                          <Hash class="h-3 w-3 text-ink-400" />
                          <span>Seq: {{ item.seq }}</span>
                        </p>
                      </div>
                    </div>

                    <StatusBadge :status="item.status_label" />
                  </div>

                  <!-- Middle: Caption & Hierarchy -->
                  <div class="mt-3.5 space-y-1.5">
                    <h3 class="text-base font-bold text-ink-900 group-hover:text-accent transition-colors truncate">
                      {{ item.menu_caption }}
                    </h3>

                    <!-- Parent submenu indicator -->
                    <p v-if="item.parent_caption" class="text-xs text-ink-500 flex items-center gap-1.5 truncate">
                      <CornerDownRight class="h-3.5 w-3.5 text-accent shrink-0" />
                      <span>Submenu dari: <strong class="text-ink-700">{{ item.parent_caption }}</strong></span>
                    </p>

                    <!-- Route / Link -->
                    <div class="flex items-center gap-1.5 text-xs text-ink-600 bg-surface-50 px-2.5 py-1.5 rounded-lg border border-border">
                      <ExternalLink class="h-3.5 w-3.5 text-ink-400 shrink-0" />
                      <span class="font-mono text-[11px] truncate">{{ item.menu_link || '#' }}</span>
                    </div>
                  </div>
                </div>

                <!-- Bottom Action Buttons -->
                <div class="mt-4 pt-3 border-t border-border flex items-center justify-between gap-2">
                  <span class="text-[11px] font-semibold text-ink-500 uppercase tracking-wider">
                    {{ item.menu_header || 'General' }}
                  </span>

                  <div class="flex items-center gap-1.5">
                    <Link
                      :href="route('config.menus.edit', item.id)"
                      class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold text-accent hover:bg-accent/10 transition-colors border border-transparent hover:border-accent/20 cursor-pointer"
                    >
                      <Pencil class="h-3.5 w-3.5" />
                      <span>Edit</span>
                    </Link>

                    <button
                      type="button"
                      class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold text-signal-danger hover:bg-signal-danger/10 transition-colors border border-transparent hover:border-signal-danger/20 cursor-pointer"
                      @click="confirmDelete(item)"
                    >
                      <Trash2 class="h-3.5 w-3.5" />
                      <span>Delete</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Pagination Bar for Card View -->
          <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-3 p-4 bg-surface-0 border border-border rounded-xl">
            <div class="text-xs text-ink-600">
              Menampilkan <span class="font-bold text-ink-900">{{ items.from ?? 0 }}</span> -
              <span class="font-bold text-ink-900">{{ items.to ?? 0 }}</span> dari
              <span class="font-bold text-ink-900">{{ items.total }}</span> menu
            </div>

            <div class="flex items-center gap-1.5 flex-wrap">
              <template v-for="(link, i) in items.links" :key="i">
                <Link
                  v-if="link.url"
                  :href="link.url"
                  class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer"
                  :class="
                    link.active
                      ? 'bg-accent text-accent-text font-bold shadow-2xs'
                      : 'bg-surface-50 text-ink-700 hover:bg-surface-100 hover:text-ink-900 border border-border'
                  "
                  v-html="link.label"
                />
                <span
                  v-else
                  class="px-3 py-1.5 rounded-lg text-xs text-ink-400 bg-surface-50 border border-border select-none"
                  v-html="link.label"
                />
              </template>
            </div>
          </div>
        </template>

        <!-- Empty State in Card View -->
        <EmptyState
          v-else
          title="No menus found"
          description="Try adjusting your search query or filters, or create a new menu entry."
        >
          <template #actions>
            <PrimaryButton :href="route('config.menus.create')">
              <Plus class="h-4 w-4 mr-1.5" />
              Create Menu
            </PrimaryButton>
          </template>
        </EmptyState>
      </div>

      <!-- TABLE LIST VIEW MODE -->
      <div v-else class="space-y-4">
        <DataTable
          :columns="columns"
          :items="items.data"
          v-model:sort="sort"
          v-model:selected="selected"
          v-model:search="search"
          v-model:filters="filters"
          v-model:per-page="perPage"
          selectable
          sticky-header
          storage-key="config.menus"
          search-placeholder="Search code, caption, link..."
          :filter-fields="filterFields"
          export-filename="config-menus"
          :total="items.total"
          :from="items.from"
          :to="items.to"
          :links="items.links"
          empty-title="No menus"
          empty-description="Create a menu so it can appear in the sidebar."
        >
          <template #bulk-actions>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline cursor-pointer"
              @click="confirmBulkDelete"
            >
              Delete selected
            </button>
          </template>

          <template #cell-seq="{ item }">
            <span class="font-mono text-xs text-ink-600">#{{ item.seq }}</span>
          </template>

          <template #cell-code="{ item }">
            <span class="font-mono text-xs font-bold text-ink-900 bg-surface-50 px-1.5 py-0.5 rounded border border-border">
              {{ item.code }}
            </span>
          </template>

          <template #cell-icon="{ item }">
            <div class="flex items-center gap-2">
              <div class="h-7 w-7 rounded-lg bg-accent/10 border border-accent/20 flex items-center justify-center text-accent shrink-0">
                <component :is="getIcon(item.icon)" class="h-3.5 w-3.5" />
              </div>
              <span class="font-mono text-xs text-ink-500">{{ item.icon || '-' }}</span>
            </div>
          </template>

          <template #cell-status_label="{ item }">
            <StatusBadge :status="item.status_label" />
          </template>

          <template #cell-menu_link="{ item }">
            <span class="font-mono text-xs text-ink-600">{{ item.menu_link || '#' }}</span>
          </template>

          <template #cell-actions="{ item }">
            <div class="flex items-center justify-end gap-3">
              <Link
                :href="route('config.menus.edit', item.id)"
                class="text-sm font-semibold text-accent hover:underline cursor-pointer"
              >
                Edit
              </Link>
              <button
                type="button"
                class="text-sm font-semibold text-signal-danger hover:underline cursor-pointer"
                @click="confirmDelete(item)"
              >
                Delete
              </button>
            </div>
          </template>
        </DataTable>
      </div>
    </div>
  </AppLayout>
</template>
