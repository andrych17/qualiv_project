<!-- ponytail: Accounting §3I allocation rules — list, pause/resume, delete, run. Mirrors
     RecurringJournalTemplates/Index.vue's shape. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface RuleRow {
  id: number
  name: string
  source_account: string
  source_cost_center: string
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  rules: RuleRow[]
}>()

const switchCompany = (e: Event) => router.get(route('accounting.allocation-rules.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const toggleActive = (r: RuleRow) => router.post(route('accounting.allocation-rules.set-active', r.id), { is_active: !r.is_active }, { preserveScroll: true })

const { confirm } = useConfirm()

const destroy = (r: RuleRow) => {
  confirm({
    title: 'Delete Allocation Rule?',
    description: `Delete allocation rule "${r.name}"? Only possible if it has never been run.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.allocation-rules.destroy', r.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Allocation rules" description="Percentage-based cost redistribution — running a rule posts a same-account journal that moves cost-center attribution, never the account's total balance.">
      <template #actions>
        <PrimaryButton :href="route('accounting.allocation-rules.create', { company_id: selectedCompanyId })">New rule</PrimaryButton>
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
            <th class="py-2">Name</th>
            <th class="py-2">Source account</th>
            <th class="py-2">Source cost center</th>
            <th class="py-2">Status</th>
            <th class="py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rules" :key="r.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2">
              <a :href="route('accounting.allocation-rules.edit', r.id)" class="font-medium text-accent hover:underline">{{ r.name }}</a>
            </td>
            <td class="py-2 text-ink-700">{{ r.source_account }}</td>
            <td class="py-2 text-ink-700">{{ r.source_cost_center }}</td>
            <td class="py-2"><StatusBadge :status="r.is_active ? 'active' : 'paused'" /></td>
            <td class="py-2 text-right">
              <a :href="route('accounting.allocation-rules.run.show', r.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Run</a>
              <button type="button" class="mr-3 text-sm font-medium text-accent hover:underline" @click="toggleActive(r)">{{ r.is_active ? 'Pause' : 'Resume' }}</button>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="destroy(r)">Delete</button>
            </td>
          </tr>
          <tr v-if="!rules.length"><td colspan="5" class="py-6 text-center text-ink-600">No allocation rules yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
