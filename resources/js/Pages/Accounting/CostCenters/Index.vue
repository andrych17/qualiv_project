<!-- ponytail: Accounting §3B/§3I cost center dimension — depth-indented flat listing per company. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface CostCenterRow {
  id: number
  code: string
  name: string
  depth: number
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  costCenters: CostCenterRow[]
}>()

const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.costCenters
  return props.costCenters.filter((c) => c.name.toLowerCase().includes(q) || c.code.includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.cost-centers.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (costCenter: CostCenterRow) => {
  confirm({
    title: `Delete cost center "${costCenter.code} ${costCenter.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.cost-centers.destroy', costCenter.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Cost Centers" description="The canonical financial cost-center dimension — attachable to any journal line.">
      <template #actions>
        <Link :href="route('accounting.allocation-rules.index', { company_id: selectedCompanyId })" class="mr-4 text-sm font-medium text-accent hover:underline">Allocation rules</Link>
        <Link :href="route('accounting.budgets.index', { company_id: selectedCompanyId })" class="mr-4 text-sm font-medium text-accent hover:underline">Budget</Link>
        <PrimaryButton :href="route('accounting.cost-centers.create', { company_id: selectedCompanyId })">New cost center</PrimaryButton>
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
          placeholder="Search cost centers…"
          class="w-full max-w-xs rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Code</th>
            <th class="py-2">Name</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in filtered" :key="c.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ c.code }}</td>
            <td class="py-2 text-ink-900" :style="{ paddingLeft: `${8 + c.depth * 16}px` }">{{ c.name }}</td>
            <td class="py-2"><StatusBadge :status="c.is_active ? 'active' : 'inactive'" /></td>
            <td class="py-2 text-right">
              <Link :href="route('accounting.cost-centers.edit', c.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(c)">Delete</button>
            </td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="4" class="py-6 text-center text-ink-600">No cost centers yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
