<!-- ponytail: Accounting §3I — run an allocation rule for a period. Preview is read-only
     (AllocationRunService::preview(), nothing posted yet); confirming posts the journal
     immediately (a deliberate single human action, unlike §3P's unattended draft-only sweep). -->
<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

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
    <PageHeader :title="`Run — ${rule.name}`" :description="`Source: ${rule.source_account} / ${rule.source_cost_center}`">
      <template #actions>
        <a :href="route('accounting.allocation-rules.edit', rule.id)" class="text-sm font-medium text-accent hover:underline">← Rule</a>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="flex flex-wrap items-center gap-4">
        <select :value="selectedPeriodId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchPeriod">
          <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
      </div>
    </Panel>

    <Panel v-if="alreadyRun" class="mt-4 p-4">
      <p class="text-sm text-ink-700">This rule has already been run for the selected period — see the history below.</p>
    </Panel>

    <template v-else-if="preview">
      <Panel class="mt-4 p-4">
        <div class="text-sm text-ink-600">Source pool this period</div>
        <div class="mt-1 text-lg font-semibold text-ink-900">{{ preview.sourceAmount.toFixed(2) }}</div>
      </Panel>

      <Panel class="mt-4">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
              <th class="px-4 py-2">Target cost center</th>
              <th class="px-4 py-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, i) in preview.lines" :key="i" class="border-b border-border">
              <td class="px-4 py-2 text-ink-900">{{ l.cost_center }}</td>
              <td class="px-4 py-2 text-right text-ink-900">{{ l.amount.toFixed(2) }}</td>
            </tr>
          </tbody>
        </table>
      </Panel>

      <div class="mt-4 flex justify-end">
        <PrimaryButton :disabled="running" @click="confirmRun">Post allocation journal</PrimaryButton>
      </div>
    </template>

    <Panel v-else class="mt-4 p-4">
      <p class="text-sm text-ink-700">Nothing posted to the source account/cost center in this period — nothing to allocate.</p>
    </Panel>

    <Panel class="mt-6">
      <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">Run history</div>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="px-4 py-2">Period</th>
            <th class="px-4 py-2 text-right">Source amount</th>
            <th class="px-4 py-2">Journal</th>
            <th class="px-4 py-2">When</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in runs" :key="r.id" class="border-b border-border">
            <td class="px-4 py-2 text-ink-900">{{ r.period_no }}</td>
            <td class="px-4 py-2 text-right text-ink-900">{{ r.source_amount.toFixed(2) }}</td>
            <td class="px-4 py-2">
              <a :href="route('accounting.journals.show', r.journal_id)" class="text-accent hover:underline">#{{ r.journal_id }}</a>
            </td>
            <td class="px-4 py-2 text-ink-700">{{ r.created_at }}</td>
          </tr>
          <tr v-if="!runs.length"><td colspan="4" class="px-4 py-6 text-center text-ink-600">This rule has never been run.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
