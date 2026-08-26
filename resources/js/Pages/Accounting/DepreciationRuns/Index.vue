<!-- ponytail: Accounting §3G monthly depreciation batch trigger — manual "run for period" v1, see DepreciationRunController docblock. -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { formatDate } from '@/Utils/formatters'

interface JournalRow {
  id: number
  journal_date: string
  memo: string | null
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  periods: Array<{ value: number; label: string }>
  recentJournals: JournalRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'journal_date', label: 'Posting Date', sortable: true },
  { key: 'memo', label: 'Journal Description', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.depreciation-runs.index'), { company_id: companyId }, { preserveState: true })
}

const form = useForm({ fiscal_period_id: null as number | null })
const submit = () => form.post(route('accounting.depreciation-runs.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Depreciation Runs" description="Posts commercial depreciation to the GL and records the parallel fiscal schedule for the selected period. Safe to re-run — already-scheduled assets are skipped.">
      <template #actions>
        <SecondaryButton :href="route('accounting.fixed-assets.index', { company_id: selectedCompanyId })">
          &larr; Back to Assets
        </SecondaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-6">
      <Panel title="Trigger Depreciation Run" class="max-w-2xl">
        <div class="mb-4 flex items-center gap-3">
          <label class="text-xs font-semibold text-ink-600">Company:</label>
          <select
            :value="selectedCompanyId"
            class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
            @change="switchCompany"
          >
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
          </select>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
          <FormSearchableSelect v-model="form.fiscal_period_id" name="fiscal_period_id" label="Fiscal Period" :options="periods" :error="form.errors.fiscal_period_id" required />
          <div class="flex justify-end">
            <PrimaryButton type="submit" :disabled="form.processing || !form.fiscal_period_id">
              Run Depreciation
            </PrimaryButton>
          </div>
        </form>
      </Panel>

      <div class="space-y-3">
        <h3 class="text-base font-semibold text-ink-900">Recent Depreciation Journals</h3>
        <DataTable
          :columns="columns"
          :items="recentJournals"
          v-model:sort="sort"
          v-model:selected="selected"
          v-model:search="search"
          sticky-header
          storage-key="accounting.depreciation-runs"
          search-placeholder="Search recent journals…"
          export-filename="depreciation-runs"
          empty-title="No depreciation runs yet"
          empty-description="Trigger monthly depreciation calculation above."
        >
          <template #cell-journal_date="{ item }">
            <span class="font-mono text-xs text-ink-700">{{ formatDate((item as JournalRow).journal_date) }}</span>
          </template>

          <template #cell-memo="{ item }">
            <Link
              :href="route('accounting.journals.show', (item as JournalRow).id)"
              class="font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as JournalRow).memo ?? `Journal #${(item as JournalRow).id}` }}
            </Link>
          </template>

          <template #cell-actions="{ item }">
            <div class="flex items-center justify-end">
              <Link
                :href="route('accounting.journals.show', (item as JournalRow).id)"
                class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              >
                View Journal &rarr;
              </Link>
            </div>
          </template>
        </DataTable>
      </div>
    </div>
  </AppLayout>
</template>
