<!-- ponytail: Accounting §3M PPN tax codes — plain company-scoped list, same convention as CostCenters. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface TaxCodeRow {
  id: number
  code: string
  rate: number
  tax_type: string
  gl_account_label: string
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  taxCodes: TaxCodeRow[]
}>()

const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.taxCodes
  return props.taxCodes.filter((t) => t.code.toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.tax-codes.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (taxCode: TaxCodeRow) => {
  confirm({
    title: `Delete tax code "${taxCode.code}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.tax-codes.destroy', taxCode.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Tax Codes" description="PPN rates and their output/input treatment — configurable per company so a rate change is a data edit, not a deploy.">
      <template #actions>
        <PrimaryButton :href="route('accounting.tax-codes.create', { company_id: selectedCompanyId })">New tax code</PrimaryButton>
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
          placeholder="Search tax codes…"
          class="w-full max-w-xs rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Code</th>
            <th class="py-2">Rate</th>
            <th class="py-2">Type</th>
            <th class="py-2">GL account</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in filtered" :key="t.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ t.code }}</td>
            <td class="py-2 text-ink-700">{{ t.rate }}%</td>
            <td class="py-2 text-ink-700 capitalize">{{ t.tax_type }}</td>
            <td class="py-2 text-ink-700">{{ t.gl_account_label }}</td>
            <td class="py-2"><StatusBadge :status="t.is_active ? 'active' : 'inactive'" /></td>
            <td class="py-2 text-right">
              <Link :href="route('accounting.tax-codes.edit', t.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(t)">Delete</button>
            </td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="6" class="py-6 text-center text-ink-600">No tax codes yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
