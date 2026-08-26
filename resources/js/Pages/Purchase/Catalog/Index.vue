<!-- Purchase Catalog Management list (§3I) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface CatalogItem {
  id: number
  item_code: string
  description: string
  category_name: string | null
  unit: string
  preferred_supplier_name: string | null
  negotiated_price: number | null
  price_valid_from: string | null
  price_valid_to: string | null
  source: string
  is_active: boolean
}

const props = defineProps<{ catalogItems: CatalogItem[] }>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'item_code', label: 'Item Code', sortable: true },
  { key: 'description', label: 'Description', sortable: true },
  { key: 'category_name', label: 'Category', sortable: true },
  { key: 'preferred_supplier_name', label: 'Preferred Supplier', sortable: true },
  { key: 'negotiated_price', label: 'Negotiated Price', sortable: true, align: 'right' as const },
  { key: 'validity', label: 'Validity Period' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const formatCurrency = (val: number | null) => {
  if (val === null) return '—'
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val)
}

const toggleActive = (id: number) => {
  router.post(route('purchase.catalog.toggle', id))
}

const filteredItems = computed(() => {
  let list = props.catalogItems
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((i) =>
      (i.item_code ?? '').toLowerCase().includes(q) ||
      (i.description ?? '').toLowerCase().includes(q) ||
      (i.category_name ?? '').toLowerCase().includes(q) ||
      (i.preferred_supplier_name ?? '').toLowerCase().includes(q)
    )
  }
  if (sort.value) {
    const { key, direction } = sort.value
    list = [...list].sort((a, b) => {
      const av = (a as unknown as Record<string, unknown>)[key]
      const bv = (b as unknown as Record<string, unknown>)[key]
      if (typeof av === 'number' && typeof bv === 'number') {
        return direction === 'asc' ? av - bv : bv - av
      }
      const as = String(av ?? '')
      const bs = String(bv ?? '')
      return direction === 'asc' ? as.localeCompare(bs) : bs.localeCompare(as)
    })
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Item Catalog" description="Standardized items, pre-negotiated prices, and preferred supplier pricing (§3I).">
      <template #actions>
        <PrimaryButton :href="route('purchase.catalog.create')">Add catalog item</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="catalog" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredItems"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search item code, description, or supplier…"
        empty-title="No catalog items yet"
        empty-description="Create catalog items with negotiated rates to streamline requisition and purchase ordering."
      >
        <template #cell-item_code="{ item }">
          <Link :href="route('purchase.catalog.edit', item.id)" class="text-sm font-semibold text-accent hover:underline">
            {{ item.item_code }}
          </Link>
          <div class="text-xs text-ink-500">{{ item.unit }}</div>
        </template>
        <template #cell-description="{ item }">
          <div class="text-sm font-medium text-ink-900">{{ item.description }}</div>
        </template>
        <template #cell-category_name="{ item }">
          <div class="text-sm text-ink-700">{{ item.category_name ?? '—' }}</div>
        </template>
        <template #cell-preferred_supplier_name="{ item }">
          <div class="text-sm text-ink-900">{{ item.preferred_supplier_name ?? '—' }}</div>
        </template>
        <template #cell-negotiated_price="{ item }">
          <div class="text-sm font-semibold text-ink-900">{{ formatCurrency(item.negotiated_price) }}</div>
        </template>
        <template #cell-validity="{ item }">
          <div v-if="item.price_valid_from || item.price_valid_to" class="text-xs text-ink-700">
            {{ item.price_valid_from ?? '∞' }} → {{ item.price_valid_to ?? '∞' }}
          </div>
          <div v-else class="text-xs text-ink-400">Open-ended</div>
        </template>
        <template #cell-is_active="{ item }">
          <span
            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
            :class="item.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-ink-100 text-ink-600'"
          >
            {{ item.is_active ? 'Active' : 'Inactive' }}
          </span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('purchase.catalog.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              Edit
            </Link>
            <span class="text-ink-300">|</span>
            <button
              type="button"
              class="text-sm font-medium text-ink-600 hover:text-ink-900"
              @click="toggleActive(item.id)"
            >
              {{ item.is_active ? 'Deactivate' : 'Activate' }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
