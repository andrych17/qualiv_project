<!-- ponytail: Accounting §3M PPh withholding types — plain company-scoped list. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface WithholdingTypeRow {
  id: number
  code: string
  bp_type: string | null
  name: string
  rate: number
  is_final: boolean
  gl_account_label: string
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  withholdingTypes: WithholdingTypeRow[]
}>()

const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.withholdingTypes
  return props.withholdingTypes.filter((w) => w.code.toLowerCase().includes(q) || w.name.toLowerCase().includes(q))
})

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'bp_type', label: 'BP Type', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'rate', label: 'Rate (%)', align: 'right' as const, sortable: true },
  { key: 'is_final', label: 'Final' },
  { key: 'gl_account_label', label: 'Payable Account' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.withholding-types.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (w: WithholdingTypeRow) => {
  confirm({
    title: `Delete withholding type "${w.code}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.withholding-types.destroy', w.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Withholding Tax Types (PPh)" description="PPh withholding types, percentage rates, final status, and payable accounts.">
      <template #actions>
        <PrimaryButton :href="route('accounting.withholding-types.create', { company_id: selectedCompanyId })">
          + New Withholding Type
        </PrimaryButton>
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
        :items="filtered"
        :search="search"
        search-placeholder="Search withholding types..."
        empty-title="No withholding types configured"
        empty-description="Create your first PPh withholding type to support AP bill withholding tax deductions."
        @update:search="search = $event"
      >
        <template #cell-code="{ item }">
          <span class="font-mono font-semibold text-ink-900">{{ item.code }}</span>
        </template>
        <template #cell-bp_type="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.bp_type ?? '—' }}</span>
        </template>
        <template #cell-name="{ item }">
          <span class="font-medium text-ink-900">{{ item.name }}</span>
        </template>
        <template #cell-rate="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">{{ item.rate }}%</span>
        </template>
        <template #cell-is_final="{ item }">
          <span class="text-xs font-medium" :class="item.is_final ? 'text-accent font-semibold' : 'text-ink-500'">{{ item.is_final ? 'Yes' : '—' }}</span>
        </template>
        <template #cell-gl_account_label="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ item.gl_account_label }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="item.is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3 text-xs font-semibold">
            <Link :href="route('accounting.withholding-types.edit', item.id)" class="text-accent hover:underline">Edit</Link>
            <button type="button" class="text-signal-danger hover:underline" @click="confirmDelete(item as WithholdingTypeRow)">Delete</button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
