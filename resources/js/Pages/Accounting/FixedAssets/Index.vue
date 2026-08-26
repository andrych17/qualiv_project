<!-- ponytail: Accounting §3G fixed asset register. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface AssetRow {
  id: number
  asset_no: string
  name: string
  asset_group_name: string
  acquisition_date: string
  acquisition_cost: number
  status: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  assets: AssetRow[]
}>()

const search = ref('')
const filters = ref({
  status: '',
})
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'active' },
      { label: 'Disposed', value: 'disposed' },
      { label: 'Draft', value: 'draft' },
    ],
  },
]

const columns = [
  { key: 'asset_no', label: 'Asset #', sortable: true },
  { key: 'name', label: 'Asset Name', sortable: true },
  { key: 'asset_group_name', label: 'Group', sortable: true },
  { key: 'acquisition_date', label: 'Acquired Date', sortable: true },
  { key: 'acquisition_cost', label: 'Acquisition Cost', sortable: true, align: 'right' as const },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredAssets = computed(() => {
  return props.assets.filter((a) => {
    if (search.value) {
      const q = search.value.toLowerCase()
      if (!a.asset_no.toLowerCase().includes(q) && !a.name.toLowerCase().includes(q)) {
        return false
      }
    }
    if (filters.value.status && a.status !== filters.value.status) {
      return false
    }
    return true
  })
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.fixed-assets.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (asset: AssetRow) => {
  confirm({
    title: `Delete asset "${asset.asset_no}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.fixed-assets.destroy', asset.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Fixed Assets" description="Commercial GL depreciation runs monthly from Depreciation Runs; fiscal depreciation is a parallel schedule for SPT reconciliation.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.depreciation-runs.index', { company_id: selectedCompanyId })">
            Depreciation Runs
          </SecondaryButton>
          <SecondaryButton :href="route('accounting.asset-groups.index', { company_id: selectedCompanyId })">
            Asset Groups
          </SecondaryButton>
          <PrimaryButton :href="route('accounting.fixed-assets.create', { company_id: selectedCompanyId })">
            New Asset
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Company:</label>
        <select
          :value="selectedCompanyId"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <DataTable
        :columns="columns"
        :items="filteredAssets"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        sticky-header
        storage-key="accounting.fixed-assets"
        search-placeholder="Search asset # or name…"
        :filter-fields="filterFields"
        export-filename="fixed-assets"
        status-rail-key="status"
        empty-title="No fixed assets found"
        empty-description="Register capital assets for commercial and tax depreciation."
      >
        <template #cell-asset_no="{ item }">
          <Link
            :href="route('accounting.fixed-assets.show', (item as AssetRow).id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as AssetRow).asset_no }}
          </Link>
        </template>

        <template #cell-name="{ item }">
          <span class="font-medium text-ink-900">{{ (item as AssetRow).name }}</span>
        </template>

        <template #cell-asset_group_name="{ item }">
          <span class="text-xs text-ink-700 font-medium">{{ (item as AssetRow).asset_group_name }}</span>
        </template>

        <template #cell-acquisition_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate((item as AssetRow).acquisition_date) }}</span>
        </template>

        <template #cell-acquisition_cost="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">
            {{ formatCurrency((item as AssetRow).acquisition_cost) }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as AssetRow).status" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              v-if="(item as AssetRow).status === 'active'"
              :href="route('accounting.fixed-assets.edit', (item as AssetRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              v-if="(item as AssetRow).status === 'active'"
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as AssetRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
