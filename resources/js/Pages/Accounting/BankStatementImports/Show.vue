<!-- ponytail: Accounting §3F staged statement lines — read-only, no matching UI yet (§3Q). -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface StatementLine {
  id: number
  line_date: string
  description: string | null
  amount: number
  reference: string | null
  status: string
}

const props = defineProps<{
  import: {
    id: number
    bank_account_name: string | null
    currency_code: string | null
    original_filename: string
    line_count: number
    imported_at: string
  }
  lines: StatementLine[]
}>()
</script>

<template>
  <AppLayout>
    <PageHeader :title="props.import.original_filename" :description="`${props.import.bank_account_name ?? '—'} — imported ${props.import.imported_at} — ${props.import.line_count} lines`">
      <template #actions>
        <Link :href="route('accounting.bank-statement-imports.index')" class="text-sm font-medium text-accent hover:underline">← Back to imports</Link>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Date</th>
            <th class="py-2">Description</th>
            <th class="py-2">Reference</th>
            <th class="py-2 text-right">Amount</th>
            <th class="py-2">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in lines" :key="l.id" class="border-b border-border">
            <td class="py-2 text-ink-700">{{ l.line_date }}</td>
            <td class="py-2 text-ink-900">{{ l.description ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ l.reference ?? '—' }}</td>
            <td class="py-2 text-right" :class="l.amount < 0 ? 'text-signal-danger' : 'text-ink-900'">{{ l.amount.toFixed(2) }}</td>
            <td class="py-2"><StatusBadge :status="l.status" /></td>
          </tr>
          <tr v-if="!lines.length"><td colspan="5" class="py-6 text-center text-ink-600">No lines.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
