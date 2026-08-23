<!-- ponytail: Accounting §3C General Ledger / Journal Entries — list, filterable by company/status/source. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface JournalRow {
  id: number
  journal_date: string
  memo: string | null
  source: string
  status: string
  period_no: number | null
  currency_code: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  journals: JournalRow[]
}>()

const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.journals
  return props.journals.filter((j) => (j.memo ?? '').toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.journals.index'), { company_id: companyId }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Journals" description="Every posting — manual today, every subledger engine later — goes through this single ledger.">
      <template #actions>
        <PrimaryButton :href="route('accounting.journals.create', { company_id: selectedCompanyId })">New journal</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <select
          :value="selectedCompanyId"
          class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>

        <input
          v-model="search"
          type="text"
          placeholder="Search memo…"
          class="w-full max-w-xs rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Date</th>
            <th class="py-2">Memo</th>
            <th class="py-2">Source</th>
            <th class="py-2">Period</th>
            <th class="py-2">Currency</th>
            <th class="py-2">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="j in filtered" :key="j.id" class="cursor-pointer border-b border-border hover:bg-surface-50" @click="router.get(route('accounting.journals.show', j.id))">
            <td class="py-2 text-ink-900">{{ j.journal_date }}</td>
            <td class="py-2 text-ink-700">
              <Link :href="route('accounting.journals.show', j.id)" class="text-accent hover:underline" @click.stop>{{ j.memo ?? `Journal #${j.id}` }}</Link>
            </td>
            <td class="py-2 text-ink-700 capitalize">{{ j.source }}</td>
            <td class="py-2 text-ink-700">{{ j.period_no ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ j.currency_code }}</td>
            <td class="py-2"><StatusBadge :status="j.status" /></td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="6" class="py-6 text-center text-ink-600">No journals yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
