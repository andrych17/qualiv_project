<!-- ponytail: Accounting §3B/§3I cost center dimension — depth-indented flat listing per company. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface CostCenterRow {
  id: number
  code: string
  name: string
  depth: number
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  costCenters: CostCenterRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Cost Center Name', sortable: true },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredCostCenters = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.costCenters
  return props.costCenters.filter((c) => c.name.toLowerCase().includes(q) || c.code.includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.cost-centers.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (costCenter: CostCenterRow) => {
  confirm({
    title: `Delete cost center "${costCenter.code} ${costCenter.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.cost-centers.destroy', costCenter.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Cost Centers" description="The canonical financial cost-center dimension — attachable to any journal line.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.allocation-rules.index', { company_id: selectedCompanyId })">
            Allocation Rules
          </SecondaryButton>
          <SecondaryButton :href="route('accounting.budgets.index', { company_id: selectedCompanyId })">
            Budget
          </SecondaryButton>
          <PrimaryButton :href="route('accounting.cost-centers.create', { company_id: selectedCompanyId })">
            New Cost Center
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
        :items="filteredCostCenters"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.cost-centers"
        search-placeholder="Search cost centers by code or name…"
        export-filename="cost-centers"
        status-rail-key="is_active"
        empty-title="No cost centers found"
        empty-description="Create hierarchical cost centers for department budgeting and allocations."
      >
        <template #cell-code="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as CostCenterRow).code }}</span>
        </template>

        <template #cell-name="{ item }">
          <span
            class="font-medium text-ink-900 block"
            :style="{ paddingLeft: `${(item as CostCenterRow).depth * 16}px` }"
          >
            <span v-if="(item as CostCenterRow).depth > 0" class="text-ink-400 mr-1 font-mono">└</span>
            {{ (item as CostCenterRow).name }}
          </span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as CostCenterRow).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.cost-centers.edit', (item as CostCenterRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as CostCenterRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
