<!-- ponytail: Accounting §3G fixed asset register. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface AssetRow {
  id: number
  asset_no: string
  name: string
  asset_group_name: string
  acquisition_date: string
  acquisition_cost: number
  status: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  assets: AssetRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.fixed-assets.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (asset: AssetRow) => {
  confirm({
    title: `Delete asset "${asset.asset_no}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.fixed-assets.destroy', asset.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Fixed Assets" description="Commercial GL depreciation runs monthly from Depreciation Runs; fiscal depreciation is a parallel schedule for SPT reconciliation.">
      <template #actions>
        <Link :href="route('accounting.depreciation-runs.index', { company_id: selectedCompanyId })" class="mr-4 text-sm font-medium text-accent hover:underline">Depreciation runs</Link>
        <Link :href="route('accounting.asset-groups.index', { company_id: selectedCompanyId })" class="mr-4 text-sm font-medium text-accent hover:underline">Asset groups</Link>
        <PrimaryButton :href="route('accounting.fixed-assets.create', { company_id: selectedCompanyId })">New asset</PrimaryButton>
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
            <th class="py-2">Asset #</th>
            <th class="py-2">Name</th>
            <th class="py-2">Group</th>
            <th class="py-2">Acquired</th>
            <th class="py-2 text-right">Cost</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in assets" :key="a.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2">
              <Link :href="route('accounting.fixed-assets.show', a.id)" class="font-medium text-accent hover:underline">{{ a.asset_no }}</Link>
            </td>
            <td class="py-2 text-ink-900">{{ a.name }}</td>
            <td class="py-2 text-ink-700">{{ a.asset_group_name }}</td>
            <td class="py-2 text-ink-700">{{ a.acquisition_date }}</td>
            <td class="py-2 text-right text-ink-700">{{ a.acquisition_cost.toFixed(2) }}</td>
            <td class="py-2"><StatusBadge :status="a.status" /></td>
            <td class="py-2 text-right">
              <Link v-if="a.status === 'active'" :href="route('accounting.fixed-assets.edit', a.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button v-if="a.status === 'active'" type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(a)">Delete</button>
            </td>
          </tr>
          <tr v-if="!assets.length"><td colspan="7" class="py-6 text-center text-ink-600">No assets yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
