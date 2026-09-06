<!-- ponytail: Accounting §3P recurring journal templates — list, pause/resume, delete. Generation
     itself never happens from this screen (it's the nightly sweep's job); this is just CRUD
     over the template + a view of where each one stands. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatDate } from '@/Utils/formatters'

interface TemplateRow {
  id: number
  name: string
  recurrence_rule: string
  next_run_date: string | null
  last_run_date: string | null
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  templates: TemplateRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'name', label: 'Template Name', sortable: true },
  { key: 'recurrence_rule', label: 'Recurrence Rule' },
  { key: 'last_run_date', label: 'Last Run' },
  { key: 'next_run_date', label: 'Next Run', sortable: true },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredTemplates = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.templates
  return props.templates.filter((t) => t.name.toLowerCase().includes(q))
})

const switchCompany = (e: Event) => router.get(route('accounting.recurring-journal-templates.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const toggleActive = (t: TemplateRow) => router.post(route('accounting.recurring-journal-templates.set-active', t.id), { is_active: !t.is_active }, { preserveScroll: true })

const { confirm } = useConfirm()

const destroy = (t: TemplateRow) => {
  confirm({
    title: 'Delete Recurring Template?',
    description: `Delete recurring template "${t.name}"? This does not affect journals already generated.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.recurring-journal-templates.destroy', t.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Recurring Journals" description="A journal draft is generated automatically each time the rule comes due — always a draft, never posted, until reviewed.">
      <template #actions>
        <PrimaryButton :href="route('accounting.recurring-journal-templates.create', { company_id: selectedCompanyId })">
          New Template
        </PrimaryButton>
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
        :items="filteredTemplates"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.recurring-journal-templates"
        search-placeholder="Search template name…"
        export-filename="recurring-journal-templates"
        status-rail-key="is_active"
        empty-title="No recurring journal templates found"
        empty-description="Define automatic recurring journal schedules for monthly depreciation, amortization, or accruals."
      >
        <template #cell-name="{ item }">
          <Link
            :href="route('accounting.recurring-journal-templates.edit', (item as TemplateRow).id)"
            class="font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as TemplateRow).name }}
          </Link>
        </template>

        <template #cell-recurrence_rule="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as TemplateRow).recurrence_rule }}</span>
        </template>

        <template #cell-last_run_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as TemplateRow).last_run_date ? formatDate((item as TemplateRow).last_run_date!) : '—' }}</span>
        </template>

        <template #cell-next_run_date="{ item }">
          <span class="font-mono text-xs font-medium text-ink-900">{{ (item as TemplateRow).next_run_date ? formatDate((item as TemplateRow).next_run_date!) : 'Exhausted' }}</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as TemplateRow).is_active ? 'active' : 'paused'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.recurring-journal-templates.edit', (item as TemplateRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-ink-600 hover:text-ink-900 hover:underline"
              @click="toggleActive(item as TemplateRow)"
            >
              {{ (item as TemplateRow).is_active ? 'Pause' : 'Resume' }}
            </button>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="destroy(item as TemplateRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
