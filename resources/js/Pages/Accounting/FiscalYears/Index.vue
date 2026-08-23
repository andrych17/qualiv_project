<!-- ponytail: Accounting §3B fiscal calendar — a fiscal year with its 12 periods; §3O period locking lives on each period row. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

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

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.fiscal-years.index'), { company_id: companyId }, { preserveState: true })
}

const setPeriodStatus = (periodId: number, status: string) => {
  router.put(route('accounting.fiscal-periods.status', periodId), { status }, { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Fiscal Years" description="Each fiscal year ships with its 12 monthly periods — posting is only allowed into an open period.">
      <template #actions>
        <PrimaryButton :href="route('accounting.fiscal-years.create', { company_id: selectedCompanyId })">New fiscal year</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <select
        :value="selectedCompanyId"
        class="mb-4 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @change="switchCompany"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
      </select>

      <div v-if="!fiscalYears.length" class="py-6 text-center text-ink-600">No fiscal years yet.</div>

      <div v-for="fy in fiscalYears" :key="fy.id" class="mb-6 rounded-sm border border-border">
        <div class="flex items-center justify-between border-b border-border bg-surface-50 px-4 py-3">
          <div class="text-sm font-semibold text-ink-900">FY {{ fy.year }} ({{ fy.start_date }} – {{ fy.end_date }})</div>
          <StatusBadge :status="fy.status" />
        </div>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
              <th class="py-2 pl-4">Period</th>
              <th class="py-2">Range</th>
              <th class="py-2">Status</th>
              <th class="py-2 pr-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in fy.periods" :key="p.id" class="border-b border-border last:border-b-0 hover:bg-surface-50">
              <td class="py-2 pl-4 text-ink-900">{{ p.period_no }}</td>
              <td class="py-2 text-ink-700">{{ p.start_date }} – {{ p.end_date }}</td>
              <td class="py-2"><StatusBadge :status="p.status" /></td>
              <td class="py-2 pr-4 text-right">
                <button v-if="p.status !== 'open'" type="button" class="mr-3 text-sm font-medium text-accent hover:underline" @click="setPeriodStatus(p.id, 'open')">Reopen</button>
                <button v-if="p.status !== 'soft_closed'" type="button" class="mr-3 text-sm font-medium text-accent hover:underline" @click="setPeriodStatus(p.id, 'soft_closed')">Soft-close</button>
                <button v-if="p.status !== 'hard_closed'" type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="setPeriodStatus(p.id, 'hard_closed')">Hard-close</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
