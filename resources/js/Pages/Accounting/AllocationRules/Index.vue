<!-- ponytail: Accounting §3I allocation rules — list, pause/resume, delete, run. Mirrors
     RecurringJournalTemplates/Index.vue's shape. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface RuleRow {
  id: number
  name: string
  source_account: string
  source_cost_center: string
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  rules: RuleRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'name', label: 'Rule Name', sortable: true },
  { key: 'source_account', label: 'Source Account', sortable: true },
  { key: 'source_cost_center', label: 'Source Cost Center', sortable: true },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredRules = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.rules
  return props.rules.filter((r) => r.name.toLowerCase().includes(q) || r.source_account.toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.allocation-rules.index'), { company_id: companyId }, { preserveState: true })
}

const toggleActive = (r: RuleRow) => {
  router.post(route('accounting.allocation-rules.set-active', r.id), { is_active: !r.is_active }, { preserveScroll: true })
}

const { confirm } = useConfirm()
const destroy = (r: RuleRow) => {
  confirm({
    title: 'Delete Allocation Rule?',
    description: `Delete allocation rule "${r.name}"? Only possible if it has never been run.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.allocation-rules.destroy', r.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Allocation Rules" description="Percentage-based cost redistribution — running a rule posts a same-account journal that moves cost-center attribution, never the account's total balance.">
      <template #actions>
        <PrimaryButton :href="route('accounting.allocation-rules.create', { company_id: selectedCompanyId })">
          New Rule
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
        :items="filteredRules"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.allocation-rules"
        search-placeholder="Search allocation rules…"
        export-filename="allocation-rules"
        status-rail-key="is_active"
        empty-title="No allocation rules found"
        empty-description="Define percentage-based overhead or shared cost allocation rules."
      >
        <template #cell-name="{ item }">
          <Link
            :href="route('accounting.allocation-rules.edit', (item as RuleRow).id)"
            class="font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as RuleRow).name }}
          </Link>
        </template>

        <template #cell-source_account="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as RuleRow).source_account }}</span>
        </template>

        <template #cell-source_cost_center="{ item }">
          <span class="text-xs text-ink-700">{{ (item as RuleRow).source_cost_center }}</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as RuleRow).is_active ? 'active' : 'paused'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.allocation-rules.run.show', (item as RuleRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Run
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-ink-600 hover:text-ink-900 hover:underline"
              @click="toggleActive(item as RuleRow)"
            >
              {{ (item as RuleRow).is_active ? 'Pause' : 'Resume' }}
            </button>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="destroy(item as RuleRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
