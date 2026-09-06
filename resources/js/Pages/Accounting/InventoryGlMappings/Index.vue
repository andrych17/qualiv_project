<!-- ponytail: Accounting §3H — item/category → GL account mapping list. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface MappingRow {
  id: number
  scope_label: string
  inventory_asset_account: string
  cogs_account: string | null
  grni_account: string | null
  adjustment_account: string | null
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  mappings: MappingRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'scope_label', label: 'Mapping Scope', sortable: true },
  { key: 'inventory_asset_account', label: 'Inventory Asset (Debit/Credit)', sortable: true },
  { key: 'cogs_account', label: 'COGS Account' },
  { key: 'grni_account', label: 'GRNI Accrual Account' },
  { key: 'adjustment_account', label: 'Adjustment Account' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredMappings = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.mappings
  return props.mappings.filter(
    (m) =>
      m.scope_label.toLowerCase().includes(q) ||
      m.inventory_asset_account.toLowerCase().includes(q)
  )
})

const { confirm } = useConfirm()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.inventory-gl-mappings.index'), { company_id: companyId }, { preserveState: true })
}

const destroy = (m: MappingRow) => {
  confirm({
    title: 'Delete Inventory GL Mapping?',
    description: `Delete the mapping for "${m.scope_label}"? Movements for it will fail loudly and queue for review until it's remapped.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.inventory-gl-mappings.destroy', m.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Inventory GL Mappings" description="Which accounts an Inventory movement posts against — inventory-asset (always), COGS (issues), GRNI/accrual (receipts), adjustment (write-offs).">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.inventory-posting-failures.index', { company_id: selectedCompanyId })">
            Review Queue
          </SecondaryButton>
          <PrimaryButton :href="route('accounting.inventory-gl-mappings.create', { company_id: selectedCompanyId })">
            New Mapping
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Company:</label>
        <select
          :value="selectedCompanyId"
          class="rounded-md border border-border bg-surface-0 pl-3 pr-8 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 cursor-pointer"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <DataTable
        :columns="columns"
        :items="filteredMappings"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.inventory-gl-mappings"
        search-placeholder="Search mapping scope or GL account…"
        export-filename="inventory-gl-mappings"
        empty-title="No inventory GL mappings found"
        empty-description="Create account mappings for category or item-level inventory valuation and movements."
      >
        <template #cell-scope_label="{ item }">
          <Link
            :href="route('accounting.inventory-gl-mappings.edit', (item as MappingRow).id)"
            class="font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as MappingRow).scope_label }}
          </Link>
        </template>

        <template #cell-inventory_asset_account="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as MappingRow).inventory_asset_account }}</span>
        </template>

        <template #cell-cogs_account="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as MappingRow).cogs_account ?? '—' }}</span>
        </template>

        <template #cell-grni_account="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as MappingRow).grni_account ?? '—' }}</span>
        </template>

        <template #cell-adjustment_account="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as MappingRow).adjustment_account ?? '—' }}</span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.inventory-gl-mappings.edit', (item as MappingRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="destroy(item as MappingRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
