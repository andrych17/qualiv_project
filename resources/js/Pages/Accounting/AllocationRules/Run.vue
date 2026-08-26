<!-- ponytail: Accounting §3I — run an allocation rule for a period. Preview is read-only
     (AllocationRunService::preview(), nothing posted yet); confirming posts the journal
     immediately (a deliberate single human action, unlike §3P's unattended draft-only sweep). -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDateTime } from '@/Utils/formatters'

interface PreviewLine { cost_center: string; amount: number }
interface RunRow { id: number; period_no: number; source_amount: number; journal_id: number; created_at: string }

const props = defineProps<{
  rule: { id: number; name: string; source_account: string; source_cost_center: string }
  periods: Array<{ value: number; label: string }>
  selectedPeriodId: number | null
  alreadyRun: boolean
  preview: { sourceAmount: number; lines: PreviewLine[] } | null
  runs: RunRow[]
}>()

const switchPeriod = (e: Event) => router.get(route('accounting.allocation-rules.run.show', props.rule.id), { fiscal_period_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const { confirm } = useConfirm()
const running = ref(false)
const confirmRun = () => {
  confirm({
    title: 'Post Allocation Journal?',
    description: `Post this allocation journal for ${props.rule.name}? This cannot be undone (only reversed like any other posted journal).`,
    confirmText: 'Post Journal',
    onConfirm: () => {
      running.value = true
      router.post(route('accounting.allocation-rules.run.store', props.rule.id), { fiscal_period_id: props.selectedPeriodId }, { onFinish: () => (running.value = false) })
    },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Run Allocation — ${rule.name}`" :description="`Source Pool: ${rule.source_account} / ${rule.source_cost_center}`">
      <template #actions>
        <SecondaryButton :href="route('accounting.allocation-rules.edit', rule.id)">&larr; Edit Rule</SecondaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Fiscal Period:</label>
        <select :value="selectedPeriodId" class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchPeriod">
          <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
      </div>
    </Panel>

    <Panel v-if="alreadyRun" class="mt-6 p-6 text-sm text-ink-700 bg-surface-50 border border-border rounded-lg">
      <p class="font-medium">This allocation rule has already been run for the selected period.</p>
    </Panel>

    <template v-else-if="preview">
      <Panel class="mt-6 p-4">
        <div class="text-xs uppercase font-semibold text-ink-600">Source Pool Balance (This Period)</div>
        <div class="mt-2 font-mono text-2xl font-bold text-ink-900">{{ formatCurrency(preview.sourceAmount) }}</div>
      </Panel>

      <Panel class="mt-6">
        <div class="overflow-x-auto rounded-lg border border-border">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                <th class="px-4 py-3">Target Cost Center</th>
                <th class="px-4 py-3 text-right">Allocation Amount</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border bg-surface">
              <tr v-for="(l, i) in preview.lines" :key="i" class="hover:bg-surface-50/75 transition-colors">
                <td class="px-4 py-3 font-medium text-ink-900">{{ l.cost_center }}</td>
                <td class="px-4 py-3 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(l.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>

      <div class="mt-6 flex justify-end">
        <PrimaryButton :disabled="running" @click="confirmRun">Post Allocation Journal</PrimaryButton>
      </div>
    </template>

    <Panel v-else class="mt-6 p-6 text-center text-ink-500">
      Nothing posted to the source account/cost center in this period — nothing to allocate.
    </Panel>

    <Panel title="Allocation Run History" class="mt-6">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="px-4 py-3">Period</th>
              <th class="px-4 py-3 text-right">Source Amount</th>
              <th class="px-4 py-3">Journal Entry</th>
              <th class="px-4 py-3">Executed At</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="r in runs" :key="r.id" class="hover:bg-surface-50/75 transition-colors">
              <td class="px-4 py-3 font-medium text-ink-900">Period {{ r.period_no }}</td>
              <td class="px-4 py-3 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(r.source_amount) }}</td>
              <td class="px-4 py-3">
                <Link :href="route('accounting.journals.show', r.journal_id)" class="text-xs font-semibold text-accent hover:underline">
                  Journal #{{ r.journal_id }}
                </Link>
              </td>
              <td class="px-4 py-3 font-mono text-xs text-ink-700">{{ formatDateTime(r.created_at) }}</td>
            </tr>
            <tr v-if="!runs.length">
              <td colspan="4" class="px-4 py-6 text-center text-ink-500">This rule has never been executed.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
