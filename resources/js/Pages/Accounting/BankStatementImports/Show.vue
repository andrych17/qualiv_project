<!-- ponytail: Accounting §3F staged statement lines — read-only, no matching UI yet (§3Q). -->
<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { formatCurrency, formatDate, formatDateTime } from '@/Utils/formatters'

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

const columns = [
  { key: 'line_date', label: 'Date', sortable: true },
  { key: 'description', label: 'Description', sortable: true },
  { key: 'reference', label: 'Reference' },
  { key: 'amount', label: 'Amount', align: 'right' as const, sortable: true },
  { key: 'status', label: 'Status' },
]
</script>

<template>
  <AppLayout>
    <PageHeader :title="props.import.original_filename" :description="`${props.import.bank_account_name ?? '—'} — Imported ${formatDateTime(props.import.imported_at)} — ${props.import.line_count} lines`">
      <template #actions>
        <SecondaryButton :href="route('accounting.bank-statement-imports.index')">
          &larr; Back to Imports
        </SecondaryButton>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="lines"
        empty-title="No lines in this statement import"
        empty-description="This file contained no parsable statement records."
      >
        <template #cell-line_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate(item.line_date) }}</span>
        </template>
        <template #cell-description="{ item }">
          <span class="font-medium text-ink-900">{{ item.description ?? '—' }}</span>
        </template>
        <template #cell-reference="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.reference ?? '—' }}</span>
        </template>
        <template #cell-amount="{ item }">
          <span class="font-mono text-xs font-semibold" :class="item.amount < 0 ? 'text-signal-danger' : 'text-ink-900'">
            {{ formatCurrency(item.amount, props.import.currency_code ?? undefined) }}
          </span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
