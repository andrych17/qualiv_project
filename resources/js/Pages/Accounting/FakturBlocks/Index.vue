<!-- ponytail: Accounting §3M — tenant-entered DJP Faktur Pajak number-allocation blocks. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface BlockRow {
  id: number
  prefix: string
  range_start: number
  range_end: number
  last_issued: number | null
  remaining: number
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  blocks: BlockRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.faktur-blocks.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDeactivate = (block: BlockRow) => {
  confirm({
    title: `Deactivate block "${block.prefix}"?`,
    description: 'No more numbers will be drawn from this block. Existing issued Faktur Pajak are unaffected.',
    confirmText: 'Deactivate',
    onConfirm: () => router.post(route('accounting.faktur-blocks.deactivate', block.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Faktur Pajak Number Blocks" description="DJP-allocated Nomor Seri Faktur Pajak ranges — output Faktur Pajak numbers are drawn from here sequentially, and a block can never wrap or reuse a number.">
      <template #actions>
        <PrimaryButton :href="route('accounting.faktur-blocks.create', { company_id: selectedCompanyId })">New block</PrimaryButton>
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
            <th class="py-2">Prefix</th>
            <th class="py-2">Range</th>
            <th class="py-2">Last issued</th>
            <th class="py-2">Remaining</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in blocks" :key="b.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ b.prefix }}</td>
            <td class="py-2 text-ink-700">{{ b.range_start }} – {{ b.range_end }}</td>
            <td class="py-2 text-ink-700">{{ b.last_issued ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ b.remaining }}</td>
            <td class="py-2"><StatusBadge :status="b.is_active ? 'active' : 'inactive'" /></td>
            <td class="py-2 text-right">
              <button v-if="b.is_active" type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDeactivate(b)">Deactivate</button>
            </td>
          </tr>
          <tr v-if="!blocks.length"><td colspan="6" class="py-6 text-center text-ink-600">No number blocks yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
