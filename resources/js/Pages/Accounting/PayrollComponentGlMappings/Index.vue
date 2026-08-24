<!-- ponytail: Accounting §3S — payroll component → GL account mapping list. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface MappingRow {
  id: number
  component_code: string
  component_label: string
  component_type: 'earning' | 'deduction' | 'employer_cost'
  gl_account: string
  payable_account: string | null
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  mappings: MappingRow[]
}>()

const switchCompany = (e: Event) => router.get(route('accounting.payroll-component-gl-mappings.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const typeLabel = (t: string) => ({ earning: 'Earning', deduction: 'Deduction', employer_cost: 'Employer cost' }[t] ?? t)

const destroy = (m: MappingRow) => {
  if (confirm(`Delete the mapping for "${m.component_code}"? Payroll runs using it will fail loudly and queue for review until it's remapped.`)) {
    router.delete(route('accounting.payroll-component-gl-mappings.destroy', m.id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Payroll GL mappings" description="Which accounts a Payroll component posts against — earning/employer-cost components debit an expense account, deductions credit a payable. A component with no mapping fails loudly and queues for review instead of guessing.">
      <template #actions>
        <Link :href="route('accounting.payroll-posting-failures.index', { company_id: selectedCompanyId })" class="mr-4 text-sm font-medium text-accent hover:underline">Review queue</Link>
        <PrimaryButton :href="route('accounting.payroll-component-gl-mappings.create', { company_id: selectedCompanyId })">New mapping</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <select :value="selectedCompanyId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchCompany">
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Code</th>
            <th class="py-2">Label</th>
            <th class="py-2">Type</th>
            <th class="py-2">GL account</th>
            <th class="py-2">Payable account</th>
            <th class="py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in mappings" :key="m.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2">
              <a :href="route('accounting.payroll-component-gl-mappings.edit', m.id)" class="font-medium text-accent hover:underline">{{ m.component_code }}</a>
            </td>
            <td class="py-2 text-ink-700">{{ m.component_label }}</td>
            <td class="py-2 text-ink-700">{{ typeLabel(m.component_type) }}</td>
            <td class="py-2 text-ink-700">{{ m.gl_account }}</td>
            <td class="py-2 text-ink-700">{{ m.payable_account ?? '—' }}</td>
            <td class="py-2 text-right">
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="destroy(m)">Delete</button>
            </td>
          </tr>
          <tr v-if="!mappings.length"><td colspan="6" class="py-6 text-center text-ink-600">No mappings yet — Payroll runs will fail loudly and queue for review until one exists.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
