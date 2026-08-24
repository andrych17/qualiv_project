<!-- ponytail: Accounting §3G asset groups — Indonesian fiscal tax classification, tenant-editable. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface AssetGroupRow {
  id: number
  code: string
  name: string
  is_building: boolean
  fiscal_useful_life_months: number
  fiscal_straight_line_rate: string
  fiscal_declining_rate: string | null
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  assetGroups: AssetGroupRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.asset-groups.index'), { company_id: companyId }, { preserveState: true })
}

const seedStarter = () => {
  if (!props.selectedCompanyId) return
  router.post(route('accounting.asset-groups.seed-starter', props.selectedCompanyId))
}

const { confirm } = useConfirm()
const confirmDelete = (group: AssetGroupRow) => {
  confirm({
    title: `Delete asset group "${group.code}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.asset-groups.destroy', group.id)),
  })
}

const pct = (rate: string | null) => (rate === null ? '—' : `${(Number(rate) * 100).toFixed(2)}%`)
</script>

<template>
  <AppLayout>
    <PageHeader title="Asset Groups" description="Indonesian fiscal tax classification (Kelompok 1-4, Bangunan) — rates are data, editable per PMK updates, never hardcoded.">
      <template #actions>
        <button v-if="!assetGroups.length" type="button" class="mr-3 text-sm font-medium text-accent hover:underline" @click="seedStarter">Seed starter groups</button>
        <PrimaryButton :href="route('accounting.asset-groups.create', { company_id: selectedCompanyId })">New group</PrimaryButton>
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
            <th class="py-2">Code</th>
            <th class="py-2">Name</th>
            <th class="py-2">Building?</th>
            <th class="py-2 text-right">Useful life (mo)</th>
            <th class="py-2 text-right">Straight-line</th>
            <th class="py-2 text-right">Declining</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="g in assetGroups" :key="g.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 font-medium text-ink-900">{{ g.code }}</td>
            <td class="py-2 text-ink-700">{{ g.name }}</td>
            <td class="py-2 text-ink-700">{{ g.is_building ? 'Yes' : 'No' }}</td>
            <td class="py-2 text-right text-ink-700">{{ g.fiscal_useful_life_months }}</td>
            <td class="py-2 text-right text-ink-700">{{ pct(g.fiscal_straight_line_rate) }}</td>
            <td class="py-2 text-right text-ink-700">{{ pct(g.fiscal_declining_rate) }}</td>
            <td class="py-2"><StatusBadge :status="g.is_active ? 'active' : 'inactive'" /></td>
            <td class="py-2 text-right">
              <Link :href="route('accounting.asset-groups.edit', g.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(g)">Delete</button>
            </td>
          </tr>
          <tr v-if="!assetGroups.length"><td colspan="8" class="py-6 text-center text-ink-600">No asset groups yet — seed the starter set or add one.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
