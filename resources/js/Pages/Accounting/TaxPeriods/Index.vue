<!-- ponytail: Accounting §3M tax period register (masa pajak). -->
<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { formatDate } from '@/Utils/formatters'

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

const columns = [
  { key: 'masa_pajak', label: 'Masa Pajak', sortable: true },
  { key: 'obligation_type', label: 'Obligation', sortable: true },
  { key: 'due_date', label: 'Due Date', sortable: true },
  { key: 'filing_status', label: 'Filing Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.tax-periods.index'), { company_id: companyId }, { preserveState: true })
}

const markFiled = (period: PeriodRow) => router.post(route('accounting.tax-periods.mark-filed', period.id), {}, { preserveScroll: true })
</script>

<template>
  <AppLayout>
    <PageHeader title="Tax Periods (Masa Pajak)" description="PPN and PPh statutory tax filing periods and deadlines.">
      <template #actions>
        <PrimaryButton :href="route('accounting.tax-periods.create', { company_id: selectedCompanyId })">
          + Register Period
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
        :items="periods"
        empty-title="No tax periods registered"
        empty-description="Register a new tax filing period to begin tracking masa pajak obligations."
      >
        <template #cell-masa_pajak="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ item.masa_pajak }}</span>
        </template>
        <template #cell-obligation_type="{ item }">
          <span class="font-semibold text-ink-700 uppercase text-xs">{{ item.obligation_type }}</span>
        </template>
        <template #cell-due_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate(item.due_date) }}</span>
        </template>
        <template #cell-filing_status="{ item }">
          <StatusBadge :status="item.filing_status" />
        </template>
        <template #cell-actions="{ item }">
          <button
            v-if="item.filing_status !== 'filed'"
            type="button"
            class="text-xs font-semibold text-accent hover:underline"
            @click="markFiled(item as PeriodRow)"
          >
            Mark Filed
          </button>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
