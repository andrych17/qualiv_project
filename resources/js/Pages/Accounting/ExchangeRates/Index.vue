<!-- ponytail: Accounting §3L exchange rates — plain company-scoped list, same convention as TaxCodes. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface RateRow {
  id: number
  currency_code: string
  rate_to_base: number
  effective_date: string
  source: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  baseCurrency: string | null
  rates: RateRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.exchange-rates.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (rate: RateRow) => {
  confirm({
    title: `Delete the ${rate.currency_code} rate effective ${rate.effective_date}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.exchange-rates.destroy', rate.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Exchange Rates" :description="`Rate to base currency (${baseCurrency ?? '—'}), effective on a date — AR/AP posting picks the most recent rate on or before the transaction date.`">
      <template #actions>
        <PrimaryButton :href="route('accounting.exchange-rates.create', { company_id: selectedCompanyId })">New rate</PrimaryButton>
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
            <th class="py-2">Currency</th>
            <th class="py-2 text-right">Rate to {{ baseCurrency ?? 'base' }}</th>
            <th class="py-2">Effective date</th>
            <th class="py-2">Source</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rates" :key="r.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ r.currency_code }}</td>
            <td class="py-2 text-right text-ink-700">{{ r.rate_to_base }}</td>
            <td class="py-2 text-ink-700">{{ r.effective_date }}</td>
            <td class="py-2 text-ink-700 capitalize">{{ r.source }}</td>
            <td class="py-2 text-right">
              <Link :href="route('accounting.exchange-rates.edit', r.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(r)">Delete</button>
            </td>
          </tr>
          <tr v-if="!rates.length"><td colspan="5" class="py-6 text-center text-ink-600">No exchange rates yet — {{ baseCurrency ?? 'the base currency' }}-only transactions don't need one.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
