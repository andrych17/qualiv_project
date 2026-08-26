<!-- ponytail: Accounting §3B fiscal calendar — a fiscal year with its 12 periods; §3O period locking lives on each period row. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatDate } from '@/Utils/formatters'

interface PeriodRow {
  id: number
  period_no: number
  start_date: string
  end_date: string
  status: string
}

interface FiscalYearRow {
  id: number
  year: number
  start_date: string
  end_date: string
  status: string
  periods: PeriodRow[]
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  fiscalYears: FiscalYearRow[]
}>()

const { confirm } = useConfirm()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.fiscal-years.index'), { company_id: companyId }, { preserveState: true })
}

const setPeriodStatus = (periodId: number, periodNo: number, status: string) => {
  if (status === 'hard_closed') {
    confirm({
      title: `Hard-Close Period ${periodNo}?`,
      description: 'Hard-closing is final and locks all GL postings permanently.',
      variant: 'destructive',
      confirmText: 'Hard-Close',
      onConfirm: () => router.put(route('accounting.fiscal-periods.status', periodId), { status }, { preserveScroll: true }),
    })
    return
  }
  router.put(route('accounting.fiscal-periods.status', periodId), { status }, { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Fiscal Years &amp; Period Locking" description="Each fiscal year contains 12 monthly periods. Postings are only permitted in open periods.">
      <template #actions>
        <PrimaryButton :href="route('accounting.fiscal-years.create', { company_id: selectedCompanyId })">
          + New Fiscal Year
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-6">
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

      <div v-if="!fiscalYears.length" class="p-8 text-center text-ink-500 bg-surface border border-border rounded-lg">
        No fiscal years configured for this company.
      </div>

      <Panel v-for="fy in fiscalYears" :key="fy.id" class="overflow-hidden">
        <template #header>
          <div class="flex items-center justify-between w-full">
            <div>
              <span class="font-bold text-ink-900 text-base">FY {{ fy.year }}</span>
              <span class="ml-3 text-xs font-mono text-ink-600">({{ formatDate(fy.start_date) }} – {{ formatDate(fy.end_date) }})</span>
            </div>
            <StatusBadge :status="fy.status" />
          </div>
        </template>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                <th class="py-3 px-4">Period</th>
                <th class="py-3 px-4">Date Range</th>
                <th class="py-3 px-4">Lock Status</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border bg-surface">
              <tr v-for="p in fy.periods" :key="p.id" class="hover:bg-surface-50/75 transition-colors">
                <td class="py-3 px-4 font-semibold text-ink-900">Period {{ p.period_no }}</td>
                <td class="py-3 px-4 font-mono text-xs text-ink-700">{{ formatDate(p.start_date) }} – {{ formatDate(p.end_date) }}</td>
                <td class="py-3 px-4"><StatusBadge :status="p.status" /></td>
                <td class="py-3 px-4 text-right">
                  <div class="flex items-center justify-end gap-3 text-xs font-semibold">
                    <button v-if="p.status !== 'open'" type="button" class="text-accent hover:underline" @click="setPeriodStatus(p.id, p.period_no, 'open')">Reopen</button>
                    <button v-if="p.status !== 'soft_closed'" type="button" class="text-accent hover:underline" @click="setPeriodStatus(p.id, p.period_no, 'soft_closed')">Soft-Close</button>
                    <button v-if="p.status !== 'hard_closed'" type="button" class="text-signal-danger hover:underline" @click="setPeriodStatus(p.id, p.period_no, 'hard_closed')">Hard-Close</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
