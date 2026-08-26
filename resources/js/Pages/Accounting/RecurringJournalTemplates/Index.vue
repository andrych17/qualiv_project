<!-- ponytail: Accounting §3P recurring journal templates — list, pause/resume, delete. Generation
     itself never happens from this screen (it's the nightly sweep's job); this is just CRUD
     over the template + a view of where each one stands. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface TemplateRow {
  id: number
  name: string
  recurrence_rule: string
  next_run_date: string | null
  last_run_date: string | null
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  templates: TemplateRow[]
}>()

const switchCompany = (e: Event) => router.get(route('accounting.recurring-journal-templates.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const toggleActive = (t: TemplateRow) => router.post(route('accounting.recurring-journal-templates.set-active', t.id), { is_active: !t.is_active }, { preserveScroll: true })

const { confirm } = useConfirm()

const destroy = (t: TemplateRow) => {
  confirm({
    title: 'Delete Recurring Template?',
    description: `Delete recurring template "${t.name}"? This does not affect journals already generated.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.recurring-journal-templates.destroy', t.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Recurring journals" description="A journal draft is generated automatically each time the rule comes due — always a draft, never posted, until reviewed.">
      <template #actions>
        <PrimaryButton :href="route('accounting.recurring-journal-templates.create', { company_id: selectedCompanyId })">New template</PrimaryButton>
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
            <th class="py-2">Rule</th>
            <th class="py-2">Last run</th>
            <th class="py-2">Next run</th>
            <th class="py-2">Status</th>
            <th class="py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in templates" :key="t.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2">
              <a :href="route('accounting.recurring-journal-templates.edit', t.id)" class="font-medium text-accent hover:underline">{{ t.name }}</a>
            </td>
            <td class="py-2 font-mono text-xs text-ink-600">{{ t.recurrence_rule }}</td>
            <td class="py-2 text-ink-700">{{ t.last_run_date ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ t.next_run_date ?? 'Exhausted' }}</td>
            <td class="py-2"><StatusBadge :status="t.is_active ? 'active' : 'paused'" /></td>
            <td class="py-2 text-right">
              <button type="button" class="mr-3 text-sm font-medium text-accent hover:underline" @click="toggleActive(t)">{{ t.is_active ? 'Pause' : 'Resume' }}</button>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="destroy(t)">Delete</button>
            </td>
          </tr>
          <tr v-if="!templates.length"><td colspan="6" class="py-6 text-center text-ink-600">No recurring journal templates yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
