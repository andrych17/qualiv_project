<!-- ponytail: Accounting §3M PPh withholding types — plain company-scoped list. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface WithholdingTypeRow {
  id: number
  code: string
  bp_type: string | null
  name: string
  rate: number
  is_final: boolean
  gl_account_label: string
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  withholdingTypes: WithholdingTypeRow[]
}>()

const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.withholdingTypes
  return props.withholdingTypes.filter((w) => w.code.toLowerCase().includes(q) || w.name.toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.withholding-types.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (w: WithholdingTypeRow) => {
  confirm({
    title: `Delete withholding type "${w.code}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.withholding-types.destroy', w.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Withholding Types" description="PPh withholding family — code, rate, final/non-final, and the payable GL account.">
      <template #actions>
        <PrimaryButton :href="route('accounting.withholding-types.create', { company_id: selectedCompanyId })">New withholding type</PrimaryButton>
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
          placeholder="Search withholding types…"
          class="w-full max-w-xs rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Code</th>
            <th class="py-2">BP type</th>
            <th class="py-2">Name</th>
            <th class="py-2">Rate</th>
            <th class="py-2">Final</th>
            <th class="py-2">Payable account</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="w in filtered" :key="w.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ w.code }}</td>
            <td class="py-2 text-ink-700">{{ w.bp_type ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ w.name }}</td>
            <td class="py-2 text-ink-700">{{ w.rate }}%</td>
            <td class="py-2 text-ink-700">{{ w.is_final ? 'Yes' : '—' }}</td>
            <td class="py-2 text-ink-700">{{ w.gl_account_label }}</td>
            <td class="py-2"><StatusBadge :status="w.is_active ? 'active' : 'inactive'" /></td>
            <td class="py-2 text-right">
              <Link :href="route('accounting.withholding-types.edit', w.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(w)">Delete</button>
            </td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="8" class="py-6 text-center text-ink-600">No withholding types yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
