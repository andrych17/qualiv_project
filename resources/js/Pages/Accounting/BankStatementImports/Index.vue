<!-- ponytail: Accounting §3F bank statement imports — plain company-scoped list. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { formatDate } from '@/Utils/formatters'

interface ImportRow {
  id: number
  bank_account_name: string | null
  original_filename: string
  line_count: number
  imported_at: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  imports: ImportRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'bank_account_name', label: 'Bank Account', sortable: true },
  { key: 'original_filename', label: 'File Name', sortable: true },
  { key: 'line_count', label: 'Lines', sortable: true, align: 'right' as const },
  { key: 'imported_at', label: 'Imported At', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredImports = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.imports
  return props.imports.filter((i) => (i.bank_account_name ?? '').toLowerCase().includes(q) || i.original_filename.toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.bank-statement-imports.index'), { company_id: companyId }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Bank Statement Imports" description="Staged for reconciliation — matching against journal entries and GL cash subledger.">
      <template #actions>
        <PrimaryButton :href="route('accounting.bank-statement-imports.create', { company_id: selectedCompanyId })">
          Import Statement
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
        :items="filteredImports"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.bank-statement-imports"
        search-placeholder="Search bank account or filename…"
        export-filename="bank-statement-imports"
        empty-title="No statement imports found"
        empty-description="Import bank MT940 or CSV statements for reconciliation."
      >
        <template #cell-bank_account_name="{ item }">
          <span class="font-medium text-ink-900">{{ (item as ImportRow).bank_account_name ?? '—' }}</span>
        </template>

        <template #cell-original_filename="{ item }">
          <Link
            :href="route('accounting.bank-statement-imports.show', (item as ImportRow).id)"
            class="font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as ImportRow).original_filename }}
          </Link>
        </template>

        <template #cell-line_count="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">{{ (item as ImportRow).line_count }}</span>
        </template>

        <template #cell-imported_at="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate((item as ImportRow).imported_at) }}</span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end">
            <Link
              :href="route('accounting.bank-statement-imports.show', (item as ImportRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              View &rarr;
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
