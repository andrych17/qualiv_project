<!-- ponytail: Accounting §3M tax period register (masa pajak). -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface PeriodRow {
  id: number
  obligation_type: string
  masa_pajak: string
  due_date: string
  filing_status: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  periods: PeriodRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.tax-periods.index'), { company_id: companyId }, { preserveState: true })
}

const markFiled = (period: PeriodRow) => router.post(route('accounting.tax-periods.mark-filed', period.id), {}, { preserveScroll: true })
</script>

<template>
  <AppLayout>
    <PageHeader title="Tax Periods" description="PPN and PPh obligations per masa pajak — due-date reminders via WNE are a later build; this is the register itself.">
      <template #actions>
        <PrimaryButton :href="route('accounting.tax-periods.create', { company_id: selectedCompanyId })">Register period</PrimaryButton>
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

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Masa pajak</th>
            <th class="py-2">Obligation</th>
            <th class="py-2">Due date</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in periods" :key="p.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ p.masa_pajak }}</td>
            <td class="py-2 text-ink-700 uppercase">{{ p.obligation_type }}</td>
            <td class="py-2 text-ink-700">{{ p.due_date }}</td>
            <td class="py-2"><StatusBadge :status="p.filing_status" /></td>
            <td class="py-2 text-right">
              <button v-if="p.filing_status !== 'filed'" type="button" class="text-sm font-medium text-accent hover:underline" @click="markFiled(p)">Mark filed</button>
            </td>
          </tr>
          <tr v-if="!periods.length"><td colspan="5" class="py-6 text-center text-ink-600">No tax periods registered yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
